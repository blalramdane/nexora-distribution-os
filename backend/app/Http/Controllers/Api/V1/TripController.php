<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class TripController extends Controller{
 private function number(string $org,string $type):string{
  $row=DB::table('document_sequences')->where('organization_id',$org)->where('document_type',$type)->where('active',true)->lockForUpdate()->first();
  if(!$row){$id=(string)Str::ulid();DB::table('document_sequences')->insert(['id'=>$id,'organization_id'=>$org,'document_type'=>$type,'prefix'=>strtoupper($type),'next_number'=>2,'padding'=>6,'reset_policy'=>'never','active'=>true,'created_at'=>now(),'updated_at'=>now()]);return strtoupper($type).'-000001';}
  DB::table('document_sequences')->where('id',$row->id)->update(['next_number'=>$row->next_number+1,'updated_at'=>now()]);
  return ($row->prefix?$row->prefix.'-':'').str_pad((string)$row->next_number,(int)$row->padding,'0',STR_PAD_LEFT);
 }
 public function index(Request $request){
  $org=$request->user()->organization_id;
  return response()->json(DB::table('trips as t')->join('vehicles as v','v.id','=','t.vehicle_id')->join('users as u','u.id','=','t.rep_user_id')->where('t.organization_id',$org)->select('t.*','v.name as vehicle_name','v.code as vehicle_code','u.name as rep_name')->orderByDesc('t.trip_date')->limit(100)->get());
 }
 public function vehicles(Request $request){return response()->json(DB::table('vehicles')->where('organization_id',$request->user()->organization_id)->where('active',true)->orderBy('name')->get());}
 public function store(Request $request){
  $data=$request->validate(['vehicle_id'=>['required','string','size:26'],'rep_user_id'=>['required','string','size:26'],'origin_location_id'=>['required','string','size:26'],'trip_date'=>['nullable','date'],'notes'=>['nullable','string']]);
  $org=$request->user()->organization_id;
  $trip=null;
  DB::transaction(function()use(&$trip,$data,$org,$request){$tripId=(string)Str::ulid();$trip=['id'=>$tripId,'organization_id'=>$org,'trip_number'=>$this->number($org,'trip'),'vehicle_id'=>$data['vehicle_id'],'rep_user_id'=>$data['rep_user_id'],'status'=>'planned','trip_date'=>$data['trip_date']??now()->toDateString(),'origin_location_id'=>$data['origin_location_id'],'notes'=>$data['notes']??null,'created_at'=>now(),'updated_at'=>now()];DB::table('trips')->insert($trip);});
  return response()->json($trip,201);
 }
 public function stock(Request $request,string $trip){
  $org=$request->user()->organization_id;
  $row=DB::table('trips')->where('organization_id',$org)->where('id',$trip)->first();
  abort_unless($row,404);
  $location=DB::table('vehicles')->where('organization_id',$org)->where('id',$row->vehicle_id)->value('location_id');
  return response()->json(DB::table('stock_balances as s')->join('products as p','p.id','=','s.product_id')->where('s.organization_id',$org)->where('s.location_id',$location)->where('s.quantity_base','>',0)->select('p.id as product_id','p.sku','p.name_ar','s.quantity_base','s.average_cost')->orderBy('p.name_ar')->get());
 }
 public function assignCustomer(Request $request,string $trip){
  $data=$request->validate(['customer_id'=>['required','string','size:26'],'sequence'=>['nullable','integer','min:1']]);$org=$request->user()->organization_id;
  abort_unless(DB::table('trips')->where('id',$trip)->where('organization_id',$org)->exists(),404);
  DB::table('trip_customers')->updateOrInsert(['trip_id'=>$trip,'customer_id'=>$data['customer_id']],['id'=>(string)Str::ulid(),'organization_id'=>$org,'sequence'=>$data['sequence']??1,'planned'=>true,'visit_status'=>'planned','updated_at'=>now(),'created_at'=>now()]);
  return response()->json(['status'=>'assigned']);
 }
}