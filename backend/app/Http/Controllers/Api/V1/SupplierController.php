<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
class SupplierController extends Controller{
 public function index(Request $request){
  $q=trim((string)$request->query('q',''));
  $filters=$request->validate(['status'=>['sometimes','in:active,inactive,all']]);
  $status=$filters['status']??'active';
  $query=DB::table('suppliers')->where('organization_id',$request->user()->organization_id)
   ->when($status==='active',fn($w)=>$w->where('active',true))
   ->when($status==='inactive',fn($w)=>$w->where('active',false));
  if($q!=='')$query->where(fn($w)=>$w->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('phone','like','%'.$q.'%'));
  return response()->json($query->select(['id','name','code','phone','address','tax_identifier','credit_terms_days','active'])->selectRaw("CASE WHEN active = 1 THEN 'active' ELSE 'inactive' END as status")->orderBy('name')->limit(100)->get()->map(function($supplier){$supplier->address_text=$supplier->address;$supplier->tax_number=$supplier->tax_identifier;return $supplier;}));
 }

 public function destroy(Request $request,string $supplierId){
  $org=(string)$request->user()->organization_id;
  return DB::transaction(function()use($org,$supplierId){
   $supplier=DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->lockForUpdate()->first();
   abort_unless($supplier,404);
   if(!(bool)$supplier->active)return response()->json(['id'=>$supplierId,'status'=>'archived','message'=>'المورد مؤرشف بالفعل.']);
   // Preserve every purchase invoice and payment: UI deletion is a reversible archive.
   DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->update(['active'=>false,'updated_at'=>now()]);
   return response()->json(['id'=>$supplierId,'status'=>'archived','message'=>'تم حذف المورد من القائمة النشطة مع الاحتفاظ بالفواتير والمدفوعات التاريخية.']);
  });
 }

 public function restore(Request $request,string $supplierId){
  $org=(string)$request->user()->organization_id;
  $exists=DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->exists();
  abort_unless($exists,404);
  DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->update(['active'=>true,'updated_at'=>now()]);
  return response()->json(['id'=>$supplierId,'status'=>'active','message'=>'تمت استعادة المورد إلى القائمة النشطة.']);
 }

