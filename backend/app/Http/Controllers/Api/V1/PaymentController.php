<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;

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
            'idempotency_key'=>['required','string','max:255'],
            'allocations'=>['nullable','array'],
            'allocations.*.document_type'=>['required','in:sales_invoice,purchase_invoice'],
            'allocations.*.document_id'=>['required','string','size:26'],
            'allocations.*.amount'=>['required','numeric','gt:0'],
        ]);

        $data['created_by'] = $request->user()->id;

        return response()->json(
            $posting->postPayment($request->user()->organization_id, $data),
            201
        );
    }
}
