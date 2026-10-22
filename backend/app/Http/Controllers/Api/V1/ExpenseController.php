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

        $user = $request->user();
        $organizationId = (string) $user->organization_id;
        $isFieldRep = $user->roles()
            ->where('roles.organization_id', $organizationId)
            ->where('roles.key', 'field_rep')
            ->exists();
        if ($isFieldRep) {
            if (abs((float) $data['amount'] - round((float) $data['amount'])) > 0.000001) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => ['قيمة المصروف لازم تكون بالجنيه الصحيح من غير كسور.']]);
            }
            if (empty($data['trip_id']) || empty($data['vehicle_id'])) {
                return response()->json(['message' => 'مصروف المندوب لازم يكون مرتبطًا برحلته والعربية المسندة له.'], 403);
            }
            $authorizedTrip = DB::table('trips as t')
                ->join('vehicles as v', function ($join): void {
                    $join->on('v.id', '=', 't.vehicle_id')->on('v.organization_id', '=', 't.organization_id');
                })
                ->where('t.organization_id', $organizationId)
                ->where('t.id', $data['trip_id'])
                ->where('t.rep_user_id', $user->id)
                ->where('v.id', $data['vehicle_id'])
                ->whereNotIn('t.status', ['completed', 'cancelled'])
                ->exists();
            $validAccount = DB::table('financial_accounts')
                ->where('organization_id', $organizationId)
                ->where('id', $data['financial_account_id'])
                ->where('active', true)
                ->exists();
            if (! $authorizedTrip || ! $validAccount) {
                return response()->json(['message' => 'الرحلة أو العربية أو الحساب المالي غير متاح لحساب المندوب.'], 403);
            }
        }

        return response()->json($posting->postExpense($user->organization_id, $data), 201);
    }
}