 public function show360(Request $request,string $supplierId){
  $org=$request->user()->organization_id;
  $filters=$request->validate([
   'per_page'=>['sometimes','integer','min:10','max:50'],
   'invoices_page'=>['sometimes','integer','min:1'],
   'payments_page'=>['sometimes','integer','min:1'],
   'returns_page'=>['sometimes','integer','min:1'],
  ]);
  $perPage=(int)($filters['per_page']??25);
  $supplier=DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->first();
  abort_unless($supplier,404);

  $invoiceBase=DB::table('purchase_invoices')->where('purchase_invoices.organization_id',$org)->where('purchase_invoices.supplier_id',$supplierId)->where('purchase_invoices.status','posted');
  $purchaseReturnsTotal=(float)DB::table('purchase_returns')->where('organization_id',$org)->where('supplier_id',$supplierId)->where('status','posted')->sum('total');
  $purchaseReturnsPayable=(float)DB::table('purchase_returns')->where('organization_id',$org)->where('supplier_id',$supplierId)->where('status','posted')->sum('payable_amount');
  $purchasesTotal=(float)(clone $invoiceBase)->sum('total');
  $purchasePayablesTotal=(float)(clone $invoiceBase)->sum('payable_amount');
  $paidOnInvoices=(float)(clone $invoiceBase)->sum('paid_amount');
  $supplierPayments=(float)DB::table('payments')->where('organization_id',$org)->where('party_type','supplier')->where('party_id',$supplierId)->where('direction','outbound')->where('status','posted')->sum('amount');
  $allocatedSupplierPayments=(float)DB::table('payment_allocations as allocation')
   ->join('payments as payment',function($join){$join->on('payment.id','=','allocation.payment_id')->on('payment.organization_id','=','allocation.organization_id');})
   ->where('allocation.organization_id',$org)->where('allocation.document_type','purchase_invoice')
   ->where('payment.party_type','supplier')->where('payment.party_id',$supplierId)->where('payment.direction','outbound')->where('payment.status','posted')->sum('allocation.amount');
  $sellThrough=DB::table('trip_supplier_settlements')->where('organization_id',$org)->where('supplier_id',$supplierId)
   ->whereIn('settlement_basis',['sold_goods','mixed'])
   ->selectRaw('COALESCE(SUM(sold_quantity_base), 0) as sold_quantity_base')
   ->selectRaw('COALESCE(SUM(returned_quantity_base), 0) as returned_quantity_base')
   ->selectRaw('COALESCE(SUM(net_quantity_base), 0) as net_quantity_base')
   ->selectRaw('COALESCE(SUM(sold_source_cost), 0) as sold_source_cost')
   ->selectRaw('COALESCE(SUM(returned_source_cost), 0) as returned_source_cost')
   ->selectRaw('COALESCE(SUM(settlement_amount), 0) as settlement_amount')->first();
  $sellThroughPayments=(float)DB::table('payments as payment')
   ->join('trip_supplier_settlements as settlement',function($join){$join->on('settlement.id','=','payment.trip_supplier_settlement_id')->on('settlement.organization_id','=','payment.organization_id');})
   ->where('payment.organization_id',$org)->where('settlement.supplier_id',$supplierId)
   ->whereIn('settlement.settlement_basis',['sold_goods','mixed'])
   ->whereIn('settlement.settlement_basis',['sold_goods','mixed'])
   ->where('payment.party_type','supplier')->where('payment.party_id',$supplierId)->where('payment.direction','outbound')->where('payment.status','posted')->sum('payment.amount');
  $unallocatedSupplierPayments=max(0,$supplierPayments-$allocatedSupplierPayments-$sellThroughPayments);

  $invoices=(clone $invoiceBase)->leftJoin('locations as location',function($join){$join->on('location.id','=','purchase_invoices.location_id')->on('location.organization_id','=','purchase_invoices.organization_id');})
   ->orderByDesc('purchase_invoices.invoice_date')->orderByDesc('purchase_invoices.created_at')
   ->paginate($perPage,['purchase_invoices.id','purchase_invoices.document_number','purchase_invoices.supplier_invoice_number','purchase_invoices.invoice_date','purchase_invoices.total','purchase_invoices.payable_amount','purchase_invoices.paid_amount','purchase_invoices.currency','purchase_invoices.created_at','location.name as location_name'],'invoices_page',(int)($filters['invoices_page']??1));

  $invoices->getCollection()->transform(function($invoice)use($org){
   $returned=(float)DB::table('purchase_returns')->where('organization_id',$org)->where('original_purchase_invoice_id',$invoice->id)->where('status','posted')->sum('total');
   $returnedPayable=(float)DB::table('purchase_returns')->where('organization_id',$org)->where('original_purchase_invoice_id',$invoice->id)->where('status','posted')->sum('payable_amount');
   $invoice->payable_amount=(float)($invoice->payable_amount??$invoice->total);
   $invoice->returned_total=$returned;$invoice->returned_payable_total=$returnedPayable;
   $invoice->remaining_payable=max(0,$invoice->payable_amount-(float)$invoice->paid_amount-$returnedPayable);return $invoice;
  });

  $returns=DB::table('purchase_returns as return_doc')
   ->leftJoin('purchase_invoices as original',function($join){$join->on('original.id','=','return_doc.original_purchase_invoice_id')->on('original.organization_id','=','return_doc.organization_id');})
   ->where('return_doc.organization_id',$org)->where('return_doc.supplier_id',$supplierId)->where('return_doc.status','posted')
   ->orderByDesc('return_doc.return_date')->orderByDesc('return_doc.created_at')
   ->paginate($perPage,['return_doc.id','return_doc.document_number','return_doc.return_date','return_doc.total','return_doc.payable_amount','return_doc.currency','return_doc.original_purchase_invoice_id','original.document_number as original_invoice_number'],'returns_page',(int)($filters['returns_page']??1));

  $payments=DB::table('payments')->where('organization_id',$org)->where('party_type','supplier')->where('party_id',$supplierId)->where('direction','outbound')->where('status','posted')
   ->orderByDesc('payment_date')->orderByDesc('created_at')->paginate($perPage,['id','amount','currency','payment_date','reference','created_at'],'payments_page',(int)($filters['payments_page']??1));

  return response()->json(['supplier'=>$supplier,'summary'=>[
   'invoice_count'=>(clone $invoiceBase)->count(),'purchases_total'=>$purchasesTotal,'paid_on_invoices'=>$paidOnInvoices,
   'payments_total'=>$supplierPayments,'returns_total'=>$purchaseReturnsTotal,
   'invoice_payable_total'=>$purchasePayablesTotal,
   'open_balance'=>max(0,$purchasePayablesTotal-$paidOnInvoices-$purchaseReturnsPayable-$unallocatedSupplierPayments),
   'sell_through'=>[
    'sold_quantity_base'=>(float)$sellThrough->sold_quantity_base,
    'returned_quantity_base'=>(float)$sellThrough->returned_quantity_base,
    'net_quantity_base'=>(float)$sellThrough->net_quantity_base,
    'sold_source_cost'=>(float)$sellThrough->sold_source_cost,
    'returned_source_cost'=>(float)$sellThrough->returned_source_cost,
    'settlement_amount'=>(float)$sellThrough->settlement_amount,
    'paid_amount'=>$sellThroughPayments,
    'open_amount'=>max(0,(float)$sellThrough->settlement_amount-$sellThroughPayments),
   ],
   'last_purchase_at'=>(clone $invoiceBase)->max('invoice_date'),
   'last_payment_at'=>DB::table('payments')->where('organization_id',$org)->where('party_type','supplier')->where('party_id',$supplierId)->where('direction','outbound')->where('status','posted')->max('payment_date'),
  ],'invoices'=>$invoices,'returns'=>$returns,'payments'=>$payments]);
 }

