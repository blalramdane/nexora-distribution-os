<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesController extends Controller
{
    public function openBalances(Request $request)
    {
        $organizationId = $request->user()->organization_id;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'string', 'size:26'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $baseQuery = static fn () => DB::table('sales_invoices as si')
            ->join('customers as c', function ($join): void {
                $join->on('c.id', '=', 'si.customer_id')->on('c.organization_id', '=', 'si.organization_id');
            })
            ->where('si.organization_id', $organizationId)
            ->where('si.status', 'posted');

        $openBalanceSql = "(COALESCE(si.total, 0) - COALESCE(si.paid_amount, 0) - COALESCE((SELECT SUM(r.total) FROM sales_returns as r WHERE r.organization_id = si.organization_id AND r.original_sales_invoice_id = si.id AND r.status = 'posted'), 0))";

        // Keep headline balances tenant-wide; pagination and search affect only the rows below.
        $summary = $baseQuery()->whereRaw($openBalanceSql.' > 0')
            ->selectRaw('COUNT(si.id) as invoice_count')
            ->selectRaw('COUNT(DISTINCT si.customer_id) as customer_count')
            ->selectRaw('COALESCE(SUM('.$openBalanceSql.'), 0) as total_receivable')
            ->first();

        $query = $baseQuery()->whereRaw($openBalanceSql.' > 0');
        if (!empty($filters['customer_id'])) {
            $query->where('si.customer_id', $filters['customer_id']);
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($matches) use ($like): void {
                $matches->where('c.name', 'like', $like)
                    ->orWhere('c.code', 'like', $like)
                    ->orWhere('c.phone', 'like', $like)
                    ->orWhere('si.document_number', 'like', $like);
            });
        }

        $rows = $query->select([
                'si.id', 'si.document_number', 'si.invoice_date', 'si.total', 'si.paid_amount', 'si.currency',
                'c.id as customer_id', 'c.name as customer_name', 'c.code as customer_code', 'c.phone as customer_phone',
            ])
            ->selectSub(DB::table('sales_returns as returned')
                ->selectRaw('COALESCE(SUM(returned.total), 0)')
                ->whereColumn('returned.organization_id', 'si.organization_id')
                ->whereColumn('returned.original_sales_invoice_id', 'si.id')
                ->where('returned.status', 'posted'), 'returned_total')
            ->selectRaw('CASE WHEN '.$openBalanceSql.' > 0 THEN '.$openBalanceSql.' ELSE 0 END as remaining_receivable')
            ->orderByDesc('remaining_receivable')->orderByDesc('si.invoice_date')->orderBy('c.name')
            ->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'invoices' => $rows->getCollection()->values(),
            'pagination' => [
                'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(), 'total' => $rows->total(),
                'from' => $rows->firstItem(), 'to' => $rows->lastItem(),
            ],
            'total_receivable' => round((float) ($summary->total_receivable ?? 0), 4),
            'open_invoice_count' => (int) ($summary->invoice_count ?? 0),
            'customer_count' => (int) ($summary->customer_count ?? 0),
        ]);
    }

    public function show(Request $request, string $invoiceId)
    {
        $organizationId = $request->user()->organization_id;
        $invoice = DB::table('sales_invoices as i')
            ->join('customers as c', function ($join): void {
                $join->on('c.id', '=', 'i.customer_id')->on('c.organization_id', '=', 'i.organization_id');
            })
            ->leftJoin('trips as t', function ($join): void {
                $join->on('t.id', '=', 'i.trip_id')->on('t.organization_id', '=', 'i.organization_id');
            })
            ->where('i.organization_id', $organizationId)
            ->where('i.id', $invoiceId)
            ->where('i.status', 'posted')
            ->select([
                'i.id', 'i.document_number', 'i.invoice_date', 'i.posted_at', 'i.subtotal', 'i.discount', 'i.tax',
                'i.total', 'i.paid_amount', 'i.balance_due', 'i.currency', 'i.notes', 'i.trip_id',
                'c.id as customer_id', 'c.name as customer_name', 'c.code as customer_code', 'c.phone as customer_phone',
                'c.address_text as customer_address', 't.trip_number',
            ])
            ->first();

        abort_unless($invoice, 404);

        $items = DB::table('sales_invoice_items as item')
            ->where('item.organization_id', $organizationId)
            ->where('item.sales_invoice_id', $invoice->id)
            ->orderBy('item.created_at')
            ->get([
                'item.id', 'item.product_id', 'item.product_name_snapshot', 'item.sku_snapshot',
                'item.entered_quantity', 'item.quantity_base', 'item.unit_price_entered',
                'item.unit_cost_snapshot', 'item.discount', 'item.tax', 'item.line_total',
            ]);

        return response()->json(['invoice' => $invoice, 'items' => $items]);
    }

    public function history(Request $request)
    {
        $data=$request->validate([
            'customer_id'=>['required','string','size:26'],
        ]);

        return response()->json(DB::table('sales_invoices')
            ->where('organization_id',$request->user()->organization_id)
            ->where('customer_id',$data['customer_id'])
            ->where('status','posted')
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id','document_number','invoice_date','total','paid_amount','balance_due','trip_id']));
    }

    public function invoiceItems(Request $request, string $invoiceId)
    {
        $organizationId=$request->user()->organization_id;
        $invoice=DB::table('sales_invoices')
            ->where('organization_id',$organizationId)
            ->where('id',$invoiceId)
            ->where('status','posted')
            ->firstOrFail();

        $items=DB::table('sales_invoice_items as item')
            ->where('item.organization_id',$organizationId)
            ->where('item.sales_invoice_id',$invoice->id)
            ->select([
                'item.id','item.product_id','item.entered_quantity','item.quantity_base',
                'item.conversion_factor_snapshot','item.unit_price_entered',
                'item.product_name_snapshot','item.sku_snapshot',
            ])
            ->selectSub(DB::table('sales_return_items as returned')
                ->selectRaw('COALESCE(SUM(returned.quantity_base), 0)')
                ->whereColumn('returned.organization_id','item.organization_id')
                ->whereColumn('returned.original_sales_invoice_item_id','item.id'), 'returned_quantity_base')
            ->orderBy('item.created_at')
            ->get()
            ->map(static function (object $item): object {
                $item->remaining_quantity_base=max(0,(float)$item->quantity_base-(float)$item->returned_quantity_base);
                return $item;
            })
            ->filter(static fn (object $item): bool => $item->remaining_quantity_base>0)
            ->values();

        return response()->json($items);
    }

    public function openInvoices(Request $request)
    {
        $data=$request->validate([
            'customer_id'=>['required','string','size:26'],
        ]);

        return response()->json(DB::table('sales_invoices')
            ->where('organization_id',$request->user()->organization_id)
            ->where('customer_id',$data['customer_id'])
            ->where('status','posted')
            ->where('balance_due','>',0)
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id','document_number','invoice_date','total','paid_amount','balance_due','trip_id']));
    }

    public function store(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'customer_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'trip_id'=>['nullable','string','size:26'],
            'invoice_date'=>['nullable','date'],
            'discount'=>['nullable','numeric','min:0'],
            'tax'=>['nullable','numeric','min:0'],
            'paid_amount'=>['nullable','numeric','min:0'],
            'financial_account_id'=>['nullable','string','size:26'],
            'payment_method_id'=>['nullable','string','size:26'],
            'payment_reference'=>['nullable','string','max:255'],
            'payment_date'=>['nullable','date'],
            'currency'=>['nullable','string','size:3'],
            'notes'=>['nullable','string'],
            'idempotency_key'=>['required','string','max:255'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['required','string','size:26'],
            'items.*.quantity'=>['required','numeric','gt:0'],
            'items.*.conversion_factor'=>['nullable','numeric','gt:0'],
            'items.*.unit_price'=>['required','numeric','gte:0'],
            'items.*.packaging_id'=>['nullable','string','size:26'],
            'items.*.unit_id'=>['nullable','string','size:26'],
        ]);
        $user = $request->user();
        $paidAtIssue = (float) ($data['paid_amount'] ?? 0);
        // Cash-at-issue receipts must be traceable back to their sale invoice.
        // Do not post a partial transaction into an older schema that cannot
        // persist that source reference; the surrounding workflow must migrate
        // this preview database first, after its backup has been verified.
        if ($paidAtIssue > 0 && (! Schema::hasColumn('payments', 'source_document_type') || ! Schema::hasColumn('payments', 'source_document_id'))) {
            abort(503, 'قاعدة المعاينة تحتاج تحديث مخطط المدفوعات قبل تسجيل تحصيل وقت البيع. لم يتم تسجيل الفاتورة أو التحصيل.');
        }
        // Choose payment terms on each invoice, not on the customer master.
        // Zero paid_amount means full credit; partial payment creates a receipt
        // and leaves the remainder on the customer account.
        $customer = DB::table('customers')
            ->where('organization_id', $user->organization_id)
            ->where('id', $data['customer_id'])
            ->first();
        if ($customer && $paidAtIssue < 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['paid_amount' => ['المبلغ المدفوع لا يمكن أن يكون سالبًا.']]);
        }
        if ((float) ($data['paid_amount'] ?? 0) > 0 && (empty($data['financial_account_id']) || empty($data['payment_method_id']))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'financial_account_id' => ['اختار حساب التحصيل وطريقة الدفع للمبلغ المستلم وقت الفاتورة.'],
            ]);
        }
        $isFieldRep = $user->roles()
            ->where('roles.organization_id', $user->organization_id)
            ->whereIn('roles.key', ['field_rep', 'sales_rep'])
            ->exists();
        if ($isFieldRep) {
            if (empty($data['trip_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['trip_id' => ['لا يمكن ترحيل بيع ميداني بدون رحلة مسندة.']]);
            }
            if (abs((float) ($data['paid_amount'] ?? 0) - round((float) ($data['paid_amount'] ?? 0))) > 0.000001) {
                throw \Illuminate\Validation\ValidationException::withMessages(['paid_amount' => ['المبلغ المدفوع وقت البيع الميداني لازم يكون بالجنيه الصحيح من غير كسور.']]);
            }
            $assignedCustomer = DB::table('trips as t')
                ->join('vehicles as v', function ($join): void {
                    $join->on('v.id', '=', 't.vehicle_id')->on('v.organization_id', '=', 't.organization_id');
                })
                ->join('trip_customers as tc', function ($join): void {
                    $join->on('tc.trip_id', '=', 't.id')->on('tc.organization_id', '=', 't.organization_id');
                })
                ->where('t.organization_id', $user->organization_id)
                ->where('t.id', $data['trip_id'])
                ->where('t.rep_user_id', $user->id)
                ->where('tc.customer_id', $data['customer_id'])
                ->where('v.location_id', $data['location_id'])
                ->whereNotIn('t.status', ['completed', 'cancelled'])
                ->exists();
            if (! $assignedCustomer) {
                abort(404, 'العميل أو الرحلة غير مسندين لحساب المندوب.');
            }
        }
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postSale($request->user()->organization_id,$data),201);
    }
}
