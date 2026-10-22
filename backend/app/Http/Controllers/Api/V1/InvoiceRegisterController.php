<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceRegisterController extends Controller
{
    /** Unified, read-only register for posted customer sales and supplier purchases. */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'in:all,sales,purchases'],
            'q' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:100'],
        ]);
        $organizationId = (string) $request->user()->organization_id;

        $sales = DB::table('sales_invoices as invoice')
            ->join('customers as party', function ($join): void {
                $join->on('party.id', '=', 'invoice.customer_id')
                    ->on('party.organization_id', '=', 'invoice.organization_id');
            })
            ->where('invoice.organization_id', $organizationId)
            ->where('invoice.status', 'posted')
            ->selectRaw("'sales' as document_type, invoice.id, invoice.document_number, NULL as external_number, invoice.invoice_date, party.id as party_id, 'customer' as party_type, party.name as party_name, party.code as party_code, invoice.total, invoice.paid_amount, invoice.currency, invoice.created_at")
            ->selectSub(DB::table('sales_returns as returned')
                ->selectRaw('COALESCE(SUM(returned.total), 0)')
                ->whereColumn('returned.organization_id', 'invoice.organization_id')
                ->whereColumn('returned.original_sales_invoice_id', 'invoice.id')
                ->where('returned.status', 'posted'), 'returns_total')
            ->selectSub(DB::table('sales_returns as returned_due')
                ->selectRaw('COALESCE(SUM(returned_due.total), 0)')
                ->whereColumn('returned_due.organization_id', 'invoice.organization_id')
                ->whereColumn('returned_due.original_sales_invoice_id', 'invoice.id')
                ->where('returned_due.status', 'posted'), 'returns_payable_total')
            ->selectRaw("CASE WHEN (COALESCE(invoice.total, 0) - COALESCE(invoice.paid_amount, 0) - COALESCE((SELECT SUM(r.total) FROM sales_returns as r WHERE r.organization_id = invoice.organization_id AND r.original_sales_invoice_id = invoice.id AND r.status = 'posted'), 0)) > 0 THEN (COALESCE(invoice.total, 0) - COALESCE(invoice.paid_amount, 0) - COALESCE((SELECT SUM(r.total) FROM sales_returns as r WHERE r.organization_id = invoice.organization_id AND r.original_sales_invoice_id = invoice.id AND r.status = 'posted'), 0)) ELSE 0 END as remaining_amount");

        $purchases = DB::table('purchase_invoices as invoice')
            ->join('suppliers as party', function ($join): void {
                $join->on('party.id', '=', 'invoice.supplier_id')
                    ->on('party.organization_id', '=', 'invoice.organization_id');
            })
            ->where('invoice.organization_id', $organizationId)
            ->where('invoice.status', 'posted')
            ->selectRaw("'purchases' as document_type, invoice.id, invoice.document_number, invoice.supplier_invoice_number as external_number, invoice.invoice_date, party.id as party_id, 'supplier' as party_type, party.name as party_name, party.code as party_code, invoice.total, invoice.paid_amount, invoice.currency, invoice.created_at")
            ->selectSub(DB::table('purchase_returns as returned')
                ->selectRaw('COALESCE(SUM(returned.total), 0)')
                ->whereColumn('returned.organization_id', 'invoice.organization_id')
                ->whereColumn('returned.original_purchase_invoice_id', 'invoice.id')
                ->where('returned.status', 'posted'), 'returns_total')
            ->selectSub(DB::table('purchase_returns as returned_due')
                ->selectRaw('COALESCE(SUM(COALESCE(returned_due.payable_amount, returned_due.total)), 0)')
                ->whereColumn('returned_due.organization_id', 'invoice.organization_id')
                ->whereColumn('returned_due.original_purchase_invoice_id', 'invoice.id')
                ->where('returned_due.status', 'posted'), 'returns_payable_total')
            ->selectRaw("CASE WHEN (COALESCE(invoice.payable_amount, invoice.total, 0) - COALESCE(invoice.paid_amount, 0) - COALESCE((SELECT SUM(COALESCE(r.payable_amount, r.total, 0)) FROM purchase_returns as r WHERE r.organization_id = invoice.organization_id AND r.original_purchase_invoice_id = invoice.id AND r.status = 'posted'), 0)) > 0 THEN (COALESCE(invoice.payable_amount, invoice.total, 0) - COALESCE(invoice.paid_amount, 0) - COALESCE((SELECT SUM(COALESCE(r.payable_amount, r.total, 0)) FROM purchase_returns as r WHERE r.organization_id = invoice.organization_id AND r.original_purchase_invoice_id = invoice.id AND r.status = 'posted'), 0)) ELSE 0 END as remaining_amount");

        $union = ($filters['type'] ?? 'all') === 'sales'
            ? $sales
            : ((($filters['type'] ?? 'all') === 'purchases') ? $purchases : $sales->unionAll($purchases));
        $query = DB::query()->fromSub($union, 'invoice_register');
        if (!empty($filters['date_from'])) {
            $query->where('invoice_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('invoice_date', '<=', $filters['date_to']);
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($matches) use ($like): void {
                $matches->where('document_number', 'like', $like)
                    ->orWhere('external_number', 'like', $like)
                    ->orWhere('party_name', 'like', $like)
                    ->orWhere('party_code', 'like', $like);
            });
        }

        $summaryRows = (clone $query)->select('currency')
            ->selectRaw("COUNT(*) as invoice_count, COALESCE(SUM(CASE WHEN document_type = 'sales' THEN total ELSE 0 END), 0) as sales_total, COALESCE(SUM(CASE WHEN document_type = 'purchases' THEN total ELSE 0 END), 0) as purchases_total, COALESCE(SUM(CASE WHEN document_type = 'sales' THEN remaining_amount ELSE 0 END), 0) as customer_receivables_total, COALESCE(SUM(CASE WHEN document_type = 'purchases' THEN remaining_amount ELSE 0 END), 0) as supplier_payables_total")
            ->groupBy('currency')->orderBy('currency')->get()
            ->map(static fn ($row) => [
                'currency' => (string) $row->currency,
                'invoice_count' => (int) $row->invoice_count,
                'sales_total' => round((float) $row->sales_total, 4),
                'purchases_total' => round((float) $row->purchases_total, 4),
                'customer_receivables_total' => round((float) $row->customer_receivables_total, 4),
                'supplier_payables_total' => round((float) $row->supplier_payables_total, 4),
            ])->values();
        $invoiceCount = (int) $summaryRows->sum('invoice_count');
        $rows = $query->orderByDesc('invoice_date')->orderByDesc('created_at')->orderBy('document_number')
            ->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'invoices' => $rows->getCollection()->values(),
            'pagination' => [
                'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(), 'total' => $rows->total(),
                'from' => $rows->firstItem(), 'to' => $rows->lastItem(),
            ],
            'summary' => [
                'invoice_count' => $invoiceCount,
                'by_currency' => $summaryRows,
            ],
            'filters' => [
                'type' => $filters['type'] ?? 'all', 'q' => $search,
                'date_from' => $filters['date_from'] ?? null, 'date_to' => $filters['date_to'] ?? null,
            ],
        ]);
    }
}
