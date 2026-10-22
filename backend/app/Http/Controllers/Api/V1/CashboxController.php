<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashboxController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'financial_account_id' => ['nullable', 'string', 'size:26'],
            'direction' => ['nullable', 'in:inbound,outbound,transfer'],
            'beneficiary_user_id' => ['nullable', 'string', 'size:26'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $org = (string) $request->user()->organization_id;
        $currency = strtoupper((string) (DB::table('organizations')->where('id', $org)->value('default_currency') ?: 'EGP'));
        $timezone = (string) (DB::table('organizations')->where('id', $org)->value('timezone') ?: 'Africa/Cairo');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $from = $data['date_from'] ?? CarbonImmutable::parse($today, $timezone)->startOfMonth()->toDateString();
        $to = $data['date_to'] ?? $today;
        if ($to < $from) throw ValidationException::withMessages(['date_to' => ['تاريخ نهاية التقرير لا يمكن أن يسبق بدايته.']]);

        $accounts = DB::table('financial_accounts')->where('organization_id', $org)->where('active', true)->orderBy('name')->get();
        if (! empty($data['financial_account_id']) && ! $accounts->contains('id', $data['financial_account_id'])) abort(404);

        $balances = $accounts->map(function ($account) use ($org): array {
            $ledgerCode = 'FA-'.$account->code;
            $ledger = DB::table('ledger_accounts')->where('organization_id', $org)->where('code', $ledgerCode)->first(['id']);
            $hasPostedOpening = DB::table('cashbox_transactions')->where('organization_id', $org)
                ->where('transaction_type', 'opening_balance')->where('status', 'posted')->where('financial_account_id', $account->id)->exists();
            $ledgerBalance = $ledger
                ? (float) DB::table('ledger_entries')->where('organization_id', $org)->where('account_id', $ledger->id)->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')->value('balance')
                : 0.0;
            $opening = $hasPostedOpening ? 0.0 : (float) $account->opening_balance;
            return [
                'id' => $account->id, 'code' => $account->code, 'name' => $account->name,
                'type' => $account->type, 'currency' => $account->currency,
                'opening_balance' => $opening, 'movement_balance' => $ledgerBalance,
                'balance' => $opening + $ledgerBalance,
            ];
        });

        $generalCashAccount = DB::table('ledger_accounts')->where('organization_id', $org)->where('code', '1000')->first(['id']);
        $generalCashBalance = $generalCashAccount
            ? (float) DB::table('ledger_entries')->where('organization_id', $org)->where('account_id', $generalCashAccount->id)->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')->value('balance')
            : 0.0;

        $movements = collect();
        $push = static function (array $row) use (&$movements): void { $movements->push($row); };

        $cashLedgerAccountId = DB::table('ledger_accounts')->where('organization_id', $org)->where('code', '1000')->value('id');
        $sales = DB::table('sales_invoices as si')
            ->join('customers as c', function ($join): void { $join->on('c.id', '=', 'si.customer_id')->on('c.organization_id', '=', 'si.organization_id'); })
            ->leftJoin('trips as t', function ($join): void { $join->on('t.id', '=', 'si.trip_id')->on('t.organization_id', '=', 'si.organization_id'); })
            ->where('si.organization_id', $org)->where('si.status', 'posted')->whereBetween('si.invoice_date', [$from, $to])
            ->select(['si.id', 'si.document_number', 'si.invoice_date', 'si.currency', 'si.trip_id', 'c.name as party_name', 't.trip_number'])
            ->selectSub(DB::table('payments as at_sale')->selectRaw('COALESCE(SUM(at_sale.amount),0)')
                ->where('at_sale.organization_id', $org)->where('at_sale.source_document_type', 'sale_at_issue')
                ->whereColumn('at_sale.source_document_id', 'si.id')->where('at_sale.status', 'posted'), 'cash_at_sale_receipt')
            ->selectSub(DB::table('payments as at_sale')->select('at_sale.financial_account_id')
                ->where('at_sale.organization_id', $org)->where('at_sale.source_document_type', 'sale_at_issue')
                ->whereColumn('at_sale.source_document_id', 'si.id')->where('at_sale.status', 'posted')->limit(1), 'cash_at_sale_account_id')
            ->selectSub(DB::table('ledger_entries')->selectRaw('COALESCE(SUM(debit - credit),0)')
                ->whereColumn('ledger_entries.source_document_id', 'si.id')->where('ledger_entries.source_document_type', 'sales_invoice')
                ->when($cashLedgerAccountId, fn ($query) => $query->where('ledger_entries.account_id', $cashLedgerAccountId)), 'legacy_cash_at_sale')
            ->get()
            ->map(function ($row) use ($org): object {
                $receipt = (float) ($row->cash_at_sale_receipt ?? 0);
                $row->cash_at_sale = $receipt > 0 ? $receipt : (float) ($row->legacy_cash_at_sale ?? 0);
                $row->account_id = $receipt > 0 ? $row->cash_at_sale_account_id : null;
                $row->account_name = $receipt > 0
                    ? (string) (DB::table('financial_accounts')->where('organization_id', $org)->where('id', $row->account_id)->value('name') ?: 'حساب تحصيل')
                    : 'الخزنة العامة غير المخصصة';
                unset($row->cash_at_sale_receipt, $row->cash_at_sale_account_id, $row->legacy_cash_at_sale);
                return $row;
            })
            ->filter(fn ($row) => (float) $row->cash_at_sale > 0);
        foreach ($sales as $row) {
            $push(['id' => 'sale-cash-'.$row->id, 'source_id' => $row->id, 'document_number' => $row->document_number, 'date' => $row->invoice_date,
                'type' => 'sale_cash', 'direction' => 'inbound', 'amount' => (float) $row->cash_at_sale, 'currency' => $row->currency,
                'party_name' => $row->party_name, 'category' => 'تحصيل نقدي وقت البيع', 'account_id' => $row->account_id, 'account_name' => $row->account_name,
                'trip_id' => $row->trip_id, 'trip_number' => $row->trip_number, 'notes' => 'النقدي المسجل لحظة إصدار فاتورة البيع؛ الآجل معروض في التقرير كذمم وليس نقدية.']);
        }

        $payments = DB::table('payments as p')
            ->join('financial_accounts as fa', function ($join): void { $join->on('fa.id', '=', 'p.financial_account_id')->on('fa.organization_id', '=', 'p.organization_id'); })
            ->leftJoin('customers as c', function ($join): void { $join->on('c.id', '=', 'p.party_id')->on('c.organization_id', '=', 'p.organization_id')->where('p.party_type', '=', 'customer'); })
            ->leftJoin('suppliers as s', function ($join): void { $join->on('s.id', '=', 'p.party_id')->on('s.organization_id', '=', 'p.organization_id')->where('p.party_type', '=', 'supplier'); })
            ->leftJoin('trips as t', function ($join): void { $join->on('t.id', '=', 'p.trip_id')->on('t.organization_id', '=', 'p.organization_id'); })
            ->where('p.organization_id', $org)->where('p.status', 'posted')->whereBetween('p.payment_date', [$from, $to])
            ->where(function ($query): void { $query->whereNull('p.source_document_type')->orWhere('p.source_document_type', '<>', 'sale_at_issue'); })
            ->get(['p.id', 'p.payment_date', 'p.amount', 'p.currency', 'p.direction', 'p.party_type', 'p.reference', 'p.trip_id', 'fa.id as account_id', 'fa.name as account_name', 'c.name as customer_name', 's.name as supplier_name', 't.trip_number']);
        foreach ($payments as $row) {
            $push(['id' => 'payment-'.$row->id, 'source_id' => $row->id, 'document_number' => 'PAY-'.strtoupper(substr((string) $row->id, 0, 8)), 'date' => $row->payment_date,
                'type' => $row->party_type === 'customer' ? 'customer_collection' : 'supplier_payment', 'direction' => $row->direction,
                'amount' => (float) $row->amount, 'currency' => $row->currency, 'party_name' => $row->customer_name ?: $row->supplier_name,
                'category' => $row->party_type === 'customer' ? 'تحصيل عميل' : 'سداد مورد', 'account_id' => $row->account_id,
                'account_name' => $row->account_name, 'trip_id' => $row->trip_id, 'trip_number' => $row->trip_number, 'reference' => $row->reference]);
        }

        $expenses = DB::table('expenses as e')
            ->join('financial_accounts as fa', function ($join): void { $join->on('fa.id', '=', 'e.financial_account_id')->on('fa.organization_id', '=', 'e.organization_id'); })
            ->leftJoin('vehicles as v', function ($join): void { $join->on('v.id', '=', 'e.vehicle_id')->on('v.organization_id', '=', 'e.organization_id'); })
            ->leftJoin('trips as t', function ($join): void { $join->on('t.id', '=', 'e.trip_id')->on('t.organization_id', '=', 'e.organization_id'); })
            ->where('e.organization_id', $org)->where('e.status', 'posted')->whereBetween('e.expense_date', [$from, $to])
            ->get(['e.id', 'e.category', 'e.amount', 'e.expense_date', 'e.notes', 'e.trip_id', 'fa.id as account_id', 'fa.name as account_name', 'v.name as vehicle_name', 't.trip_number']);
        foreach ($expenses as $row) {
            $push(['id' => 'expense-'.$row->id, 'source_id' => $row->id, 'document_number' => 'EXP-'.strtoupper(substr((string) $row->id, 0, 8)), 'date' => $row->expense_date,
                'type' => 'expense', 'direction' => 'outbound', 'amount' => (float) $row->amount, 'currency' => $currency,
                'party_name' => $row->vehicle_name, 'category' => $row->category, 'account_id' => $row->account_id,
                'account_name' => $row->account_name, 'trip_id' => $row->trip_id, 'trip_number' => $row->trip_number, 'notes' => $row->notes]);
        }

        $manual = DB::table('cashbox_transactions as cb')
            ->leftJoin('financial_accounts as fa', function ($join): void { $join->on('fa.id', '=', 'cb.financial_account_id')->on('fa.organization_id', '=', 'cb.organization_id'); })
            ->leftJoin('financial_accounts as dest', function ($join): void { $join->on('dest.id', '=', 'cb.destination_financial_account_id')->on('dest.organization_id', '=', 'cb.organization_id'); })
            ->leftJoin('trips as t', function ($join): void { $join->on('t.id', '=', 'cb.trip_id')->on('t.organization_id', '=', 'cb.organization_id'); })
            ->leftJoin('vehicles as v', function ($join): void { $join->on('v.id', '=', 'cb.vehicle_id')->on('v.organization_id', '=', 'cb.organization_id'); })
            ->where('cb.organization_id', $org)->where('cb.status', 'posted')->whereBetween('cb.transaction_date', [$from, $to])
            ->get(['cb.*', 'fa.name as account_name', 'dest.name as destination_account_name', 't.trip_number', 'v.name as vehicle_name']);
        foreach ($manual as $row) {
            $push(['id' => 'cashbox-'.$row->id, 'source_id' => $row->id, 'document_number' => $row->document_number, 'date' => $row->transaction_date,
                'type' => $row->transaction_type, 'direction' => $row->direction, 'amount' => (float) $row->amount, 'currency' => $row->currency,
                'party_name' => $row->beneficiary_name_snapshot ?: $row->vehicle_name, 'beneficiary_user_id' => $row->beneficiary_user_id, 'category' => $row->category,
                'account_id' => $row->financial_account_id, 'account_name' => $row->source_is_general_cash ? 'الخزنة العامة' : ($row->account_name ?: '—'),
                'destination_account_id' => $row->destination_financial_account_id, 'destination_account_name' => $row->destination_account_name, 'trip_id' => $row->trip_id, 'trip_number' => $row->trip_number,
                'vehicle_name' => $row->vehicle_name, 'reference' => $row->reference, 'notes' => $row->notes]);
        }

        $movements = $movements->filter(function (array $row) use ($data): bool {
            if (! empty($data['direction']) && $row['direction'] !== $data['direction']) return false;
            if (! empty($data['financial_account_id']) && ($row['account_id'] ?? null) !== $data['financial_account_id'] && ($row['destination_account_id'] ?? null) !== $data['financial_account_id']) return false;
            if (! empty($data['beneficiary_user_id']) && ($row['beneficiary_user_id'] ?? null) !== $data['beneficiary_user_id']) return false;
            return true;
        })->sortByDesc(fn (array $row) => $row['date'].'|'.$row['document_number'])->take((int) ($data['limit'] ?? 300))->values();

        $custodyRows = DB::table('cashbox_transactions as cb')
            ->join('users as u', function ($join): void { $join->on('u.id', '=', 'cb.beneficiary_user_id')->on('u.organization_id', '=', 'cb.organization_id'); })
            ->where('cb.organization_id', $org)->where('cb.status', 'posted')
            ->whereIn('cb.transaction_type', ['custody_issue', 'custody_return'])
            ->groupBy('u.id', 'u.name')
            ->selectRaw("u.id as user_id, u.name, SUM(CASE WHEN cb.transaction_type = 'custody_issue' THEN cb.amount ELSE 0 END) as total_issued, SUM(CASE WHEN cb.transaction_type = 'custody_return' THEN cb.amount ELSE 0 END) as total_returned")
            ->get()->map(function ($row) use ($org) {
                $issued = (float) $row->total_issued;
                $returned = (float) $row->total_returned;
                return ['user_id' => $row->user_id, 'name' => $row->name, 'total_issued' => $issued, 'total_returned' => $returned, 'outstanding' => round($issued - $returned, 4)];
            })->values();

        $summary = $this->cashSummary($org, $from, $to, $currency);
        return response()->json([
            'period' => ['date_from' => $from, 'date_to' => $to], 'currency' => $currency,
            'summary' => $summary, 'accounts' => $balances, 'general_cash_balance' => $generalCashBalance,
            'movements' => $movements, 'custody' => $custodyRows, 'notes' => [
                'transfers_are_internal_and_do_not_change_global_cash' => true,
                'credit_sales_are_receivables_not_cash' => true,
                'legacy_sales_cash_uses_general_cash_until_an_account_is_selected' => true,
            ],
        ]);
    }

    public function beneficiaries(Request $request)
    {
        $org = (string) $request->user()->organization_id;
        return response()->json(DB::table('users as u')
            ->join('user_roles as ur', function ($join): void { $join->on('ur.user_id', '=', 'u.id')->on('ur.organization_id', '=', 'u.organization_id'); })
            ->where('u.organization_id', $org)->where('u.status', 'active')
            ->select('u.id', 'u.name', 'u.phone')->distinct()->orderBy('u.name')->get());
    }

    public function store(Request $request, TransactionPostingService $posting)
    {
        $data = $request->validate([
            'transaction_type' => ['required', 'in:opening_balance,other_income,owner_withdrawal,salary_payment,other_expense,account_transfer,custody_issue,custody_return'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'financial_account_id' => ['nullable', 'string', 'size:26'],
            'source_is_general_cash' => ['nullable', 'boolean'],
            'destination_financial_account_id' => ['nullable', 'string', 'size:26'],
            'transaction_date' => ['nullable', 'date_format:Y-m-d'],
            'beneficiary_type' => ['nullable', 'in:owner,driver,employee'],
            'beneficiary_user_id' => ['nullable', 'string', 'size:26'],
            'beneficiary_name' => ['nullable', 'string', 'max:255'],
            'vehicle_id' => ['nullable', 'string', 'size:26'],
            'trip_id' => ['nullable', 'string', 'size:26'],
            'category' => ['required', 'string', 'max:128', 'regex:/\S/'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        $data['created_by'] = $request->user()->id;
        return response()->json($posting->postCashboxTransaction((string) $request->user()->organization_id, $data), 201);
    }

    private function cashSummary(string $org, string $from, string $to, string $currency): array
    {
        $receiptsAtSale = (float) DB::table('payments as p')
            ->join('sales_invoices as si', function ($join): void { $join->on('si.id', '=', 'p.source_document_id')->on('si.organization_id', '=', 'p.organization_id'); })
            ->where('p.organization_id', $org)->where('p.source_document_type', 'sale_at_issue')->where('p.status', 'posted')
            ->where('si.status', 'posted')->whereBetween('si.invoice_date', [$from, $to])->sum('p.amount');
        $legacySalesCash = (float) DB::table('ledger_entries as le')
            ->join('ledger_accounts as la', function ($join): void { $join->on('la.id', '=', 'le.account_id')->on('la.organization_id', '=', 'le.organization_id'); })
            ->join('sales_invoices as si', function ($join): void { $join->on('si.id', '=', 'le.source_document_id')->on('si.organization_id', '=', 'le.organization_id'); })
            ->where('le.organization_id', $org)->where('la.code', '1000')->where('le.source_document_type', 'sales_invoice')
            ->where('si.status', 'posted')->whereBetween('si.invoice_date', [$from, $to])
            ->whereNotExists(function ($query) use ($org): void {
                $query->selectRaw('1')->from('payments as at_sale')->where('at_sale.organization_id', $org)
                    ->where('at_sale.source_document_type', 'sale_at_issue')->where('at_sale.status', 'posted')
                    ->whereColumn('at_sale.source_document_id', 'si.id');
            })
            ->selectRaw('COALESCE(SUM(le.debit - le.credit),0) as total')->value('total');
        $salesCash = $receiptsAtSale + $legacySalesCash;
        $collectionsQuery = DB::table('payments')->where('organization_id', $org)->where('status', 'posted')->whereBetween('payment_date', [$from, $to]);
        $collections = (float) (clone $collectionsQuery)->where('direction', 'inbound')->where(function ($query): void { $query->whereNull('source_document_type')->orWhere('source_document_type', '<>', 'sale_at_issue'); })->sum('amount');
        $supplierPayments = (float) (clone $collectionsQuery)->where('direction', 'outbound')->sum('amount');
        $expenses = (float) DB::table('expenses')->where('organization_id', $org)->where('status', 'posted')->whereBetween('expense_date', [$from, $to])->sum('amount');
        $otherIncome = (float) DB::table('cashbox_transactions')->where('organization_id', $org)->where('status', 'posted')->where('transaction_type', 'other_income')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $openingBalance = (float) DB::table('cashbox_transactions')->where('organization_id', $org)->where('status', 'posted')->where('transaction_type', 'opening_balance')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $ownerDrawings = (float) DB::table('cashbox_transactions')->where('organization_id', $org)->where('status', 'posted')->where('transaction_type', 'owner_withdrawal')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $salaryPayments = (float) DB::table('cashbox_transactions')->where('organization_id', $org)->where('status', 'posted')->where('transaction_type', 'salary_payment')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $otherCashExpenses = (float) DB::table('cashbox_transactions')->where('organization_id', $org)->where('status', 'posted')->where('transaction_type', 'other_expense')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $custodyIssued = (float) DB::table('cashbox_transactions')->where('organization_id', $org)->where('status', 'posted')->where('transaction_type', 'custody_issue')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $custodyReturned = (float) DB::table('cashbox_transactions')->where('organization_id', $org)->where('status', 'posted')->where('transaction_type', 'custody_return')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        $transfers = (float) DB::table('cashbox_transactions')->where('organization_id', $org)->where('status', 'posted')->where('transaction_type', 'account_transfer')->whereBetween('transaction_date', [$from, $to])->sum('amount');
        return [
            'sales_cash' => $salesCash, 'customer_collections' => $collections,
            'other_income' => $otherIncome, 'opening_cash' => $openingBalance,
            'supplier_payments' => $supplierPayments, 'expenses' => $expenses,
            'owner_withdrawals' => $ownerDrawings, 'salary_payments' => $salaryPayments,
            'other_expenses' => $otherCashExpenses, 'custody_issued' => $custodyIssued, 'custody_returned' => $custodyReturned, 'internal_transfers' => $transfers,
            'total_inbound' => $salesCash + $collections + $otherIncome + $openingBalance + $custodyReturned,
            'total_outbound' => $supplierPayments + $expenses + $ownerDrawings + $salaryPayments + $otherCashExpenses + $custodyIssued,
            'net_cash_flow' => $salesCash + $collections + $otherIncome + $openingBalance + $custodyReturned - $supplierPayments - $expenses - $ownerDrawings - $salaryPayments - $otherCashExpenses - $custodyIssued,
            'currency' => $currency,
        ];
    }
}
