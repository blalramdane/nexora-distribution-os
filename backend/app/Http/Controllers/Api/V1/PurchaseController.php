<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function history(Request $request)
    {
        $data=$request->validate([
            'supplier_id'=>['required','string','size:26'],
        ]);

        return response()->json(DB::table('purchase_invoices')
            ->where('organization_id',$request->user()->organization_id)
            ->where('supplier_id',$data['supplier_id'])
            ->where('status','posted')
            ->orderByDesc('invoice_date')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id','document_number','invoice_date','total','paid_amount']));
    }

    public function invoiceItems(Request $request, string $invoiceId)
    {
        $organizationId=$request->user()->organization_id;
        $invoice=DB::table('purchase_invoices')
            ->where('organization_id',$organizationId)
            ->where('id',$invoiceId)
            ->where('status','posted')
            ->firstOrFail();

        $items=DB::table('purchase_invoice_items as item')
            ->where('item.organization_id',$organizationId)
            ->where('item.purchase_invoice_id',$invoice->id)
            ->select([
                'item.id','item.product_id','item.entered_quantity','item.quantity_base',
                'item.conversion_factor_snapshot','item.unit_cost_entered',
                'item.product_name_snapshot','item.sku_snapshot',
            ])
            ->selectSub(DB::table('purchase_return_items as returned')
                ->selectRaw('COALESCE(SUM(returned.quantity_base), 0)')
                ->whereColumn('returned.organization_id','item.organization_id')
                ->whereColumn('returned.original_purchase_invoice_item_id','item.id'), 'returned_quantity_base')
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

    public function showInvoice(Request $request, string $invoiceId)
    {
        $organizationId = $request->user()->organization_id;
        $invoice = DB::table('purchase_invoices as p')
            ->leftJoin('locations as location', function ($join): void {
                $join->on('location.id', '=', 'p.location_id')
                    ->on('location.organization_id', '=', 'p.organization_id');
            })
            ->where('p.organization_id', $organizationId)
            ->where('p.id', $invoiceId)
            ->where('p.status', 'posted')
            ->select([
                'p.id', 'p.document_number', 'p.supplier_id', 'p.supplier_invoice_number', 'p.invoice_date',
                'p.total', 'p.payable_amount', 'p.paid_amount', 'p.currency', 'p.created_at', 'location.name as location_name',
            ])
            ->first();

        abort_unless($invoice, 404);

        $invoice->returned_total = (float) DB::table('purchase_returns')
            ->where('organization_id', $organizationId)
            ->where('original_purchase_invoice_id', $invoiceId)
            ->where('status', 'posted')
            ->sum('total');
        $invoice->returned_payable_total = (float) DB::table('purchase_returns')
            ->where('organization_id', $organizationId)
            ->where('original_purchase_invoice_id', $invoiceId)
            ->where('status', 'posted')
            ->sum('payable_amount');
        $invoice->remaining_payable = max(0, (float) ($invoice->payable_amount ?? $invoice->total) - (float) $invoice->paid_amount - $invoice->returned_payable_total);

        return response()->json($invoice);
    }

    public function openBalances(Request $request)
    {
        $organizationId = $request->user()->organization_id;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'string', 'size:26'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $baseQuery = static function () use ($organizationId) {
            return DB::table('purchase_invoices as p')
                ->join('suppliers as s', function ($join): void {
                    $join->on('s.id', '=', 'p.supplier_id')->on('s.organization_id', '=', 'p.organization_id');
                })
                ->where('p.organization_id', $organizationId)
                ->where('p.status', 'posted');
        };

        $openBalanceSql = "(COALESCE(p.payable_amount, p.total, 0) - COALESCE(p.paid_amount, 0) - COALESCE((SELECT SUM(COALESCE(r.payable_amount, r.total, 0)) FROM purchase_returns as r WHERE r.organization_id = p.organization_id AND r.original_purchase_invoice_id = p.id AND r.status = 'posted'), 0))";

        // The headline total always represents the whole tenant, not just the
        // currently filtered page or supplier, so a search cannot look like a
        // change in the books.
        $summary = $baseQuery()
            ->whereRaw($openBalanceSql.' > 0')
            ->selectRaw('COUNT(p.id) as invoice_count')
            ->selectRaw('COUNT(DISTINCT p.supplier_id) as supplier_count')
            ->selectRaw('COALESCE(SUM('.$openBalanceSql.'), 0) as total_payable')
            ->first();

        $query = $baseQuery()->whereRaw($openBalanceSql.' > 0');
        if (!empty($filters['supplier_id'])) {
            $query->where('p.supplier_id', $filters['supplier_id']);
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($matches) use ($like): void {
                $matches->where('s.name', 'like', $like)
                    ->orWhere('s.code', 'like', $like)
                    ->orWhere('p.document_number', 'like', $like)
                    ->orWhere('p.supplier_invoice_number', 'like', $like);
            });
        }

        $rows = $query
            ->select([
                'p.id', 'p.document_number', 'p.supplier_invoice_number', 'p.invoice_date',
                'p.total', 'p.payable_amount', 'p.paid_amount', 'p.currency', 's.id as supplier_id', 's.name as supplier_name', 's.code as supplier_code',
            ])
            ->selectSub(DB::table('purchase_returns as returned')
                ->selectRaw('COALESCE(SUM(returned.total), 0)')
                ->whereColumn('returned.organization_id', 'p.organization_id')
                ->whereColumn('returned.original_purchase_invoice_id', 'p.id')
                ->where('returned.status', 'posted'), 'returned_total')
            ->selectSub(DB::table('purchase_returns as returned_payable')
                ->selectRaw('COALESCE(SUM(COALESCE(returned_payable.payable_amount, returned_payable.total)), 0)')
                ->whereColumn('returned_payable.organization_id', 'p.organization_id')
                ->whereColumn('returned_payable.original_purchase_invoice_id', 'p.id')
                ->where('returned_payable.status', 'posted'), 'returned_payable_total')
            ->selectRaw('CASE WHEN '.$openBalanceSql.' > 0 THEN '.$openBalanceSql.' ELSE 0 END as remaining_payable')
            ->orderByDesc('remaining_payable')
            ->orderByDesc('p.invoice_date')
            ->paginate((int) ($filters['per_page'] ?? 25), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'invoices' => $rows->getCollection()->values(),
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
            ],
            'total_payable' => round((float) ($summary->total_payable ?? 0), 4),
            'open_invoice_count' => (int) ($summary->invoice_count ?? 0),
            'supplier_count' => (int) ($summary->supplier_count ?? 0),
        ]);
    }

    public function sourceTrace(Request $request)
    {
        $organizationId = $request->user()->organization_id;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $invoiceQuery = DB::table('purchase_invoices as p')
            ->join('suppliers as s', function ($join): void {
                $join->on('s.id', '=', 'p.supplier_id')->on('s.organization_id', '=', 'p.organization_id');
            })
            ->where('p.organization_id', $organizationId)
            ->where('p.status', 'posted');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $invoiceQuery->where(function ($matches) use ($like, $organizationId): void {
                $matches->where('p.document_number', 'like', $like)
                    ->orWhere('p.supplier_invoice_number', 'like', $like)
                    ->orWhere('s.name', 'like', $like)
                    ->orWhere('s.code', 'like', $like)
                    ->orWhereExists(function ($itemQuery) use ($like, $organizationId): void {
                        $itemQuery->selectRaw('1')
                            ->from('purchase_invoice_items as searched_item')
                            ->whereColumn('searched_item.organization_id', 'p.organization_id')
                            ->whereColumn('searched_item.purchase_invoice_id', 'p.id')
                            ->where('searched_item.organization_id', $organizationId)
                            ->where(function ($itemMatches) use ($like): void {
                                $itemMatches->where('searched_item.product_name_snapshot', 'like', $like)
                                    ->orWhere('searched_item.sku_snapshot', 'like', $like);
                            });
                    });
            });
        }

        $paginator = $invoiceQuery
            ->orderByDesc('p.invoice_date')
            ->orderByDesc('p.created_at')
            ->paginate((int) ($filters['per_page'] ?? 25), [
                'p.id', 'p.document_number', 'p.supplier_invoice_number', 'p.invoice_date',
                'p.total', 'p.payable_amount', 'p.paid_amount', 'p.currency', 's.id as supplier_id', 's.name as supplier_name', 's.code as supplier_code',
            ], 'page', (int) ($filters['page'] ?? 1));
        $invoices = $paginator->getCollection()->keyBy('id');
        $pagination = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        if ($invoices->isEmpty()) {
            return response()->json([
                'invoices' => [],
                'pagination' => $pagination,
                'source_attribution' => 'FIFO layers for movements posted after source tracking was enabled; older stock is explicitly unattributed.',
            ]);
        }

        $invoiceIds = $invoices->keys()->all();
        $items = DB::table('purchase_invoice_items as item')
            ->join('purchase_invoices as p', function ($join): void {
                $join->on('p.id', '=', 'item.purchase_invoice_id')->on('p.organization_id', '=', 'item.organization_id');
            })
            ->where('item.organization_id', $organizationId)
            ->whereIn('item.purchase_invoice_id', $invoiceIds)
            ->select([
                'item.id', 'item.purchase_invoice_id', 'item.product_id', 'item.product_name_snapshot',
                'item.sku_snapshot', 'item.quantity_base', 'item.unit_cost_base', 'item.line_total', 'item.payable_amount',
                'p.document_number', 'p.invoice_date',
            ])
            ->selectSub(DB::table('purchase_return_items as returned')
                ->selectRaw('COALESCE(SUM(returned.quantity_base), 0)')
                ->whereColumn('returned.organization_id', 'item.organization_id')
                ->whereColumn('returned.original_purchase_invoice_item_id', 'item.id'), 'supplier_returned_quantity_base')
            ->selectSub(DB::table('stock_source_allocations as allocations')
                ->selectRaw("COALESCE(SUM(CASE WHEN allocation_type = 'sale' THEN quantity_base WHEN allocation_type = 'sales_return' THEN -quantity_base ELSE 0 END), 0)")
                ->whereColumn('allocations.organization_id', 'item.organization_id')
                ->whereColumn('allocations.source_purchase_invoice_item_id', 'item.id')
                ->whereIn('allocations.allocation_type', ['sale', 'sales_return']), 'net_sold_quantity_base')
            ->selectSub(DB::table('stock_source_layers as layers')
                ->selectRaw('COALESCE(SUM(layers.quantity_remaining_base), 0)')
                ->whereColumn('layers.organization_id', 'item.organization_id')
                ->whereColumn('layers.source_purchase_invoice_item_id', 'item.id'), 'remaining_stock_quantity_base')
            ->orderBy('p.invoice_date')
            ->orderBy('item.created_at')
            ->get();

        // Link each source purchase line to the exact customer invoices which
        // consumed its stock layer. Returns are traced back through the
        // reversed allocation, so the invoice shows net sold quantity.
        $salesTrace = DB::table('stock_source_allocations as allocation')
            ->join('sales_invoices as sale', function ($join): void {
                $join->on('sale.id', '=', 'allocation.source_document_id')
                    ->on('sale.organization_id', '=', 'allocation.organization_id');
            })
            ->join('customers as customer', function ($join): void {
                $join->on('customer.id', '=', 'sale.customer_id')
                    ->on('customer.organization_id', '=', 'sale.organization_id');
            })
            ->join('sales_invoice_items as sale_item', function ($join): void {
                $join->on('sale_item.id', '=', 'allocation.source_document_item_id')
                    ->on('sale_item.organization_id', '=', 'allocation.organization_id');
            })
            ->leftJoin('trips as trip', function ($join): void {
                $join->on('trip.id', '=', 'sale.trip_id')
                    ->on('trip.organization_id', '=', 'sale.organization_id');
            })
            ->where('allocation.organization_id', $organizationId)
            ->where('allocation.source_document_type', 'sales_invoice')
            ->where('allocation.allocation_type', 'sale')
            ->where('sale.status', 'posted')
            ->whereIn('allocation.source_purchase_invoice_item_id', $items->pluck('id')->all())
            ->select([
                'allocation.source_purchase_invoice_item_id',
                'sale.id as sales_invoice_id',
                'sale.document_number as sales_document_number',
                'sale.invoice_date as sales_invoice_date',
                'sale.currency',
                'customer.name as customer_name',
                'customer.code as customer_code',
                'trip.trip_number',
            ])
            ->selectRaw('COALESCE(SUM(allocation.quantity_base), 0) as sourced_quantity_base')
            ->selectRaw('COALESCE(SUM((sale_item.line_total / NULLIF(sale_item.quantity_base, 0)) * allocation.quantity_base), 0) as sourced_sales_value')
            ->selectSub(DB::table('stock_source_allocations as restored')
                ->join('stock_source_allocations as original_allocation', function ($join): void {
                    $join->on('original_allocation.id', '=', 'restored.reverses_allocation_id')
                        ->on('original_allocation.organization_id', '=', 'restored.organization_id');
                })
                ->selectRaw('COALESCE(SUM(restored.quantity_base), 0)')
                ->whereColumn('original_allocation.organization_id', 'allocation.organization_id')
                ->whereColumn('original_allocation.source_purchase_invoice_item_id', 'allocation.source_purchase_invoice_item_id')
                ->whereColumn('original_allocation.source_document_id', 'allocation.source_document_id')
                ->where('original_allocation.allocation_type', 'sale')
                ->where('restored.allocation_type', 'sales_return'), 'returned_quantity_base')
            ->groupBy(
                'allocation.source_purchase_invoice_item_id',
                'sale.id',
                'sale.document_number',
                'sale.invoice_date',
                'sale.currency',
                'customer.name',
                'customer.code',
                'trip.trip_number'
            )
            ->orderByDesc('sale.invoice_date')
            ->orderByDesc('sale.document_number')
            ->get()
            ->groupBy('source_purchase_invoice_item_id');

        $remainingLocations = DB::table('stock_source_layers as layers')
            ->join('locations as location', function ($join): void {
                $join->on('location.id', '=', 'layers.location_id')->on('location.organization_id', '=', 'layers.organization_id');
            })
            ->where('layers.organization_id', $organizationId)
            ->whereIn('layers.source_purchase_invoice_item_id', $items->pluck('id')->all())
            ->whereRaw('layers.quantity_remaining_base > 0')
            ->select([
                'layers.source_purchase_invoice_item_id',
                'layers.location_id',
                'location.name as location_name',
                'location.type as location_type',
            ])
            ->selectRaw('COALESCE(SUM(layers.quantity_remaining_base), 0) as quantity_base')
            ->groupBy('layers.source_purchase_invoice_item_id', 'layers.location_id', 'location.name', 'location.type')
            ->get()
            ->groupBy('source_purchase_invoice_item_id');

        $itemsByInvoice = $items->groupBy('purchase_invoice_id');
        $result = $invoices->map(function (object $invoice) use ($itemsByInvoice, $remainingLocations, $salesTrace, $organizationId): array {
            $returnedTotal = (float) DB::table('purchase_returns')
                ->where('organization_id', $organizationId)
                ->where('original_purchase_invoice_id', $invoice->id)
                ->where('status', 'posted')
                ->sum('total');
            $returnedPayableTotal = (float) DB::table('purchase_returns')
                ->where('organization_id', $organizationId)
                ->where('original_purchase_invoice_id', $invoice->id)
                ->where('status', 'posted')
                ->sum('payable_amount');
            $lines = ($itemsByInvoice->get($invoice->id) ?? collect())->map(static function (object $item) use ($remainingLocations, $salesTrace): array {
                $byLocation = ($remainingLocations->get($item->id) ?? collect())->map(static fn (object $stock): array => [
                    'location_id' => $stock->location_id,
                    'location_name' => $stock->location_name,
                    'location_type' => $stock->location_type,
                    'quantity_base' => (float) $stock->quantity_base,
                ])->values();
                $sales = ($salesTrace->get($item->id) ?? collect())->map(static function (object $sale): array {
                    $sourcedQuantity = (float) $sale->sourced_quantity_base;
                    $returnedQuantity = (float) $sale->returned_quantity_base;
                    $netQuantity = max(0, $sourcedQuantity - $returnedQuantity);
                    $sourceSalesValue = (float) $sale->sourced_sales_value;
                    $netSalesValue = $sourcedQuantity > 0
                        ? $sourceSalesValue * ($netQuantity / $sourcedQuantity)
                        : 0;

                    return [
                        'sales_invoice_id' => $sale->sales_invoice_id,
                        'document_number' => $sale->sales_document_number,
                        'invoice_date' => $sale->sales_invoice_date,
                        'customer_name' => $sale->customer_name,
                        'customer_code' => $sale->customer_code,
                        'trip_number' => $sale->trip_number,
                        'sourced_quantity_base' => $sourcedQuantity,
                        'returned_quantity_base' => $returnedQuantity,
                        'net_quantity_base' => $netQuantity,
                        'sourced_sales_value' => round($sourceSalesValue, 4),
                        'net_sales_value' => round($netSalesValue, 4),
                        'currency' => $sale->currency,
                    ];
                })->values();
                return [
                    'purchase_invoice_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name_snapshot,
                    'sku' => $item->sku_snapshot,
                    'received_quantity_base' => (float) $item->quantity_base,
                    'supplier_returned_quantity_base' => (float) $item->supplier_returned_quantity_base,
                    'net_sold_quantity_base' => (float) $item->net_sold_quantity_base,
                    'remaining_stock_quantity_base' => (float) $item->remaining_stock_quantity_base,
                    'remaining_by_location' => $byLocation,
                    'sales' => $sales,
                    'unit_cost_base' => (float) $item->unit_cost_base,
                    'line_total' => (float) $item->line_total,
                    'payable_amount' => (float) ($item->payable_amount ?? $item->line_total),
                ];
            })->values();

            return [
                'purchase_invoice_id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'supplier_invoice_number' => $invoice->supplier_invoice_number,
                'invoice_date' => $invoice->invoice_date,
                'supplier_id' => $invoice->supplier_id,
                'supplier_name' => $invoice->supplier_name,
                'supplier_code' => $invoice->supplier_code,
                'invoice_total' => (float) $invoice->total,
                'paid_amount' => (float) $invoice->paid_amount,
                'returned_total' => $returnedTotal,
                'payable_amount' => (float) ($invoice->payable_amount ?? $invoice->total),
                'returned_payable_total' => $returnedPayableTotal,
                'remaining_payable' => max(0, (float) ($invoice->payable_amount ?? $invoice->total) - (float) $invoice->paid_amount - $returnedPayableTotal),
                'currency' => $invoice->currency,
                'items' => $lines,
            ];
        })->values();

        return response()->json([
            'invoices' => $result,
            'pagination' => $pagination,
            'source_attribution' => 'FIFO layer trace; older stock without source data remains explicitly unattributed and is not assigned to a supplier invoice.',
        ]);
    }

    public function store(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'supplier_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'invoice_date'=>['nullable','date'],
            'supplier_invoice_number'=>['nullable','string','max:128'],
            'discount'=>['nullable','numeric','min:0'],
            'discount_percent'=>['nullable','numeric','min:0','max:100'],
            'tax'=>['nullable','numeric','min:0'],
            'currency'=>['nullable','string','size:3'],
            'notes'=>['nullable','string'],
            'idempotency_key'=>['required','string','max:255'],
            'import_document_id'=>['nullable','string','size:26'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['nullable','string','size:26'],
            'items.*.new_product'=>['nullable','array'],
            'items.*.new_product.sku'=>['nullable','string','max:128'],
            'items.*.new_product.supplier_sku'=>['nullable','string','max:128'],
            'items.*.new_product.supplier_name'=>['nullable','string','max:255'],
            'items.*.new_product.name_ar'=>['nullable','string','max:255'],
            'items.*.new_product.name_en'=>['nullable','string','max:255'],
            'items.*.new_product.brand'=>['nullable','string','max:255'],
            'items.*.new_product.category_id'=>['nullable','string','size:26'],
            'items.*.new_product.base_unit_id'=>['nullable','string','size:26'],
            'items.*.new_product.default_piece_price'=>['nullable','numeric','min:0'],
            'items.*.new_product.purchase_packaging_name'=>['nullable','string','max:255'],
            'items.*.new_product.purchase_packaging_unit_id'=>['nullable','string','size:26'],
            'items.*.new_product.purchase_conversion_to_base'=>['nullable','numeric','gt:0','max:1000000000'],
            'items.*.quantity'=>['required','numeric','gt:0'],
            'items.*.conversion_factor'=>['nullable','numeric','gt:0'],
            'items.*.unit_cost'=>['required','numeric','gte:0'],
            'items.*.packaging_id'=>['nullable','string','size:26'],
            'items.*.unit_id'=>['nullable','string','size:26'],
        ]);

        foreach ($data['items'] as $index => $item) {
            $hasExistingProduct = isset($item['product_id']) && trim((string) $item['product_id']) !== '';
            $newProduct = isset($item['new_product']) && is_array($item['new_product']) ? $item['new_product'] : null;
            $hasNewProduct = $newProduct !== null;

            if ($hasExistingProduct === $hasNewProduct) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ['اختار صنفًا موجودًا أو أدخل بيانات صنف جديد، وليس الاثنين معًا.'],
                ]);
            }

            if ($hasNewProduct) {
                $errors = [];
                if (trim((string) ($newProduct['name_ar'] ?? '')) === '') {
                    $errors["items.{$index}.new_product.name_ar"] = ['اكتب اسم الصنف الجديد بالعربي.'];
                }
                if (trim((string) ($newProduct['base_unit_id'] ?? '')) === '') {
                    $errors["items.{$index}.new_product.base_unit_id"] = ['اختار الوحدة الأساسية للصنف الجديد.'];
                }
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
        }

        $data['created_by']=$request->user()->id;
        return response()->json($posting->postPurchase($request->user()->organization_id,$data),201);
    }
}
