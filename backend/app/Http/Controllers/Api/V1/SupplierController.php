<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class SupplierController extends Controller{
 public function index(Request $request){
  $q=trim((string)$request->query('q',''));
  $query=DB::table('suppliers')->where('organization_id',$request->user()->organization_id)->where('active',true);
  if($q!=='')$query->where(fn($w)=>$w->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('phone','like','%'.$q.'%'));
  return response()->json($query->select(['id','name','code','phone','address','tax_identifier','credit_terms_days','active'])->selectRaw("'active' as status")->orderBy('name')->limit(100)->get()->map(function($supplier){$supplier->address_text=$supplier->address;$supplier->tax_number=$supplier->tax_identifier;return $supplier;}));
 }

 public function store(Request $request){
  $org=$request->user()->organization_id;
  $data=$request->validate([
   'name'=>['required','string','max:255'],
   'code'=>['nullable','string','max:64',Rule::unique('suppliers','code')->where(fn($q)=>$q->where('organization_id',$org))],
   'phone'=>['nullable','string','max:32'],
   'tax_number'=>['nullable','string','max:128'],
   'address_text'=>['nullable','string','max:2000'],
   'notes'=>['nullable','string','max:5000'],
  ]);
  $id=(string)Str::ulid();
  DB::table('suppliers')->insert([
   'id'=>$id,'organization_id'=>$org,'code'=>$data['code']??'SUP-'.strtoupper(Str::random(8)),
   'name'=>$data['name'],'normalized_name'=>mb_strtolower(trim($data['name']),'UTF-8'),
   'phone'=>$data['phone']??null,'tax_identifier'=>$data['tax_number']??null,
   'address'=>$data['address_text']??null,'active'=>true,'credit_terms_days'=>0,
   'created_at'=>now(),'updated_at'=>now(),
  ]);
  return response()->json(DB::table('suppliers')->where('organization_id',$org)->where('id',$id)->select(['id','name','code','phone','address','tax_identifier','credit_terms_days','active'])->selectRaw("'active' as status")->first(),201);
 }
}
