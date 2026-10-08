<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function sales(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'customer_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'original_sales_invoice_id'=>['nullable','string','size:26'],
            'trip_id'=>['nullable','string','size:26'],
            'return_date'=>['nullable','date'],
            'discount'=>['nullable','numeric','min:0'],
            'tax'=>['nullable','numeric','min:0'],
            'currency'=>['nullable','string','size:3'],
            'idempotency_key'=>['required','string','max:255'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['required','string','size:26'],
            'items.*.quantity'=>['required','numeric','gt:0'],
            'items.*.conversion_factor'=>['nullable','numeric','gt:0'],
            'items.*.unit_price'=>['required','numeric','gte:0'],
            'items.*.packaging_id'=>['nullable','string','size:26'],
            'items.*.original_sales_invoice_item_id'=>['nullable','string','size:26'],
        ]);
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postSalesReturn($request->user()->organization_id,$data),201);
    }

    public function purchases(Request $request, TransactionPostingService $posting)
    {
        $data=$request->validate([
            'supplier_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'original_purchase_invoice_id'=>['nullable','string','size:26'],
            'return_date'=>['nullable','date'],
            'discount'=>['nullable','numeric','min:0'],
            'tax'=>['nullable','numeric','min:0'],
            'currency'=>['nullable','string','size:3'],
            'idempotency_key'=>['required','string','max:255'],
            'items'=>['required','array','min:1'],
            'items.*.product_id'=>['required','string','size:26'],
            'items.*.quantity'=>['required','numeric','gt:0'],
            'items.*.conversion_factor'=>['nullable','numeric','gt:0'],
            'items.*.unit_cost'=>['required','numeric','gte:0'],
            'items.*.packaging_id'=>['nullable','string','size:26'],
            'items.*.original_purchase_invoice_item_id'=>['nullable','string','size:26'],
        ]);
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postPurchaseReturn($request->user()->organization_id,$data),201);
    }
}
