<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SupplierController extends Controller{
 public function index(Request $request){
  $q=trim((string)$request->query('q',''));
  $query=DB::table('suppliers')->where('organization_id',$request->user()->organization_id)->where('status','active');
  if($q!=='')$query->where(fn($w)=>$w->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('phone','like','%'.$q.'%'));
  return response()->json($query->orderBy('name')->limit(100)->get());
 }
}