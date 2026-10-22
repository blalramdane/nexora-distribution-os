<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class FieldController extends Controller{
 public function references(Request $request){
  $org=$request->user()->organization_id;
  return response()->json([
   'accounts'=>DB::table('financial_accounts')->where('organization_id',$org)->where('active',true)->orderBy('name')->get(['id','code','name','currency']),
   'payment_methods'=>DB::table('payment_methods')->where(function($q)use($org){$q->whereNull('organization_id')->orWhere('organization_id',$org);})->where('active',true)->orderBy('name_ar')->get(['id','code','name_ar','requires_reference']),
  ]);
 }
 public function customerPrices(Request $request){
  $data=$request->validate(['trip_id'=>['required','string','size:26'],'customer_id'=>['required','string','size:26']]);
  $user=$request->user();$org=(string)$user->organization_id;
  $assigned=DB::table('trips as t')->join('trip_customers as tc',function($join){$join->on('tc.trip_id','=','t.id')->on('tc.organization_id','=','t.organization_id');})
   ->where('t.organization_id',$org)->where('t.id',$data['trip_id'])->where('t.rep_user_id',$user->id)
   ->where('tc.customer_id',$data['customer_id'])->whereNotIn('t.status',['completed','cancelled'])->exists();
  abort_unless($assigned,404);
  return response()->json(DB::table('customer_product_prices as cp')
   ->join('products as p',function($join){$join->on('p.id','=','cp.product_id')->on('p.organization_id','=','cp.organization_id');})
   ->leftJoin('product_packagings as pkg',function($join){$join->on('pkg.id','=','cp.packaging_id')->on('pkg.organization_id','=','cp.organization_id');})
   ->where('cp.organization_id',$org)->where('cp.customer_id',$data['customer_id'])->where('cp.active',true)
   ->where('p.active',true)->orderBy('p.name_ar')->orderBy('cp.unit_key')
   ->get(['cp.product_id','cp.packaging_id','cp.unit_key','cp.unit_price','p.sku','p.name_ar as product_name','pkg.name_ar as packaging_name']));
 }
 public function salesForTrip(Request $request){
  $data=$request->validate(['trip_id'=>['required','string','size:26'],'customer_id'=>['required','string','size:26']]);
  $user=$request->user();$org=$user->organization_id;
  $assigned=DB::table('trips as t')->join('trip_customers as tc',function($join){$join->on('tc.trip_id','=','t.id')->on('tc.organization_id','=','t.organization_id');})
   ->where('t.organization_id',$org)->where('t.id',$data['trip_id'])->where('t.rep_user_id',$user->id)->where('tc.customer_id',$data['customer_id'])->whereNotIn('t.status',['completed','cancelled'])->exists();
  abort_unless($assigned,404);
  return response()->json(DB::table('sales_invoices')->where('organization_id',$org)->where('trip_id',$data['trip_id'])->where('customer_id',$data['customer_id'])->where('status','posted')->orderByDesc('invoice_date')->orderByDesc('created_at')->limit(100)->get(['id','document_number','invoice_date','total','balance_due','currency']));
 }
 public function salesInvoiceItems(Request $request,string $invoiceId){
  $data=$request->validate(['trip_id'=>['required','string','size:26'],'customer_id'=>['required','string','size:26']]);
  $user=$request->user();$org=$user->organization_id;
  $assigned=DB::table('trips as t')->join('trip_customers as tc',function($join){$join->on('tc.trip_id','=','t.id')->on('tc.organization_id','=','t.organization_id');})
   ->where('t.organization_id',$org)->where('t.id',$data['trip_id'])->where('t.rep_user_id',$user->id)->where('tc.customer_id',$data['customer_id'])->whereNotIn('t.status',['completed','cancelled'])->exists();
  abort_unless($assigned,404);
  $invoice=DB::table('sales_invoices')->where('organization_id',$org)->where('id',$invoiceId)->where('trip_id',$data['trip_id'])->where('customer_id',$data['customer_id'])->where('status','posted')->first();
  abort_unless($invoice,404);
  return response()->json(DB::table('sales_invoice_items as item')->join('products as p',function($join){$join->on('p.id','=','item.product_id')->on('p.organization_id','=','item.organization_id');})->join('units as u','u.id','=','item.entered_unit_id')
   ->where('item.organization_id',$org)->where('item.sales_invoice_id',$invoiceId)
   ->select(['item.id','item.product_id','item.packaging_id','item.entered_unit_id','u.name_ar as entered_unit_name','p.base_unit_id','item.product_name_snapshot','item.sku_snapshot','item.entered_quantity','item.quantity_base','item.unit_price_entered','item.conversion_factor_snapshot'])
   ->selectSub(DB::table('sales_return_items as returned')->selectRaw('COALESCE(SUM(returned.quantity_base),0)')->whereColumn('returned.organization_id','item.organization_id')->whereColumn('returned.original_sales_invoice_item_id','item.id'),'returned_quantity_base')
   ->orderBy('item.created_at')->get()->map(static function($item){$item->remaining_quantity_base=max(0,(float)$item->quantity_base-(float)$item->returned_quantity_base);$factor=max(0.000001,(float)$item->conversion_factor_snapshot);$item->remaining_entered_quantity=round($item->remaining_quantity_base/$factor,6);return $item;})
   ->filter(static fn($item)=>$item->remaining_quantity_base>0)->values());
 }
 public function catalogForTrip(Request $request){
  $data=$request->validate(['trip_id'=>['required','string','size:26'],'q'=>['nullable','string','max:120'],'per_page'=>['nullable','integer','min:12','max:100']]);
  $user=$request->user();$org=$user->organization_id;$term=trim((string)($data['q']??''));
  $trip=DB::table('trips as t')->join('vehicles as v',function($join){$join->on('v.id','=','t.vehicle_id')->on('v.organization_id','=','t.organization_id');})
   ->where('t.organization_id',$org)->where('t.id',$data['trip_id'])->where('t.rep_user_id',$user->id)->whereNotIn('t.status',['completed','cancelled'])->select('t.id','v.location_id')->first();
  abort_unless($trip,404);
  $query=DB::table('products as p')->join('stock_balances as s',function($join)use($org,$trip){$join->on('s.product_id','=','p.id')->where('s.organization_id','=',$org)->where('s.location_id','=',$trip->location_id);})
   ->leftJoin('units as u','u.id','=','p.base_unit_id')
   ->where('p.organization_id',$org)->where('p.active',true)
   ->select(['p.id','p.sku','p.name_ar','p.name_en','p.brand','p.base_unit_id','u.name_ar as base_unit_name','s.quantity_base']);
  $query->selectSub(DB::table('product_barcodes')->select('barcode')->whereColumn('product_id','p.id')->where('organization_id',$org)->where('active',true)->orderBy('created_at')->limit(1),'barcode');
  if($term!==''){
   $like='%'.$term.'%';
   $query->where(function($where)use($like,$org){
    $where->where('p.name_ar','like',$like)->orWhere('p.name_en','like',$like)->orWhere('p.sku','like',$like)->orWhere('p.brand','like',$like)
     ->orWhereExists(function($sub)use($like,$org){$sub->selectRaw('1')->from('product_barcodes as b')->whereColumn('b.product_id','p.id')->where('b.organization_id',$org)->where('b.active',true)->where('b.barcode','like',$like);})
     ->orWhereExists(function($sub)use($like,$org){$sub->selectRaw('1')->from('product_aliases as a')->whereColumn('a.product_id','p.id')->where('a.organization_id',$org)->where('a.active',true)->where('a.normalized_alias','like',$like);});
   });
  }
  $products=$query->orderBy('p.name_ar')->orderBy('p.sku')->paginate((int)($data['per_page']??50));
  $ids=$products->getCollection()->pluck('id')->all();
  $packagings=DB::table('product_packagings as pp')->join('units as unit','unit.id','=','pp.unit_id')
   ->where('pp.organization_id',$org)->where('pp.active',true)->whereIn('pp.product_id',$ids)
   ->where(function($q){$q->whereNull('pp.effective_from')->orWhereDate('pp.effective_from','<=',now()->toDateString());})
   ->where(function($q){$q->whereNull('pp.effective_to')->orWhereDate('pp.effective_to','>=',now()->toDateString());})
   ->where(function($q)use($org){$q->whereNull('unit.organization_id')->orWhere('unit.organization_id',$org);})
   ->select(['pp.id','pp.product_id','pp.unit_id','pp.name_ar','unit.name_ar as unit_name','pp.conversion_to_base','pp.barcode_primary','pp.is_default_sale_unit'])
   ->orderByDesc('pp.is_default_sale_unit')->orderBy('pp.conversion_to_base')->get()->groupBy('product_id');
  $products->getCollection()->transform(function($p)use($packagings){$p->quantity_base=(float)$p->quantity_base;$p->packagings=($packagings->get($p->id)??collect())->values();return $p;});
  return response()->json($products);
 }
 public function expensesForTrip(Request $request){
  $data=$request->validate(['trip_id'=>['required','string','size:26']]);
  $user=$request->user();$org=$user->organization_id;
  abort_unless(DB::table('trips')->where('organization_id',$org)->where('id',$data['trip_id'])->where('rep_user_id',$user->id)->whereNotIn('status',['completed','cancelled'])->exists(),404);
  return response()->json(DB::table('expenses as e')->join('financial_accounts as fa',function($join){$join->on('fa.id','=','e.financial_account_id')->on('fa.organization_id','=','e.organization_id');})
   ->where('e.organization_id',$org)->where('e.trip_id',$data['trip_id'])->where('e.status','posted')->orderByDesc('e.expense_date')->orderByDesc('e.created_at')->limit(50)
   ->get(['e.id','e.category','e.amount','e.expense_date','e.notes','fa.name as financial_account_name']));
 }
 public function stocktakeForTrip(Request $request){
  $data=$request->validate(['trip_id'=>['required','string','size:26']]);
  $user=$request->user();$org=$user->organization_id;
  $trip=DB::table('trips as t')->join('vehicles as v',function($join){$join->on('v.id','=','t.vehicle_id')->on('v.organization_id','=','t.organization_id');})
   ->where('t.organization_id',$org)->where('t.id',$data['trip_id'])->where('t.rep_user_id',$user->id)->whereNotIn('t.status',['completed','cancelled'])->select('t.id','v.location_id')->first();
  abort_unless($trip,404);
  $products=DB::table('products as p')->join('stock_balances as s',function($join)use($org,$trip){$join->on('s.product_id','=','p.id')->where('s.organization_id','=',$org)->where('s.location_id','=',$trip->location_id);})
   ->leftJoin('units as u','u.id','=','p.base_unit_id')->where('p.organization_id',$org)->where('p.active',true)->select(['p.id as product_id','p.sku','p.name_ar','u.name_ar as base_unit_name','s.quantity_base as expected_quantity_base'])->orderBy('p.name_ar')->limit(1000)->get();
  $products->transform(function($product){$product->expected_quantity_base=(float)$product->expected_quantity_base;return $product;});
  $history=DB::table('field_stock_counts')->where('organization_id',$org)->where('trip_id',$trip->id)->orderByDesc('created_at')->limit(10)->get(['id','status','item_count','variance_items_count','items_json','notes','submitted_at','created_at'])->map(function($row){$row->items=json_decode($row->items_json,true)??[];unset($row->items_json);return $row;});
  return response()->json(['trip_id'=>$trip->id,'items'=>$products,'history'=>$history]);
 }
 public function submitStocktake(Request $request){
  $data=$request->validate(['trip_id'=>['required','string','size:26'],'idempotency_key'=>['required','string','max:255'],'items'=>['required','array','min:1','max:1000'],'items.*.product_id'=>['required','string','size:26','distinct'],'items.*.counted_quantity_base'=>['required','integer','gte:0','max:1000000000'],'notes'=>['nullable','string','max:2000']]);
  $user=$request->user();$org=$user->organization_id;
  $trip=DB::table('trips as t')->join('vehicles as v',function($join){$join->on('v.id','=','t.vehicle_id')->on('v.organization_id','=','t.organization_id');})
   ->where('t.organization_id',$org)->where('t.id',$data['trip_id'])->where('t.rep_user_id',$user->id)->whereNotIn('t.status',['completed','cancelled'])->select('t.id','v.location_id')->first();
  abort_unless($trip,404);
  $canonicalItems=collect($data['items'])->map(fn($item)=>['product_id'=>(string)$item['product_id'],'counted_quantity_base'=>(float)$item['counted_quantity_base']])->sortBy('product_id')->values()->all();
  $payloadHash=hash('sha256',json_encode(['trip_id'=>(string)$data['trip_id'],'notes'=>$data['notes']??null,'items'=>$canonicalItems],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  $existing=DB::table('field_stock_counts')->where('organization_id',$org)->where('idempotency_key',$data['idempotency_key'])->first();
  if($existing){
   if((string)$existing->trip_id!==(string)$trip->id||!hash_equals((string)$existing->payload_hash,$payloadHash))throw \Illuminate\Validation\ValidationException::withMessages(['idempotency_key'=>['مفتاح الجرد مستخدم لبيانات مختلفة؛ راجع نتيجة المحاولة السابقة قبل إعادة الإرسال.']]);
   return response()->json(['id'=>$existing->id,'trip_id'=>$existing->trip_id,'status'=>$existing->status,'item_count'=>$existing->item_count,'variance_items_count'=>$existing->variance_items_count,'items'=>json_decode($existing->items_json,true)??[],'submitted_at'=>$existing->submitted_at],200);
  }
  $productIds=collect($data['items'])->pluck('product_id')->unique()->values();
  $balances=DB::table('stock_balances as s')->join('products as p',function($join){$join->on('p.id','=','s.product_id')->on('p.organization_id','=','s.organization_id');})
   ->leftJoin('units as u','u.id','=','p.base_unit_id')->where('s.organization_id',$org)->where('s.location_id',$trip->location_id)->whereIn('s.product_id',$productIds)->select(['s.product_id','s.quantity_base','p.sku','p.name_ar','u.name_ar as base_unit_name'])->get()->keyBy('product_id');
  $rows=[];$varianceCount=0;
  foreach($data['items'] as $item){
   $balance=$balances->get($item['product_id']);
   if(!$balance)throw \Illuminate\Validation\ValidationException::withMessages(['items'=>['كل صنف في الجرد لازم يكون من مخزون العربية المسندة للرحلة. حدّث المخزون ثم أعد المراجعة.']]);
   $expected=(float)$balance->quantity_base;$counted=(float)$item['counted_quantity_base'];$variance=round($counted-$expected,6);
   if(abs($variance)>0.000001)$varianceCount++;
   $rows[]=['product_id'=>$balance->product_id,'sku'=>$balance->sku,'product_name'=>$balance->name_ar,'base_unit_name'=>$balance->base_unit_name,'expected_quantity_base'=>$expected,'counted_quantity_base'=>$counted,'variance_quantity_base'=>$variance];
  }
  $id=(string)Str::ulid();$now=now();
  DB::table('field_stock_counts')->insert(['id'=>$id,'organization_id'=>$org,'trip_id'=>$trip->id,'submitted_by'=>$user->id,'status'=>'submitted','idempotency_key'=>$data['idempotency_key'],'payload_hash'=>$payloadHash,'item_count'=>count($rows),'variance_items_count'=>$varianceCount,'items_json'=>json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'notes'=>$data['notes']??null,'submitted_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
  return response()->json(['id'=>$id,'trip_id'=>$trip->id,'status'=>'submitted','item_count'=>count($rows),'variance_items_count'=>$varianceCount,'items'=>$rows,'submitted_at'=>$now->toISOString()],201);
 }
 public function today(Request $request){
  $org=$request->user()->organization_id;$uid=$request->user()->id;$date=now()->toDateString();
  $trip=DB::table('trips as t')->join('vehicles as v',function($join){$join->on('v.id','=','t.vehicle_id')->on('v.organization_id','=','t.organization_id');})->where('t.organization_id',$org)->where('t.rep_user_id',$uid)->whereDate('t.trip_date',$date)->whereNotIn('t.status',['completed','cancelled'])->select('t.*','v.name as vehicle_name','v.code as vehicle_code','v.location_id as vehicle_location_id')
   // Prefer a route with assigned customer stops over a newer empty draft. A
   // rep opening today's field screen must see the route that has actual work.
   ->orderByRaw('EXISTS (SELECT 1 FROM trip_customers tc WHERE tc.organization_id = t.organization_id AND tc.trip_id = t.id) DESC')
   ->orderByDesc('t.created_at')->first();
  if(!$trip)return response()->json(['trip'=>null,'customers'=>[],'stock'=>[]]);
  $customers=DB::table('trip_customers as tc')->join('customers as c',function($join){$join->on('c.id','=','tc.customer_id')->on('c.organization_id','=','tc.organization_id');})->leftJoin('customer_addresses as ca',function($j){$j->on('ca.customer_id','=','c.id')->on('ca.organization_id','=','c.organization_id')->where('ca.is_primary',true)->where('ca.active',true);})
   ->where('tc.organization_id',$org)->where('tc.trip_id',$trip->id)->select('tc.id as assignment_id','tc.sequence','tc.visit_status','c.id','c.code','c.name','c.phone','c.address_text','ca.latitude','ca.longitude')->orderBy('tc.sequence')->get();
  $location=DB::table('vehicles')->where('organization_id',$org)->where('id',$trip->vehicle_id)->value('location_id');
  // Never expose purchase cost or a global/default selling price to a field rep.
  // The sale price is chosen for each customer on the sale invoice itself.
  $stock=DB::table('stock_balances as s')->join('products as p',function($join){$join->on('p.id','=','s.product_id')->on('p.organization_id','=','s.organization_id');})->where('s.organization_id',$org)->where('s.location_id',$location)->where('p.active',true)->select('p.id','p.sku','p.name_ar','p.name_en','s.quantity_base')->orderBy('p.name_ar')->limit(1000)->get();
  return response()->json(['trip'=>$trip,'customers'=>$customers,'stock'=>$stock]);
 }
 public function visit(Request $request){
  $data=$request->validate(['trip_id'=>['required','string','size:26'],'customer_id'=>['required','string','size:26'],'status'=>['required','in:checked_in,visited,skipped'],'latitude'=>['nullable','numeric','between:-90,90'],'longitude'=>['nullable','numeric','between:-180,180'],'notes'=>['nullable','string']]);
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
  $data=$request->validate(['trip_id'=>['required','string','size:26'],'name'=>['required','string','max:255'],'phone'=>['nullable','string','max:32'],'address_text'=>['nullable','string','max:2000'],'latitude'=>['nullable','numeric','between:-90,90'],'longitude'=>['nullable','numeric','between:-180,180']]);
  $user=$request->user();$org=(string)$user->organization_id;
  $trip=DB::table('trips')->where('organization_id',$org)->where('id',$data['trip_id'])->where('rep_user_id',$user->id)->whereNotIn('status',['completed','cancelled'])->first();
  abort_unless($trip,404,'الرحلة غير مسندة لحسابك أو لم تعد نشطة.');
  $customer=DB::transaction(function()use($data,$user,$org,$trip){
   $id=(string)Str::ulid();$now=now();$name=trim($data['name']);
   DB::table('customers')->insert(['id'=>$id,'organization_id'=>$org,'code'=>'CUS-'.strtoupper(Str::random(8)),'name'=>$name,'normalized_name'=>mb_strtolower($name,'UTF-8'),'phone'=>isset($data['phone'])?trim($data['phone']):null,'address_text'=>$data['address_text']??null,'credit_limit'=>0,'payment_terms_days'=>0,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
   if(isset($data['latitude'],$data['longitude']))DB::table('customer_addresses')->insert(['id'=>(string)Str::ulid(),'organization_id'=>$org,'customer_id'=>$id,'label'=>'field','address_text'=>$data['address_text']??null,'latitude'=>$data['latitude'],'longitude'=>$data['longitude'],'is_primary'=>true,'active'=>true,'created_at'=>$now,'updated_at'=>$now]);
   $sequence=((int)DB::table('trip_customers')->where('organization_id',$org)->where('trip_id',$trip->id)->max('sequence'))+1;
   DB::table('trip_customers')->insert(['id'=>(string)Str::ulid(),'organization_id'=>$org,'trip_id'=>$trip->id,'customer_id'=>$id,'sequence'=>$sequence,'planned'=>false,'visit_status'=>'checked_in','created_at'=>$now,'updated_at'=>$now]);
   DB::table('customer_visits')->insert(['id'=>(string)Str::ulid(),'organization_id'=>$org,'trip_id'=>$trip->id,'customer_id'=>$id,'user_id'=>$user->id,'status'=>'checked_in','check_in_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
   return DB::table('customers')->where('organization_id',$org)->where('id',$id)->first();
  });
  return response()->json(['customer'=>$customer,'sequence'=>(int)DB::table('trip_customers')->where('organization_id',$org)->where('trip_id',$trip->id)->where('customer_id',$customer->id)->value('sequence'),'visit_status'=>'checked_in'],201);
 }
}