 public function rebates(Request $request,string $supplierId){
  $org=(string)$request->user()->organization_id;
  abort_unless(DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->exists(),404);
  if (!Schema::hasTable('supplier_rebates') || !Schema::hasTable('supplier_rebate_invoices')) {
   return response()->json([]);
  }
  $rows=DB::table('supplier_rebates as rebate')
   ->leftJoin('users as creator',function($join){$join->on('creator.id','=','rebate.created_by')->on('creator.organization_id','=','rebate.organization_id');})
   ->where('rebate.organization_id',$org)->where('rebate.supplier_id',$supplierId)
   ->orderByDesc('rebate.created_at')
   ->get(['rebate.id','rebate.document_number','rebate.period_from','rebate.period_to','rebate.currency','rebate.invoice_count','rebate.calculation_base','rebate.rate_percent','rebate.rebate_amount','rebate.status','rebate.notes','rebate.created_at','creator.name as created_by_name'])
   ->map(static function($row)use($org){
    $row->invoice_numbers=DB::table('supplier_rebate_invoices')->where('organization_id',$org)->where('supplier_rebate_id',$row->id)->orderBy('invoice_date_snapshot')->pluck('invoice_number_snapshot')->all();
    $row->calculation_base=(float)$row->calculation_base;
    $row->rate_percent=(float)$row->rate_percent;
    $row->rebate_amount=(float)$row->rebate_amount;
    $row->invoice_count=(int)$row->invoice_count;
    return $row;
   });
  return response()->json($rows);
 }

