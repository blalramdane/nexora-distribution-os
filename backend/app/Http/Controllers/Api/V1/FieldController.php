<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class FieldController extends Controller{
 public function today(Request $request){
  $org=$request->user()->organization_id;$uid=$request->user()->id;$date=now()->toDateString();
  $trip=DB::table('trips as t')->join('vehicles as v','v.id','=','t.vehicle_id')->where('t.organization_id',$org)->where('t.rep_user_id',$uid)->whereDate('t.trip_date',$date)->whereNotIn('t.status',['completed','cancelled'])->select('t.*','v.name as vehicle_name','v.code as vehicle_code','v.location_id as vehicle_location_id')->orderByDesc('t.created_at')->first();
  if(!$trip)return response()->json(['trip'=>null,'customers'=>[],'stock'=>[]]);
  $customers=DB::table('trip_customers as tc')->join('customers as c','c.id','=','tc.customer_id')->leftJoin('customer_addresses as ca',function($j){$j->on('ca.customer_id','=','c.id')->where('ca.is_primary',true)->where('ca.active',true);})
   ->where('tc.organization_id',$org)->where('tc.trip_id',$trip->id)->select('tc.id as assignment_id','tc.sequence','tc.visit_status','c.id','c.code','c.name','c.phone','c.address_text','ca.latitude','ca.longitude')->orderBy('tc.sequence')->get();
  $location=DB::table('vehicles')->where('organization_id',$org)->where('id',$trip->vehicle_id)->value('location_id');
  $stock=DB::table('stock_balances as s')->join('products as p','p.id','=','s.product_id')->where('s.organization_id',$org)->where('s.location_id',$location)->where('s.quantity_base','>',0)->select('p.id','p.sku','p.name_ar','p.default_piece_price','s.quantity_base','s.average_cost')->orderBy('p.name_ar')->limit(500)->get();
  return response()->json(['trip'=>$trip,'customers'=>$customers,'stock'=>$stock]);
 }
 public function visit(Request $request){
  $data=$request->validate(['trip_id'=>['required','string','size:26'],'customer_id'=>['required','string','size:26'],'status'=>['required','in:checked_in,visited,skipped'],'latitude'=>['nullable','numeric'],'longitude'=>['nullable','numeric'],'notes'=>['nullable','string']]);
  $org=$request->user()->organization_id;$userId=$request->user()->id;
  abort_unless(DB::table('trips')->where('id',$data['trip_id'])->where('organization_id',$org)->where('rep_user_id',$userId)->whereNotIn('status',['completed','cancelled'])->exists(),404);
  abort_unless(DB::table('trip_customers as tc')->join('customers as c','c.id','=','tc.customer_id')->where('tc.organization_id',$org)->where('tc.trip_id',$data['trip_id'])->where('tc.customer_id',$data['customer_id'])->where('c.organization_id',$org)->exists(),404);
  $existing=DB::table('customer_visits')->where('organization_id',$org)->where('trip_id',$data['trip_id'])->where('customer_id',$data['customer_id'])->where('user_id',$userId)->latest('created_at')->first();
  $now=now();
  if($existing){DB::table('customer_visits')->where('id',$existing->id)->update(['status'=>$data['status'],'check_in_at'=>$data['status']==='checked_in'?($existing->check_in_at?:$now):$existing->check_in_at,'check_out_at'=>in_array($data['status'],['visited','skipped'],true)?$now:$existing->check_out_at,'latitude'=>$data['latitude']??$existing->latitude,'longitude'=>$data['longitude']??$existing->longitude,'notes'=>$data['notes']??$existing->notes,'updated_at'=>$now]);}
  else DB::table('customer_visits')->insert(['id'=>(string)Str::ulid(),'organization_id'=>$org,'trip_id'=>$data['trip_id'],'customer_id'=>$data['customer_id'],'user_id'=>$request->user()->id,'status'=>$data['status'],'check_in_at'=>$data['status']==='checked_in'?$now:null,'check_out_at'=>in_array($data['status'],['visited','skipped'],true)?$now:null,'latitude'=>$data['latitude']??null,'longitude'=>$data['longitude']??null,'notes'=>$data['notes']??null,'created_at'=>$now,'updated_at'=>$now]);
  DB::table('trip_customers')->where('organization_id',$org)->where('trip_id',$data['trip_id'])->where('customer_id',$data['customer_id'])->update(['visit_status'=>$data['status'],'updated_at'=>$now]);
  return response()->json(['status'=>'ok']);
 }
 public function createCustomer(Request $request){
  $data=$request->validate(['name'=>['required','string','max:255'],'phone'=>['nullable','string','max:32'],'address_text'=>['nullable','string'],'latitude'=>['nullable','numeric'],'longitude'=>['nullable','numeric']]);
  $id=(string)Str::ulid();$org=$request->user()->organization_id;
  DB::table('customers')->insert(['id'=>$id,'organization_id'=>$org,'code'=>'CUS-'.strtoupper(Str::random(8)),'name'=>$data['name'],'normalized_name'=>mb_strtolower(trim($data['name']),'UTF-8'),'phone'=>$data['phone']??null,'address_text'=>$data['address_text']??null,'credit_limit'=>0,'payment_terms_days'=>0,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
  if(isset($data['latitude'],$data['longitude']))DB::table('customer_addresses')->insert(['id'=>(string)Str::ulid(),'organization_id'=>$org,'customer_id'=>$id,'label'=>'field','address_text'=>$data['address_text']??null,'latitude'=>$data['latitude'],'longitude'=>$data['longitude'],'is_primary'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
  return response()->json(DB::table('customers')->where('id',$id)->first(),201);
 }
}