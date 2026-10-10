<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'trip_id' => ['nullable', 'string', 'size:26'],
            'expense_date_from' => ['nullable', 'date'],
            'expense_date_to' => ['nullable', 'date', 'after_or_equal:expense_date_from'],
        ]);

        $organizationId = $request->user()->organization_id;
        $query = DB::table('expenses as e')
            ->join('financial_accounts as fa', function ($join): void {
                $join->on('fa.id', '=', 'e.financial_account_id')
                    ->on('fa.organization_id', '=', 'e.organization_id');
            })
            ->leftJoin('trips as t', function ($join): void {
                $join->on('t.id', '=', 'e.trip_id')
                    ->on('t.organization_id', '=', 'e.organization_id');
            })
            ->leftJoin('vehicles as v', function ($join): void {
                $join->on('v.id', '=', 'e.vehicle_id')
                    ->on('v.organization_id', '=', 'e.organization_id');
            })
            ->where('e.organization_id', $organizationId)
            ->where('e.status', 'posted')
            ->select([
                'e.id', 'e.category', 'e.amount', 'e.expense_date', 'e.trip_id',
                'e.vehicle_id', 'e.notes', 'e.status', 'e.created_at',
                'fa.id as financial_account_id', 'fa.code as financial_account_code',
                'fa.name as financial_account_name', 't.trip_number', 'v.name as vehicle_name',
            ]);

        if (! empty($data['trip_id'])) {
            abort_unless(
                DB::table('trips')->where('organization_id', $organizationId)->where('id', $data['trip_id'])->exists(),
                404,
            );
            $query->where('e.trip_id', $data['trip_id']);
        }

        if (! empty($data['expense_date_from'])) {
            $query->whereDate('e.expense_date', '>=', $data['expense_date_from']);
        }
        if (! empty($data['expense_date_to'])) {
            $query->whereDate('e.expense_date', '<=', $data['expense_date_to']);
        }

        return response()->json($query->orderByDesc('e.expense_date')->orderByDesc('e.created_at')->limit(200)->get());
    }

    public function store(Request $request, TransactionPostingService $posting)
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:128', 'regex:/\S/'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'financial_account_id' => ['required', 'string', 'size:26'],
            'expense_date' => ['nullable', 'date'],
            'trip_id' => ['nullable', 'string', 'size:26'],
            'vehicle_id' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);

        $data['created_by'] = $request->user()->id;

        return response()->json($posting->postExpense($request->user()->organization_id, $data), 201);
    }
}
