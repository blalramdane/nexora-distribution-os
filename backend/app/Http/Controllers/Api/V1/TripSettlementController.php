<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
class TripSettlementController extends Controller{
 public function store(Request $request,TransactionPostingService $posting){
  $data=$request->validate([
   'trip_id'=>['required','string','size:26'],
   'opening_cash'=>['nullable','numeric','min:0'],
   'actual_cash'=>['required','numeric','min:0'],
   'closing_items'=>['required','array'],
   'closing_items.*.product_id'=>['required','string','size:26'],
   'closing_items.*.quantity_base'=>['required','numeric','gte:0'],
  ]);
  $data['settled_by']=$request->user()->id;
  return response()->json($posting->settleTrip($request->user()->organization_id,$data),201);
 }
}