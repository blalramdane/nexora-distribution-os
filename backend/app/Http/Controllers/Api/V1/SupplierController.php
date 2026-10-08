<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierController extends Controller
{
 public function index(Request $request){
  $q=trim((string)$request->query('q',''));
  $query=DB::table('suppliers')->where('organization_id',$request->user()->organization_id)->where('active',true);
  if($q!=='')$query->where(fn($w)=>$w->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('phone','like','%'.$q.'%'));
  return response()->json($query->orderBy('name')->limit(100)->get());
 }

 public function store(Request $request){
  $data=$request->validate([
   'name'=>['required','string','max:255'],
   'code'=>['nullable','string','max:64'],
   'phone'=>['nullable','string','max:32'],
   'address'=>['nullable','string'],
   'tax_identifier'=>['nullable','string','max:128'],
   'credit_terms_days'=>['nullable','integer','min:0'],
  ]);
  $org=$request->user()->organization_id;
  $code=$data['code'] ?? 'SUP-'.strtoupper(Str::random(8));
  if(DB::table('suppliers')->where('organization_id',$org)->where('code',$code)->exists()){
   return response()->json(['message'=>'كود المورد مستخدم بالفعل.'],422);
  }
  $id=(string)Str::ulid();
  DB::table('suppliers')->insert([
   'id'=>$id,'organization_id'=>$org,'code'=>$code,'name'=>$data['name'],
   'normalized_name'=>mb_strtolower(trim($data['name']),'UTF-8'),'phone'=>$data['phone']??null,
   'address'=>$data['address']??null,'tax_identifier'=>$data['tax_identifier']??null,
   'credit_terms_days'=>$data['credit_terms_days']??0,'active'=>true,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
  return response()->json(DB::table('suppliers')->where('id',$id)->first(),201);
 }
}