 public function storeRebate(Request $request,string $supplierId){
  $org=(string)$request->user()->organization_id;
  abort_unless(Schema::hasTable('supplier_rebates') && Schema::hasTable('supplier_rebate_invoices'),503,'تحديث مخطط قاعدة المعاينة مطلوب لتسجيل خصم المورد. لم يتم تسجيل أي تغيير.');
  $data=$request->validate([
   'period_from'=>['required','date_format:Y-m-d'],
   'period_to'=>['required','date_format:Y-m-d','after_or_equal:period_from'],
   'rate_percent'=>['required','numeric','gt:0','max:100'],
   'notes'=>['nullable','string','max:2000'],
  ]);

  $result=DB::transaction(function()use($org,$supplierId,$data,$request){
   $supplier=DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->lockForUpdate()->first();
   abort_unless($supplier,404);

   // Lock eligible invoices and exclude any invoice previously used by a rebate.
   // Use each invoice's final total less the posted returns linked to it at claim time.
   $invoices=DB::table('purchase_invoices')
    ->where('organization_id',$org)->where('supplier_id',$supplierId)->where('status','posted')
    ->whereBetween('invoice_date',[$data['period_from'],$data['period_to']])
    ->whereNotExists(function($query)use($org){
     $query->selectRaw('1')->from('supplier_rebate_invoices as claimed')
      ->whereColumn('claimed.purchase_invoice_id','purchase_invoices.id')
      ->where('claimed.organization_id',$org);
    })
    ->select(['id','document_number','invoice_date','total','currency'])
    ->selectSub(DB::table('purchase_returns as returned')
     ->selectRaw('COALESCE(SUM(returned.total), 0)')
     ->whereColumn('returned.organization_id','purchase_invoices.organization_id')
     ->whereColumn('returned.original_purchase_invoice_id','purchase_invoices.id')
     ->where('returned.status','posted'),'return_total_snapshot')
    ->orderBy('invoice_date')->orderBy('document_number')->lockForUpdate()->get();

   if($invoices->isEmpty()){
    throw \Illuminate\Validation\ValidationException::withMessages(['period_from'=>['لا توجد فواتير شراء مرحلة وغير مستخدمة في خصم سابق داخل الفترة المحددة.']]);
   }
   if($invoices->pluck('currency')->unique()->count()!==1){
    throw \Illuminate\Validation\ValidationException::withMessages(['period_to'=>['الفترة تحتوي على فواتير بعملات مختلفة؛ افصل كل عملة في تسوية مستقلة بعد مراجعة المحاسب.']]);
   }

   $base='0.0000';
   foreach($invoices as $invoice){
    $netInvoice=bcsub((string)$invoice->total,(string)$invoice->return_total_snapshot,4);
    if(bccomp($netInvoice,'0.0000',4)<0){$netInvoice='0.0000';}
    $base=bcadd($base,$netInvoice,4);
   }
   $rate=number_format((float)$data['rate_percent'],4,'.','');
   $amount=bcdiv(bcmul($base,$rate,8),'100',4);
   if(bccomp($base,'0.0000',4)<=0||bccomp($amount,'0.0000',4)<=0){
    throw \Illuminate\Validation\ValidationException::withMessages(['rate_percent'=>['قيمة الفواتير أو الخصم المحسوب لا تسمح بإنشاء رصيد خصم موجب.']]);
   }

   $id=(string)Str::ulid();
   $now=now();
   $documentNumber='SR-'.$now->format('Ymd').'-'.strtoupper(substr($id,-10));
   DB::table('supplier_rebates')->insert([
    'id'=>$id,'organization_id'=>$org,'supplier_id'=>$supplierId,'document_number'=>$documentNumber,
    'period_from'=>$data['period_from'],'period_to'=>$data['period_to'],'currency'=>$invoices->first()->currency,
    'invoice_count'=>$invoices->count(),'calculation_base'=>$base,'rate_percent'=>$rate,'rebate_amount'=>$amount,
    'status'=>'accrued','notes'=>$data['notes']??null,'created_by'=>$request->user()->id,
    'created_at'=>$now,'updated_at'=>$now,
   ]);
   foreach($invoices as $invoice){
    DB::table('supplier_rebate_invoices')->insert([
     'id'=>(string)Str::ulid(),'organization_id'=>$org,'supplier_rebate_id'=>$id,'purchase_invoice_id'=>$invoice->id,
     'invoice_number_snapshot'=>$invoice->document_number,'invoice_date_snapshot'=>$invoice->invoice_date,
     'invoice_total_snapshot'=>$invoice->total,'return_total_snapshot'=>$invoice->return_total_snapshot,
     'created_at'=>$now,'updated_at'=>$now,
    ]);
   }

   return [
    'id'=>$id,'document_number'=>$documentNumber,'supplier_id'=>$supplierId,
    'period_from'=>$data['period_from'],'period_to'=>$data['period_to'],'currency'=>$invoices->first()->currency,
    'invoice_count'=>$invoices->count(),'calculation_base'=>(float)$base,'rate_percent'=>(float)$rate,
    'rebate_amount'=>(float)$amount,'status'=>'accrued','notes'=>$data['notes']??null,
    'invoice_numbers'=>$invoices->pluck('document_number')->all(),
    'accounting_note'=>'رصيد خصم مستحق مسجل للمراجعة؛ لم يُسجل قبض نقدي ولم يتم خصمه تلقائيًا من مديونية فواتير المورد أو قيود الأستاذ.',
   ];
  },attempts:3);

  return response()->json($result,201);
 }

