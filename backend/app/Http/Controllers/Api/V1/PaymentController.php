<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function store(Request $request, TransactionPostingService $posting)
    {
        $data = $request->validate([
            'party_type'=>['required','in:customer,supplier'],
            'party_id'=>['required','string','size:26'],
            'financial_account_id'=>['required','string','size:26'],
            'payment_method_id'=>['required','string','size:26'],
            'direction'=>['required','in:inbound,outbound'],
            'amount'=>['required','numeric','gt:0'],
            'currency'=>['nullable','string','size:3'],
            'payment_date'=>['nullable','date'],
            'reference'=>['nullable','string','max:255'],
            'trip_id'=>['nullable','string','size:26'],
            'trip_supplier_settlement_id'=>['nullable','string','size:26'],
            'idempotency_key'=>['required','string','max:255'],
            'allocations'=>['nullable','array'],
            'allocations.*.document_type'=>['required','in:sales_invoice,purchase_invoice'],
            'allocations.*.document_id'=>['required','string','size:26'],
            'allocations.*.amount'=>['required','numeric','gt:0'],
        ]);

        $data['created_by'] = $request->user()->id;

        $organizationId = (string) $request->user()->organization_id;
        $isFieldRep = $request->user()->roles()
            ->where('roles.organization_id', $organizationId)
            ->where('roles.key', 'field_rep')
            ->exists();
        if ($isFieldRep) {
            if (abs((float) $data['amount'] - round((float) $data['amount'])) > 0.000001) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => ['مبلغ التحصيل لازم يكون بالجنيه الصحيح من غير كسور.']]);
            }
            if ($data['direction'] !== 'inbound' || $data['party_type'] !== 'customer' || empty($data['trip_id'])) {
                return response()->json(['message' => 'المندوب الميداني يستطيع تسجيل تحصيل عميل مرتبط برحلته فقط.'], 403);
            }

            $assigned = DB::table('trips as t')
                ->join('trip_customers as tc', function ($join): void {
                    $join->on('tc.trip_id', '=', 't.id')->on('tc.organization_id', '=', 't.organization_id');
                })
                ->where('t.organization_id', $organizationId)
                ->where('t.id', $data['trip_id'])
                ->where('t.rep_user_id', $request->user()->id)
                ->where('tc.customer_id', $data['party_id'])
                ->whereNotIn('t.status', ['completed', 'cancelled'])
                ->exists();
            if (! $assigned) {
                return response()->json(['message' => 'العميل أو الرحلة غير مسندين لحساب المندوب.'], 404);
            }
        }

        return response()->json(
            $posting->postPayment($request->user()->organization_id, $data),
            201
        );
    }
}
