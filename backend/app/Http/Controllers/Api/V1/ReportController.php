<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $organizationId = (string) $request->user()->organization_id;
        $organization = DB::table('organizations')->where('id', $organizationId)->first(['default_currency', 'timezone']);
        abort_unless($organization, 403, 'Organization context is unavailable.');

        $timezone = $organization->timezone ?: 'Africa/Cairo';
        $today = CarbonImmutable::now($timezone)->toDateString();
        $dateTo = $filters['date_to'] ?? $today;
        $dateFrom = $filters['date_from'] ?? CarbonImmutable::parse($dateTo, $timezone)->startOfMonth()->toDateString();

        if ($dateTo < $dateFrom) {
            throw ValidationException::withMessages([
                'date_to' => ['تاريخ النهاية يجب أن يكون في نفس يوم البداية أو بعده.'],
            ]);
        }

        if (CarbonImmutable::parse($dateFrom, $timezone)->diffInDays(CarbonImmutable::parse($dateTo, $timezone)) > 366) {
            throw ValidationException::withMessages([
                'date_to' => ['أقصى مدة للتقرير 367 يومًا؛ قسّم المدة إلى فترات أصغر.'],
            ]);
        }

        $salesByDay = $this->dailySumDocuments('sales_invoices', 'invoice_date', $organizationId, $dateFrom, $dateTo);
        $purchasesByDay = $this->dailySumDocuments('purchase_invoices', 'invoice_date', $organizationId, $dateFrom, $dateTo);
        $salesReturnsByDay = $this->dailySumDocuments('sales_returns', 'return_date', $organizationId, $dateFrom, $dateTo);
        $purchaseReturnsByDay = $this->dailySumDocuments('purchase_returns', 'return_date', $organizationId, $dateFrom, $dateTo);
        $collectionsByDay = $this->dailySum('payments', 'payment_date', $organizationId, $dateFrom, $dateTo, ['direction' => 'inbound']);
        $supplierPaymentsByDay = $this->dailySum('payments', 'payment_date', $organizationId, $dateFrom, $dateTo, ['direction' => 'outbound']);
        $expensesByDay = $this->dailySum('expenses', 'expense_date', $organizationId, $dateFrom, $dateTo);
        $cogsByDay = $this->dailySalesCost($organizationId, $dateFrom, $dateTo);
        $returnedCogsByDay = $this->dailyReturnedSalesCost($organizationId, $dateFrom, $dateTo);

        $salesTotal = $this->sumDocuments('sales_invoices', 'invoice_date', $organizationId, $dateFrom, $dateTo);
        $purchasesTotal = $this->sumDocuments('purchase_invoices', 'invoice_date', $organizationId, $dateFrom, $dateTo);
        $salesReturnsTotal = $this->sumDocuments('sales_returns', 'return_date', $organizationId, $dateFrom, $dateTo);
        $purchaseReturnsTotal = $this->sumDocuments('purchase_returns', 'return_date', $organizationId, $dateFrom, $dateTo);
        $collections = $this->sumPeriod('payments', 'payment_date', $organizationId, $dateFrom, $dateTo, ['direction' => 'inbound']);
        $supplierPayments = $this->sumPeriod('payments', 'payment_date', $organizationId, $dateFrom, $dateTo, ['direction' => 'outbound']);
        $expenses = $this->sumPeriod('expenses', 'expense_date', $organizationId, $dateFrom, $dateTo);
        $cogs = $this->sumValues($cogsByDay);
        $returnedCogs = $this->sumValues($returnedCogsByDay);
        $netSales = $salesTotal - $salesReturnsTotal;
        $netPurchases = $purchasesTotal - $purchaseReturnsTotal;
        // A return from an older period can recover more cost than the sales
        // made in this period. Preserve that credit instead of silently capping
        // net COGS at zero.
        $netCogs = $cogs - $returnedCogs;

        $inventory = DB::table('stock_balances')
            ->where('organization_id', $organizationId);

        $activeTrips = DB::table('trips')
            ->where('organization_id', $organizationId)
            ->whereBetween('trip_date', [$dateFrom, $dateTo])
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        return response()->json([
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'currency' => strtoupper((string) ($organization->default_currency ?: 'EGP')),
            'summary' => [
                'sales_total' => round($salesTotal, 4),
                'sales_returns_total' => round($salesReturnsTotal, 4),
                'net_sales' => round($netSales, 4),
                'purchases_total' => round($purchasesTotal, 4),
                'purchase_returns_total' => round($purchaseReturnsTotal, 4),
                'net_purchases' => round($netPurchases, 4),
                'collections' => round($collections, 4),
                'supplier_payments' => round($supplierPayments, 4),
                'expenses' => round($expenses, 4),
                'cost_of_goods_sold' => round($cogs, 4),
                'returned_cost' => round($returnedCogs, 4),
                'net_cost_of_goods_sold' => round($netCogs, 4),
                'gross_profit' => round($netSales - $netCogs, 4),
                'net_profit' => round($netSales - $netCogs - $expenses, 4),
                'receivables_current' => round((float) DB::table('customer_balance_summaries')->where('organization_id', $organizationId)->sum('outstanding'), 4),
                'payables_current' => round((float) DB::table('supplier_balance_summaries')->where('organization_id', $organizationId)->sum('outstanding'), 4),
                'inventory_units_current' => round((float) (clone $inventory)->sum('quantity_base'), 6),
                'inventory_value_current' => round((float) (clone $inventory)->selectRaw('COALESCE(SUM(quantity_base * average_cost),0) as total')->value('total'), 4),
                'active_trips_in_period' => (int) $activeTrips,
            ],
            'daily' => $this->combineDaily([
                'sales' => $salesByDay,
                'sales_returns' => $salesReturnsByDay,
                'purchases' => $purchasesByDay,
                'purchase_returns' => $purchaseReturnsByDay,
                'collections' => $collectionsByDay,
                'supplier_payments' => $supplierPaymentsByDay,
                'expenses' => $expensesByDay,
                'cost_of_goods_sold' => $cogsByDay,
                'returned_cost' => $returnedCogsByDay,
            ]),
            'top_products' => $this->topProducts($organizationId, $dateFrom, $dateTo),
            'notes' => [
                'balances_and_inventory_are_current_snapshots' => true,
                'trip_cash_and_stock_variances_included_in_profit' => false,
                'profit_is_operational_not_a_final_tax_statement' => true,
            ],
        ]);
    }

    /** @return array<string, float> */
    private function dailySum(string $table, string $dateColumn, string $organizationId, string $from, string $to, array $equals = []): array
    {
        $query = DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('status', 'posted')
            ->whereBetween($dateColumn, [$from, $to]);

        foreach ($equals as $column => $value) {
            $query->where($column, $value);
        }

        return $query->select($dateColumn . ' as report_date')
            ->selectRaw('COALESCE(SUM(amount), 0) as report_total')
            ->groupBy($dateColumn)
            ->orderBy($dateColumn)
            ->pluck('report_total', 'report_date')
            ->map(static fn ($value): float => (float) $value)
            ->all();
    }

    private function sumPeriod(string $table, string $dateColumn, string $organizationId, string $from, string $to, array $equals = []): float
    {
        $query = DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('status', 'posted')
            ->whereBetween($dateColumn, [$from, $to]);

        foreach ($equals as $column => $value) {
            $query->where($column, $value);
        }

        return (float) $query->sum('amount');
    }

    /** @return array<string, float> */
    private function dailySumDocuments(string $table, string $dateColumn, string $organizationId, string $from, string $to): array
    {
        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('status', 'posted')
            ->whereBetween($dateColumn, [$from, $to])
            ->select($dateColumn . ' as report_date')
            ->selectRaw('COALESCE(SUM(total), 0) as report_total')
            ->groupBy($dateColumn)
            ->orderBy($dateColumn)
            ->pluck('report_total', 'report_date')
            ->map(static fn ($value): float => (float) $value)
            ->all();
    }

    private function sumDocuments(string $table, string $dateColumn, string $organizationId, string $from, string $to): float
    {
        return (float) DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('status', 'posted')
            ->whereBetween($dateColumn, [$from, $to])
            ->sum('total');
    }

    /** @return array<string, float> */
    private function dailySalesCost(string $organizationId, string $from, string $to): array
    {
        return DB::table('sales_invoices as invoice')
            ->join('sales_invoice_items as item', function ($join): void {
                $join->on('item.sales_invoice_id', '=', 'invoice.id')
                    ->on('item.organization_id', '=', 'invoice.organization_id');
            })
            ->where('invoice.organization_id', $organizationId)
            ->where('invoice.status', 'posted')
            ->whereBetween('invoice.invoice_date', [$from, $to])
            ->select('invoice.invoice_date as report_date')
            ->selectRaw('COALESCE(SUM(item.unit_cost_snapshot * item.quantity_base), 0) as report_total')
            ->groupBy('invoice.invoice_date')
            ->orderBy('invoice.invoice_date')
            ->pluck('report_total', 'report_date')
            ->map(static fn ($value): float => (float) $value)
            ->all();
    }

    /** @return array<string, float> */
    private function dailyReturnedSalesCost(string $organizationId, string $from, string $to): array
    {
        return DB::table('sales_returns as return_doc')
            ->join('sales_return_items as item', function ($join): void {
                $join->on('item.sales_return_id', '=', 'return_doc.id')
                    ->on('item.organization_id', '=', 'return_doc.organization_id');
            })
            ->where('return_doc.organization_id', $organizationId)
            ->where('return_doc.status', 'posted')
            ->whereBetween('return_doc.return_date', [$from, $to])
            ->select('return_doc.return_date as report_date')
            ->selectRaw('COALESCE(SUM(item.unit_cost_snapshot * item.quantity_base), 0) as report_total')
            ->groupBy('return_doc.return_date')
            ->orderBy('return_doc.return_date')
            ->pluck('report_total', 'report_date')
            ->map(static fn ($value): float => (float) $value)
            ->all();
    }

    private function sumValues(array $values): float
    {
        return array_sum(array_map('floatval', $values));
    }

    private function combineDaily(array $series): array
    {
        $dates = [];
        foreach ($series as $values) {
            foreach (array_keys($values) as $date) {
                $dates[$date] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return array_map(static function (string $date) use ($series): array {
            $row = ['date' => $date];
            foreach ($series as $key => $values) {
                $row[$key] = round((float) ($values[$date] ?? 0), 4);
            }
            $row['net_sales'] = round($row['sales'] - $row['sales_returns'], 4);
            $row['net_purchases'] = round($row['purchases'] - $row['purchase_returns'], 4);
            $row['net_cost_of_goods_sold'] = round($row['cost_of_goods_sold'] - $row['returned_cost'], 4);
            $row['net_profit_before_variance'] = round($row['net_sales'] - $row['net_cost_of_goods_sold'] - $row['expenses'], 4);
            return $row;
        }, $dates);
    }

    private function topProducts(string $organizationId, string $from, string $to): array
    {
        return DB::table('sales_invoices as invoice')
            ->join('sales_invoice_items as item', function ($join): void {
                $join->on('item.sales_invoice_id', '=', 'invoice.id')
                    ->on('item.organization_id', '=', 'invoice.organization_id');
            })
            ->where('invoice.organization_id', $organizationId)
            ->where('invoice.status', 'posted')
            ->whereBetween('invoice.invoice_date', [$from, $to])
            ->select([
                'item.product_id',
                'item.product_name_snapshot as product_name',
                'item.sku_snapshot as sku',
            ])
            ->selectRaw('COALESCE(SUM(item.quantity_base), 0) as quantity_base')
            ->selectRaw('COALESCE(SUM(item.line_total), 0) as sales_total')
            ->selectRaw('COALESCE(SUM(item.unit_cost_snapshot * item.quantity_base), 0) as cost_of_goods_sold')
            ->groupBy('item.product_id', 'item.product_name_snapshot', 'item.sku_snapshot')
            ->orderByDesc('sales_total')
            ->limit(10)
            ->get()
            ->map(static function ($item): array {
                $item->quantity_base = (float) $item->quantity_base;
                $item->sales_total = (float) $item->sales_total;
                $item->cost_of_goods_sold = (float) $item->cost_of_goods_sold;

                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'sku' => $item->sku,
                    'quantity_base' => round($item->quantity_base, 6),
                    'sales_total' => round($item->sales_total, 4),
                    'cost_of_goods_sold' => round($item->cost_of_goods_sold, 4),
                    'gross_profit' => round($item->sales_total - $item->cost_of_goods_sold, 4),
                ];
            })
            ->all();
    }
}
