<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Http\Request;
class TripSettlementController extends Controller{
 public function preview(Request $request,TransactionPostingService $posting,string $trip){
  $organizationId=(string)$request->user()->organization_id;
  abort_unless(\Illuminate\Support\Facades\DB::table('trips')->where('organization_id',$organizationId)->where('id',$trip)->exists(),404);
  return response()->json($posting->previewTripSupplierSettlement($organizationId,$trip));
 }

 public function store(Request $request,TransactionPostingService $posting){
  $user=$request->user();
  if($user->roles()->where('roles.organization_id',$user->organization_id)->where('roles.key','field_rep')->exists()){
   return response()->json(['message'=>'إقفال الرحلة والتسوية النهائية متاحان للمشرف أو المحاسب فقط.'],403);
  }
  $data=$request->validate([
   'trip_id'=>['required','string','size:26'],
   'idempotency_key'=>['required','string','max:255'],
   'opening_cash'=>['nullable','numeric','min:0'],
   'actual_cash'=>['required','numeric','min:0'],
   'closing_items'=>['required','array'],
   'closing_items.*.product_id'=>['required','string','size:26','distinct'],
   'closing_items.*.quantity_base'=>['required','numeric','gte:0'],
  ]);
  $data['settled_by']=$user->id;
  return response()->json($posting->settleTrip($user->organization_id,$data),201);
 }
}
