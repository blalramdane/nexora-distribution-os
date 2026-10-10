<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function adjust(Request $request, \App\Services\Transactions\TransactionPostingService $posting)
    {
        $data=$request->validate([
            'product_id'=>['required','string','size:26'],
            'location_id'=>['required','string','size:26'],
            'quantity_delta'=>['required','numeric','not_in:0'],
            'reason'=>['required','string','max:255'],
            'idempotency_key'=>['required','string','max:255'],
        ]);
        $data['created_by']=$request->user()->id;
        return response()->json($posting->postStockAdjustment($request->user()->organization_id,$data),201);
    }

    public function index(Request $request)
    {
        $query=DB::table('stock_balances as sb')
            ->join('products as p','p.id','=','sb.product_id')
            ->join('locations as l','l.id','=','sb.location_id')
            ->where('sb.organization_id',$request->user()->organization_id)
            ->select('sb.*','p.sku','p.name_ar','p.name_en','l.code as location_code','l.name as location_name')
            ->orderBy('p.name_ar');
        if($request->filled('location_id')) $query->where('sb.location_id',$request->query('location_id'));
        if($request->filled('product_id')) $query->where('sb.product_id',$request->query('product_id'));
        return response()->json($query->paginate(min((int)$request->query('per_page',50),200)));
    }
}