<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class FinanceReferenceController extends Controller{
 public function index(Request $request){
  $org=$request->user()->organization_id;
  return response()->json([
   'accounts'=>DB::table('financial_accounts')->where('organization_id',$org)->where('active',true)->orderBy('name')->get(),
   'payment_methods'=>DB::table('payment_methods')->where(function($q)use($org){$q->whereNull('organization_id')->orWhere('organization_id',$org);})->where('active',true)->orderBy('name_ar')->get(),
  ]);
 }

 public function storeAccount(Request $request){
  $org=(string)$request->user()->organization_id;
  $data=$request->validate([
   'name'=>['required','string','max:180'],
   'type'=>['required',Rule::in(['cash','wallet','bank','other'])],
   'currency'=>['nullable','string','size:3'],
   'opening_balance'=>['nullable','numeric','min:0'],
  ]);
  $base=strtoupper((string)DB::table('organizations')->where('id',$org)->value('default_currency'));
  $code=strtoupper($data['type']).'_'.Str::upper(Str::random(10));
  $id=(string)Str::ulid();
  DB::table('financial_accounts')->insert([
   'id'=>$id,'organization_id'=>$org,'code'=>$code,'name'=>trim($data['name']),
   'type'=>$data['type'],'currency'=>strtoupper($data['currency']??$base),
   'opening_balance'=>$data['opening_balance']??0,'active'=>true,'created_at'=>now(),'updated_at'=>now(),
  ]);
  return response()->json(DB::table('financial_accounts')->where('organization_id',$org)->where('id',$id)->first(),201);
 }

 public function storePaymentMethod(Request $request){
  $org=(string)$request->user()->organization_id;
  $data=$request->validate([
   'name_ar'=>['required','string','max:120'],
   'code'=>['required','string','max:32','regex:/^[a-zA-Z0-9_-]+$/'],
   'requires_reference'=>['nullable','boolean'],
  ]);
  $code=strtolower($data['code']);
  $exists=DB::table('payment_methods')->where('organization_id',$org)->where('code',$code)->exists();
  if($exists) return response()->json(['message'=>'طريقة الدفع دي موجودة بالفعل.'],422);
  $id=(string)Str::ulid();
  DB::table('payment_methods')->insert([
   'id'=>$id,'organization_id'=>$org,'code'=>$code,'name_ar'=>trim($data['name_ar']),
   'name_en'=>null,'requires_reference'=>(bool)($data['requires_reference']??true),
   'active'=>true,'created_at'=>now(),'updated_at'=>now(),
  ]);
  return response()->json(DB::table('payment_methods')->where('id',$id)->first(),201);
 }
}
