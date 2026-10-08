<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class FinanceReferenceController extends Controller{
 public function index(Request $request){
  $org=$request->user()->organization_id;
  return response()->json([
   'accounts'=>DB::table('financial_accounts')->where('organization_id',$org)->where('active',true)->orderBy('name')->get(),
   'payment_methods'=>DB::table('payment_methods')->where(function($q)use($org){$q->whereNull('organization_id')->orWhere('organization_id',$org);})->where('active',true)->orderBy('name_ar')->get(),
  ]);
 }
}