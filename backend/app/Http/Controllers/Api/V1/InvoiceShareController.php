<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceShareController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'invoice_type' => ['required', 'in:sales,purchase,supplier_payment'],
            'invoice_id' => ['required', 'string', 'size:26'],
        ]);
        $this->authorizeInvoiceType($request, $data['invoice_type']);

        if ($data['invoice_type'] === 'supplier_payment') {
            $invoice = DB::table('payments')->where('organization_id', $request->user()->organization_id)
                ->where('id', $data['invoice_id'])->where('party_type', 'supplier')->where('direction', 'outbound')->where('status', 'posted')->first(['id']);
        } else {
            $table = $data['invoice_type'] === 'sales' ? 'sales_invoices' : 'purchase_invoices';
            $invoice = DB::table($table)->where('organization_id', $request->user()->organization_id)
                ->where('id', $data['invoice_id'])->where('status', 'posted')->first(['id']);
        }
        abort_unless($invoice, 404);

        return response()->json(DB::table('invoice_share_links')
            ->where('organization_id', $request->user()->organization_id)
            ->where('invoice_type', $data['invoice_type'])
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'invoice_type', 'invoice_id', 'expires_at', 'revoked_at', 'access_count', 'last_accessed_at', 'created_at']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'invoice_type' => ['required', 'in:sales,purchase,supplier_payment'],
            'invoice_id' => ['required', 'string', 'size:26'],
            'expires_in_days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $organizationId = (string) $request->user()->organization_id;
        $type = $data['invoice_type'];
        $this->authorizeInvoiceType($request, $type);
        if ($type === 'supplier_payment') {
            $invoice = DB::table('payments')->where('organization_id', $organizationId)
                ->where('id', $data['invoice_id'])->where('party_type', 'supplier')->where('direction', 'outbound')->where('status', 'posted')->first(['id']);
        } else {
            $table = $type === 'sales' ? 'sales_invoices' : 'purchase_invoices';
            $invoice = DB::table($table)->where('organization_id', $organizationId)
                ->where('id', $data['invoice_id'])->where('status', 'posted')->first(['id']);
        }

        abort_unless($invoice, 404);

        $plainToken = Str::random(64);
        $now = now();
        $linkId = (string) Str::ulid();

        DB::transaction(function () use ($organizationId, $type, $invoice, $request, $plainToken, $now, $linkId, $data): void {
            DB::table('invoice_share_links')->insert([
                'id' => $linkId,
                'organization_id' => $organizationId,
                'created_by' => $request->user()->id,
                'invoice_type' => $type,
                'invoice_id' => $invoice->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => $now->copy()->addDays((int) $data['expires_in_days']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('invoice_share_access_logs')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'share_link_id' => $linkId,
                'action' => 'created',
                'created_at' => $now,
            ]);
        });

        return response()->json([
            'id' => $linkId,
            'invoice_type' => $type,
            'invoice_id' => $invoice->id,
            'path' => '/shared/invoice/'.$plainToken,
            'expires_at' => $now->copy()->addDays((int) $data['expires_in_days'])->toIso8601String(),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $shareId)
    {
        $organizationId = (string) $request->user()->organization_id;
        $share = DB::table('invoice_share_links')
            ->where('organization_id', $organizationId)
            ->where('id', $shareId)
            ->first();

        abort_unless($share, 404);
        $this->authorizeInvoiceType($request, $share->invoice_type);

        if ($share->revoked_at === null) {
            $now = now();
            DB::transaction(function () use ($share, $organizationId, $now): void {
                DB::table('invoice_share_links')->where('organization_id', $organizationId)->where('id', $share->id)->update([
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('invoice_share_access_logs')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $organizationId,
                    'share_link_id' => $share->id,
                    'action' => 'revoked',
                    'created_at' => $now,
                ]);
            });
        }

        return response()->json(['id' => $shareId, 'revoked' => true]);
    }

    public function showPublic(Request $request, string $token)
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            abort(404);
        }

        $tokenHash = hash('sha256', $token);
        $share = DB::table('invoice_share_links')
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        abort_unless($share, 404);

        if ($share->invoice_type === 'supplier_payment') {
            $payment = DB::table('payments as payment')
                ->join('suppliers as party', function ($join): void {
                    $join->on('party.id', '=', 'payment.party_id')->on('party.organization_id', '=', 'payment.organization_id');
                })
                ->leftJoin('payment_methods as method', function ($join): void {
                    $join->on('method.id', '=', 'payment.payment_method_id');
                })
                ->leftJoin('trip_supplier_settlements as trip_supplier_settlement', function ($join): void {
                    $join->on('trip_supplier_settlement.id', '=', 'payment.trip_supplier_settlement_id')
                        ->on('trip_supplier_settlement.organization_id', '=', 'payment.organization_id');
                })
                ->leftJoin('trips as trip', function ($join): void {
                    $join->on('trip.id', '=', 'trip_supplier_settlement.trip_id')
                        ->on('trip.organization_id', '=', 'trip_supplier_settlement.organization_id');
                })
                ->where('payment.organization_id', $share->organization_id)
                ->where('payment.id', $share->invoice_id)
                ->where('payment.party_type', 'supplier')
                ->where('payment.direction', 'outbound')
                ->where('payment.status', 'posted')
                ->select([
                    'payment.id', 'payment.amount', 'payment.currency', 'payment.payment_date', 'payment.reference',
                    'payment.created_at', 'party.name as party_name', 'method.name_ar as payment_method_name', 'trip.trip_number',
                ])->first();

            abort_unless($payment, 404);
            DB::transaction(function () use ($share, $request): void {
                DB::table('invoice_share_links')->where('organization_id', $share->organization_id)->where('id', $share->id)->increment('access_count', 1, ['last_accessed_at' => now()]);
                DB::table('invoice_share_access_logs')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $share->organization_id,
                    'share_link_id' => $share->id, 'action' => 'viewed',
                    'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null,
                    'user_agent_hash' => $request->userAgent() ? hash_hmac('sha256', $request->userAgent(), (string) config('app.key')) : null,
                    'created_at' => now(),
                ]);
            });

            return response()->json(['document_type' => 'supplier_payment', 'payment_receipt' => [
                'document_number' => 'SP-'.strtoupper(substr((string) $payment->id, 0, 8)),
                'payment_date' => $payment->payment_date,
                'party_name' => $payment->party_name,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'reference' => $payment->reference,
                'payment_method_name' => $payment->payment_method_name,
                'trip_number' => $payment->trip_number,
            ]])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
        }

        $table = $share->invoice_type === 'sales' ? 'sales_invoices' : 'purchase_invoices';
        $query = DB::table($table.' as i')->where('i.organization_id', $share->organization_id)
            ->where('i.id', $share->invoice_id)->where('i.status', 'posted');

        if ($share->invoice_type === 'sales') {
            $invoice = $query->join('customers as party', function ($join): void {
                $join->on('party.id', '=', 'i.customer_id')->on('party.organization_id', '=', 'i.organization_id');
            })->select([
                'i.id', 'i.document_number', 'i.invoice_date', 'i.subtotal', 'i.discount', 'i.tax', 'i.total',
                'i.currency', 'party.name as party_name',
            ])->first();
            $itemsTable = 'sales_invoice_items';
            $foreignKey = 'sales_invoice_id';
            $quantityField = 'entered_quantity';
            $priceField = 'unit_price_entered';
        } else {
            $invoice = $query->join('suppliers as party', function ($join): void {
                $join->on('party.id', '=', 'i.supplier_id')->on('party.organization_id', '=', 'i.organization_id');
            })->select([
                'i.id', 'i.document_number', 'i.invoice_date', 'i.subtotal', 'i.discount', 'i.tax', 'i.total',
                'i.currency', 'party.name as party_name',
            ])->first();
            $itemsTable = 'purchase_invoice_items';
            $foreignKey = 'purchase_invoice_id';
            $quantityField = 'entered_quantity';
            $priceField = 'unit_cost_entered';
        }

        abort_unless($invoice, 404);

        $items = DB::table($itemsTable)->where('organization_id', $share->organization_id)
            ->where($foreignKey, $invoice->id)->orderBy('created_at')
            ->get(['product_name_snapshot', 'sku_snapshot', $quantityField.' as quantity', $priceField.' as unit_price', 'line_total']);

        DB::transaction(function () use ($share, $request): void {
            DB::table('invoice_share_links')->where('organization_id', $share->organization_id)->where('id', $share->id)->increment('access_count', 1, ['last_accessed_at' => now()]);
            DB::table('invoice_share_access_logs')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $share->organization_id,
                'share_link_id' => $share->id,
                'action' => 'viewed',
                'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null,
                'user_agent_hash' => $request->userAgent() ? hash_hmac('sha256', $request->userAgent(), (string) config('app.key')) : null,
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'invoice' => [
                'document_number' => $invoice->document_number,
                'invoice_date' => $invoice->invoice_date,
                'party_name' => $invoice->party_name,
                'subtotal' => $invoice->subtotal,
                'discount' => $invoice->discount,
                'tax' => $invoice->tax,
                'total' => $invoice->total,
                'currency' => $invoice->currency,
                'items' => $items,
            ],
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    private function authorizeInvoiceType(Request $request, string $type): void
    {
        $keys = match ($type) {
            'sales' => ['sales.view', 'sales.post'],
            'purchase' => ['purchases.view', 'purchases.post'],
            'supplier_payment' => ['payments.record'],
            default => [],
        };
        $user = $request->user();
        $allowed = $user->roles()
            ->where('roles.organization_id', $user->organization_id)
            ->whereHas('permissions', static fn ($query) => $query->whereIn('key', $keys))
            ->exists();

        abort_unless($allowed, 403, 'You do not have permission to share this invoice.');
    }
}
