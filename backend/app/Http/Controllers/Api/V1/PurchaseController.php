<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function store(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'supplier_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'invoice_date'=>['nullable','date'],
            'supplier_invoice_number'=>['nullable','string','max:128'],
            'discount'=>['nullable','numeric','min:0'],
            'tax'=>['nullable','numeric','min:0'],
            'currency'=>['nullable','string','size:3'],
            'notes'=>['nullable','string'],
            'idempotency_key'=>['required','string','max:255'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['required','string','size:26'],
            'items.*.quantity'=>['required','numeric','gt:0'],
            'items.*.conversion_factor'=>['nullable','numeric','gt:0'],
            'items.*.unit_cost'=>['required','numeric','gte:0'],
            'items.*.packaging_id'=>['nullable','string','size:26'],
            'items.*.unit_id'=>['nullable','string','size:26'],
        ]);
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postPurchase($request->user()->organization_id,$data),201);
    }
}