 public function tripSettlements(Request $request,string $supplierId){
  $org=(string)$request->user()->organization_id;
  abort_unless(DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->exists(),404);
  $paid=DB::table('payments')->where('organization_id',$org)->where('party_type','supplier')->where('party_id',$supplierId)->where('direction','outbound')->where('status','posted')
   ->whereNotNull('trip_supplier_settlement_id')->select('trip_supplier_settlement_id')->selectRaw('COALESCE(SUM(amount),0) as paid_amount')->groupBy('trip_supplier_settlement_id');
  $rows=DB::table('trip_supplier_settlements as settlement')
   ->join('trips as trip',function($join){$join->on('trip.id','=','settlement.trip_id')->on('trip.organization_id','=','settlement.organization_id');})
   ->leftJoinSub($paid,'settlement_paid',function($join){$join->on('settlement_paid.trip_supplier_settlement_id','=','settlement.id');})
   ->where('settlement.organization_id',$org)->where('settlement.supplier_id',$supplierId)
   ->orderByDesc('trip.trip_date')->orderByDesc('settlement.created_at')
  ->get(['settlement.id','settlement.trip_id','trip.trip_number','trip.trip_date','settlement.sold_quantity_base','settlement.returned_quantity_base','settlement.net_quantity_base','settlement.sold_source_cost','settlement.returned_source_cost','settlement.settlement_amount','settlement.deferred_source_cost','settlement.settlement_basis','settlement.items_json','settlement_paid.paid_amount'])
   ->map(function($row){
    $row->paid_amount=(float)($row->paid_amount??0);
    $row->settlement_amount=(float)$row->settlement_amount;
    $row->deferred_source_cost=(float)($row->deferred_source_cost??0);
    $row->is_payable=in_array((string)($row->settlement_basis??'legacy_source_cost'),['sold_goods','mixed'],true);
    // Historical rows are retained for audit/source-cost viewing, not treated as
    // an additional supplier payable over the original purchase invoice.
    $row->outstanding_amount=$row->is_payable?max(0,$row->settlement_amount-$row->paid_amount):0.0;
    $row->sold_quantity_base=(float)$row->sold_quantity_base;
    $row->returned_quantity_base=(float)$row->returned_quantity_base;
    $row->net_quantity_base=(float)$row->net_quantity_base;
    $row->items=json_decode((string)$row->items_json,true)?:[];
    unset($row->items_json);
    return $row;
   });
  return response()->json($rows);
 }

 public function products(Request $request,string $supplierId){
  $org=(string)$request->user()->organization_id;
  abort_unless(DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->exists(),404);
  $filters=$request->validate(['q'=>['nullable','string','max:120'],'limit'=>['sometimes','integer','min:1','max:500']]);
  $query=DB::table('products as product')
   ->leftJoin('supplier_products as mapping',function($join)use($org,$supplierId){
    $join->on('mapping.product_id','=','product.id')->on('mapping.organization_id','=','product.organization_id')
     ->where('mapping.supplier_id','=',$supplierId);
   })
   ->where('product.organization_id',$org)->where('product.active',true);
  $q=trim((string)($filters['q']??''));
  if($q!=='')$query->where(fn($w)=>$w->where('product.name_ar','like','%'.$q.'%')->orWhere('product.sku','like','%'.$q.'%'));
  $rows=$query->select(['product.id as product_id','product.sku','product.name_ar','product.base_unit_id','mapping.id as supplier_product_id','mapping.settlement_unit_price'])
   ->selectRaw("COALESCE(mapping.settlement_method,'purchase_debt') as settlement_method")
   ->orderBy('product.name_ar')->limit((int)($filters['limit']??300))->get();
  return response()->json(['supplier_id'=>$supplierId,'products'=>$rows,'effective_for_future_purchases_only'=>true,'settlement_methods'=>[
   ['value'=>'purchase_debt','label'=>'المستحق عند الشراء'],
   ['value'=>'sold_goods','label'=>'المستحق حسب الكمية المباعة بعد إقفال الرحلة'],
  ]]);
 }

 public function updateProductSettlement(Request $request,string $supplierId,string $productId){
  $org=(string)$request->user()->organization_id;
  abort_unless(DB::table('suppliers')->where('organization_id',$org)->where('id',$supplierId)->exists(),404);
  abort_unless(DB::table('products')->where('organization_id',$org)->where('id',$productId)->exists(),404);
  $data=$request->validate([
   'settlement_method'=>['required','in:purchase_debt,sold_goods'],
   'settlement_unit_price'=>['nullable','numeric','min:0','max:9999999999999.9999'],
  ]);
  $now=now();
  $mapping=DB::table('supplier_products')->where('organization_id',$org)->where('supplier_id',$supplierId)->where('product_id',$productId)->first();
  $values=[
   'settlement_method'=>$data['settlement_method'],
   'settlement_unit_price'=>$data['settlement_method']==='sold_goods'&&array_key_exists('settlement_unit_price',$data)&&$data['settlement_unit_price']!==null?$data['settlement_unit_price']:null,
   'updated_at'=>$now,
  ];
  if($mapping){DB::table('supplier_products')->where('organization_id',$org)->where('id',$mapping->id)->update($values);$mappingId=$mapping->id;}
  else{
   $mappingId=(string)Str::ulid();
   DB::table('supplier_products')->insert(array_merge($values,[
    'id'=>$mappingId,'organization_id'=>$org,'supplier_id'=>$supplierId,'product_id'=>$productId,
    'supplier_sku'=>null,'supplier_name'=>null,'preferred_packaging_id'=>null,'last_cost'=>null,'last_purchase_at'=>null,
    'active'=>true,'created_at'=>$now,
   ]));
  }
  return response()->json(['id'=>$mappingId,'supplier_id'=>$supplierId,'product_id'=>$productId,
   'settlement_method'=>$values['settlement_method'],'settlement_unit_price'=>$values['settlement_unit_price'],
   'effective_for_future_purchases_only'=>true,'note'=>'تم حفظ سياسة المورد للصنف. الفواتير المرحلة تحتفظ بنسخة السياسة والسعر كما كانا وقت الشراء.']);
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
