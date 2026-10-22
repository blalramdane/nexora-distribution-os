<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TripController extends Controller
{
    private function number(string $org,string $type):string
    {
        $row=DB::table('document_sequences')->where('organization_id',$org)->where('document_type',$type)->where('active',true)->lockForUpdate()->first();
        if(!$row){$id=(string)Str::ulid();DB::table('document_sequences')->insert(['id'=>$id,'organization_id'=>$org,'document_type'=>$type,'prefix'=>strtoupper($type),'next_number'=>2,'padding'=>6,'reset_policy'=>'never','active'=>true,'created_at'=>now(),'updated_at'=>now()]);return strtoupper($type).'-000001';}
        DB::table('document_sequences')->where('id',$row->id)->update(['next_number'=>$row->next_number+1,'updated_at'=>now()]);
        return ($row->prefix?$row->prefix.'-':'').str_pad((string)$row->next_number,(int)$row->padding,'0',STR_PAD_LEFT);
    }

    private function routeCustomers(string $org,string $trip): \Illuminate\Support\Collection
    {
        return DB::table('trip_customers as tc')
            ->join('customers as c',function($join){$join->on('c.id','=','tc.customer_id')->on('c.organization_id','=','tc.organization_id');})
            ->leftJoin('customer_addresses as ca',function($join){
                $join->on('ca.customer_id','=','c.id')->on('ca.organization_id','=','c.organization_id')->where('ca.is_primary',true)->where('ca.active',true);
            })
            ->where('tc.organization_id',$org)->where('tc.trip_id',$trip)
            ->select('tc.id as trip_customer_id','tc.customer_id','tc.sequence','tc.visit_status','c.code','c.name','c.phone','c.address_text','c.governorate_id','c.center_id','c.city_area_id','ca.latitude','ca.longitude','ca.address_text as primary_address')
            ->orderBy('tc.sequence')->get();
    }

    public function index(Request $request)
    {
        $org=$request->user()->organization_id;
        return response()->json(DB::table('trips as t')->join('vehicles as v',function($join){$join->on('v.id','=','t.vehicle_id')->on('v.organization_id','=','t.organization_id');})->join('users as u',function($join){$join->on('u.id','=','t.rep_user_id')->on('u.organization_id','=','t.organization_id');})->where('t.organization_id',$org)->when($request->boolean('archived_only'),fn($query)=>$query->whereNotNull('t.archived_at'))->when(!$request->boolean('include_archived')&&!$request->boolean('archived_only'),fn($query)=>$query->whereNull('t.archived_at'))->select('t.*','v.name as vehicle_name','v.code as vehicle_code','v.location_id as vehicle_location_id','u.name as rep_name')->orderByDesc('t.trip_date')->limit(100)->get());
    }

    public function vehicles(Request $request){return response()->json(DB::table('vehicles')->where('organization_id',$request->user()->organization_id)->where('active',true)->orderBy('name')->get());}

    public function store(Request $request)
    {
        $data=$request->validate(['vehicle_id'=>['required','string','size:26'],'rep_user_id'=>['required','string','size:26'],'origin_location_id'=>['required','string','size:26'],'trip_date'=>['nullable','date'],'display_name'=>['nullable','string','max:160'],'notes'=>['nullable','string','max:5000']]);
        $org=$request->user()->organization_id;
        foreach ([
            'vehicle_id' => ['vehicles', $data['vehicle_id']],
            'rep_user_id' => ['users', $data['rep_user_id']],
            'origin_location_id' => ['locations', $data['origin_location_id']],
        ] as $field => [$table, $id]) {
            if (!DB::table($table)->where('id', $id)->where('organization_id', $org)->exists()) {
                throw ValidationException::withMessages([$field => ['The selected record does not belong to this organization.']]);
            }
        }
        if(!DB::table('locations')->where('organization_id',$org)->where('id',$data['origin_location_id'])->where('status','active')->where('type','warehouse')->exists()){
            throw ValidationException::withMessages(['origin_location_id'=>['نقطة الانطلاق لازم تكون مخزنًا نشطًا، وليس موقع عربية.']]);
        }
        $trip=null;
        DB::transaction(function()use(&$trip,$data,$org){$tripId=(string)Str::ulid();$trip=['id'=>$tripId,'organization_id'=>$org,'trip_number'=>$this->number($org,'trip'),'display_name'=>trim((string)($data['display_name']??''))?:null,'vehicle_id'=>$data['vehicle_id'],'rep_user_id'=>$data['rep_user_id'],'status'=>'planned','trip_date'=>$data['trip_date']??now()->toDateString(),'origin_location_id'=>$data['origin_location_id'],'notes'=>$data['notes']??null,'created_at'=>now(),'updated_at'=>now()];DB::table('trips')->insert($trip);});
        return response()->json($trip,201);
    }

    public function update(Request $request,string $trip)
    {
        $data=$request->validate(['display_name'=>['sometimes','nullable','string','max:160'],'origin_location_id'=>['sometimes','required','string','size:26']]);
        $org=(string)$request->user()->organization_id;
        $row=DB::table('trips')->where('organization_id',$org)->where('id',$trip)->first();
        abort_unless($row,404);
        $updates=['updated_at'=>now()];
        if(array_key_exists('display_name',$data))$updates['display_name']=trim((string)($data['display_name']??''))?:null;
        if(array_key_exists('origin_location_id',$data)){
            if(!in_array($row->status,['planned','ready'],true)||DB::table('trip_loads')->where('organization_id',$org)->where('trip_id',$trip)->exists())throw ValidationException::withMessages(['origin_location_id'=>['لا يمكن تغيير مخزن الانطلاق بعد بدء تحميل العربية.']]);
            $source=DB::table('locations')->where('organization_id',$org)->where('id',$data['origin_location_id'])->where('status','active')->where('type','warehouse')->first();
            if(!$source)throw ValidationException::withMessages(['origin_location_id'=>['اختار مخزنًا نشطًا من نوع مخزن؛ مواقع العربيات لا تصلح كمخزن انطلاق.']]);
            $updates['origin_location_id']=$source->id;
        }
        DB::table('trips')->where('organization_id',$org)->where('id',$trip)->update($updates);
        return response()->json(['id'=>$trip,'display_name'=>$updates['display_name']??$row->display_name,'origin_location_id'=>$updates['origin_location_id']??$row->origin_location_id,'message'=>array_key_exists('origin_location_id',$data)?'تم حفظ مخزن الانطلاق.':'تم تحديث اسم الرحلة.']);
    }

    public function destroy(Request $request,string $trip)
    {
        $org=(string)$request->user()->organization_id;
        return DB::transaction(function()use($org,$trip){
            $row=DB::table('trips')->where('organization_id',$org)->where('id',$trip)->lockForUpdate()->first();
            abort_unless($row,404);
            if($row->archived_at!==null)return response()->json(['id'=>$trip,'status'=>'archived','message'=>'الرحلة مؤرشفة بالفعل.']);
            DB::table('trips')->where('organization_id',$org)->where('id',$trip)->update(['archived_at'=>now(),'updated_at'=>now()]);
            return response()->json(['id'=>$trip,'status'=>'archived','message'=>'تم إخفاء الرحلة من القائمة النشطة مع الاحتفاظ بالفواتير وحركات المخزون والتسويات.']);
        });
    }

    public function archiveAll(Request $request)
    {
        $request->validate(['confirm'=>['required','accepted']]);
        $org=(string)$request->user()->organization_id;
        $count=DB::table('trips')->where('organization_id',$org)->whereNull('archived_at')->update(['archived_at'=>now(),'updated_at'=>now()]);
        return response()->json(['status'=>'archived','archived_count'=>$count,'message'=>$count
            ? "تم إخفاء {$count} رحلة من التشغيل الحالي. الفواتير والمدفوعات وحركات المخزون والتسويات محفوظة، ويمكن استعادة الرحلات من الأرشيف."
            : 'لا توجد رحلات نشطة تحتاج إلى أرشفة.']);
    }

    public function restore(Request $request,string $trip)
    {
        $org=(string)$request->user()->organization_id;
        $row=DB::table('trips')->where('organization_id',$org)->where('id',$trip)->first();
        abort_unless($row,404);
        DB::table('trips')->where('organization_id',$org)->where('id',$trip)->update(['archived_at'=>null,'updated_at'=>now()]);
        return response()->json(['id'=>$trip,'status'=>'active','message'=>'تمت استعادة الرحلة إلى قائمة التشغيل.']);
    }

    public function cleanupEmpty(Request $request)
    {
        $org=(string)$request->user()->organization_id;
        $request->validate(['confirm'=>['required','accepted']]);
        return DB::transaction(function()use($org){
            $trips=DB::table('trips')->where('organization_id',$org)->whereNull('archived_at')->where('status','planned')->lockForUpdate()->get();
            $deleted=[];$skipped=[];
            foreach($trips as $trip){
                $reason=$this->tripDeletionBlockReason($org,$trip);
                if($reason!==null){$skipped[]=['id'=>$trip->id,'trip_number'=>$trip->trip_number,'display_name'=>$trip->display_name??null,'reason'=>$reason];continue;}
                DB::table('trips')->where('organization_id',$org)->where('id',$trip->id)->delete();
                $deleted[]=['id'=>$trip->id,'trip_number'=>$trip->trip_number,'display_name'=>$trip->display_name??null];
            }
            return response()->json([
                'deleted_count'=>count($deleted),'skipped_count'=>count($skipped),'deleted'=>$deleted,'skipped'=>$skipped,
                'message'=>count($deleted)?'تم حذف '.count($deleted).' رحلة مخططة فارغة. الرحلات التي عليها حركات تم الاحتفاظ بها.':'لم تُحذف أي رحلة. '.(count($skipped)?'الرحلات المخططة المتبقية مرتبطة بحركات أو مستندات.':'لا توجد رحلات مخططة فارغة.'),
            ]);
        });
    }

    private function tripDeletionBlockReason(string $org,object $trip): ?string
    {
        if($trip->status!=='planned')return 'لا يمكن حذف رحلة بدأت أو أُغلقت. احتفظ بها كسجل تاريخي لحماية الحسابات والمخزون.';
        $dependencies=[
            'stock_movements'=>'حركات المخزون','sales_invoices'=>'فواتير البيع','sales_returns'=>'مرتجعات البيع',
            'payments'=>'التحصيلات والمدفوعات','expenses'=>'المصروفات','trip_customers'=>'العملاء وخط السير',
            'customer_visits'=>'سجل الزيارات','trip_loads'=>'مستندات تحميل العربية','trip_settlements'=>'التسويات',
            'trip_supplier_settlements'=>'تسويات الموردين','cashbox_transactions'=>'حركات الخزنة','trip_expenses'=>'ربط المصروفات',
        ];
        foreach($dependencies as $table=>$label){
            if(!\Illuminate\Support\Facades\Schema::hasTable($table)||!\Illuminate\Support\Facades\Schema::hasColumn($table,'trip_id'))continue;
            $query=DB::table($table)->where('trip_id',$trip->id);
            if(\Illuminate\Support\Facades\Schema::hasColumn($table,'organization_id'))$query->where('organization_id',$org);
            if($query->exists())return "لا يمكن حذف الرحلة لوجود {$label} مرتبطة بها. تم الاحتفاظ بها لحماية السجلات المالية والمخزنية.";
        }
        return null;
    }

    public function stock(Request $request,string $trip)
    {
        $org=(string)$request->user()->organization_id;
        $row=DB::table('trips')->where('organization_id',$org)->where('id',$trip)->first();
        abort_unless($row,404);
        $location=DB::table('vehicles')->where('organization_id',$org)->where('id',$row->vehicle_id)->value('location_id');

        // Closed trips must show the reconciled closing count, not the vehicle's
        // mutable stock after later journeys. Otherwise include both the trip's
        // opening snapshot and movements so carried-over items remain visible.
        $settlement=DB::table('trip_settlements')->where('organization_id',$org)->where('trip_id',$trip)->first();
        if ($row->status === 'completed' && $settlement) {
            return response()->json(DB::table('trip_settlement_lines as line')
                ->join('products as p',function($join){$join->on('p.id','=','line.product_id')->on('p.organization_id','=','line.organization_id');})
                ->where('line.organization_id',$org)->where('line.trip_settlement_id',$settlement->id)
                ->select('p.id as product_id','p.sku','p.name_ar','line.actual_closing_quantity_base as quantity_base')
                ->selectRaw('0 as average_cost')->orderBy('p.name_ar')->get());
        }

        $productIds=DB::table('stock_movements')->where('organization_id',$org)->where('trip_id',$trip)->distinct()->pluck('product_id');
        $snapshot=DB::table('trip_stock_opening_snapshots')->where('organization_id',$org)->where('trip_id',$trip)->first();
        if ($snapshot) {
            $productIds=$productIds->merge(DB::table('trip_stock_opening_snapshot_items')->where('organization_id',$org)->where('snapshot_id',$snapshot->id)->pluck('product_id'));
        } elseif ($productIds->isEmpty()) {
            // Before the first trip movement, this is a read-only preview of the
            // candidate opening count. The immutable snapshot is created only
            // by the first successful stock posting (or final settlement).
            $productIds=DB::table('stock_balances')->where('organization_id',$org)->where('location_id',$location)->where('quantity_base','>',0)->pluck('product_id');
        }
        $productIds=$productIds->unique()->values();
        if ($productIds->isEmpty()) return response()->json([]);

        return response()->json(DB::table('products as p')
            ->leftJoin('stock_balances as s',function($join)use($org,$location){$join->on('s.product_id','=','p.id')->on('s.organization_id','=','p.organization_id')->where('s.location_id','=',$location);})
            ->where('p.organization_id',$org)->whereIn('p.id',$productIds)
            ->select('p.id as product_id','p.sku','p.name_ar','s.average_cost')
            ->selectRaw('COALESCE(s.quantity_base, 0) as quantity_base')
            ->orderBy('p.name_ar')->get());
    }

    public function profit(Request $request, string $trip)
    {
        $org = $request->user()->organization_id;
        $tripRow = DB::table('trips')->where('organization_id', $org)->where('id', $trip)->first();
        abort_unless($tripRow, 404);

        $sales = DB::table('sales_invoices')->where('organization_id', $org)->where('trip_id', $trip)->where('status', 'posted');
        $salesTotal = (float) (clone $sales)->sum('total');
        $salesCount = (int) (clone $sales)->count();
        $salesInvoiceIds = (clone $sales)->select('id');
        $cogs = (float) DB::table('sales_invoice_items as item')
            ->where('item.organization_id', $org)
            ->whereIn('item.sales_invoice_id', $salesInvoiceIds)
            ->selectRaw('COALESCE(SUM(item.unit_cost_snapshot * item.quantity_base), 0) as total')
            ->value('total');

        $returns = DB::table('sales_returns')->where('organization_id', $org)->where('trip_id', $trip)->where('status', 'posted');
        $returnsTotal = (float) (clone $returns)->sum('total');
        $returnsCount = (int) (clone $returns)->count();
        $returnIds = (clone $returns)->select('id');
        $returnedCogs = (float) DB::table('sales_return_items as item')
            ->where('item.organization_id', $org)
            ->whereIn('item.sales_return_id', $returnIds)
            ->selectRaw('COALESCE(SUM(item.unit_cost_snapshot * item.quantity_base), 0) as total')
            ->value('total');

        $expenses = DB::table('expenses')->where('organization_id', $org)->where('trip_id', $trip)->where('status', 'posted');
        $expenseTotal = (float) (clone $expenses)->sum('amount');
        $expenseRows = (clone $expenses)->orderBy('expense_date')->get(['id', 'category', 'amount', 'expense_date', 'notes']);

        $netSales = $salesTotal - $returnsTotal;
        $netCogs = max(0, $cogs - $returnedCogs);
        $grossProfit = $netSales - $netCogs;
        $netProfit = $grossProfit - $expenseTotal;

        return response()->json([
            'trip_id' => $tripRow->id,
            'trip_number' => $tripRow->trip_number,
            'currency' => strtoupper((string) DB::table('organizations')->where('id', $org)->value('default_currency')),
            'sales_count' => $salesCount,
            'sales_total' => round($salesTotal, 4),
            'returns_count' => $returnsCount,
            'returns_total' => round($returnsTotal, 4),
            'net_sales' => round($netSales, 4),
            'cost_of_goods_sold' => round($cogs, 4),
            'returned_cost' => round($returnedCogs, 4),
            'net_cost_of_goods_sold' => round($netCogs, 4),
            'gross_profit' => round($grossProfit, 4),
            'trip_expenses' => round($expenseTotal, 4),
            'net_trip_profit' => round($netProfit, 4),
            'expenses' => $expenseRows,
            'variance_policy_note' => 'فرق النقدية والمخزون يظهر في التسوية بشكل مستقل ولا يدخل في صافي الربح إلى أن تعتمد سياسة ترحيله محاسبيًا.',
            'costing_note' => 'تكلفة البضاعة مبنية على متوسط التكلفة المثبت وقت البيع/المرتجع؛ لا يوجد حتى الآن توزيع تكلفة كل بيع على فاتورة مورد بعينها.',
        ]);
    }

    public function detailedReport(Request $request, string $trip)
    {
        $org = (string) $request->user()->organization_id;
        $tripRow = DB::table('trips as t')
            ->join('vehicles as v', function ($join): void { $join->on('v.id', '=', 't.vehicle_id')->on('v.organization_id', '=', 't.organization_id'); })
            ->join('users as u', function ($join): void { $join->on('u.id', '=', 't.rep_user_id')->on('u.organization_id', '=', 't.organization_id'); })
            ->where('t.organization_id', $org)->where('t.id', $trip)
            ->first(['t.*', 'v.name as vehicle_name', 'v.plate_number', 'u.name as rep_name']);
        abort_unless($tripRow, 404);

        $cashLedgerAccountId = DB::table('ledger_accounts')->where('organization_id', $org)->where('code', '1000')->value('id');
        $invoices = DB::table('sales_invoices as si')
            ->join('customers as c', function ($join): void { $join->on('c.id', '=', 'si.customer_id')->on('c.organization_id', '=', 'si.organization_id'); })
            ->where('si.organization_id', $org)->where('si.trip_id', $trip)->where('si.status', 'posted')
            ->orderBy('si.invoice_date')->orderBy('si.created_at')
            ->select(['si.id', 'si.document_number', 'si.invoice_date', 'si.total', 'si.paid_amount', 'si.balance_due', 'si.currency', 'c.id as customer_id', 'c.name as customer_name', 'c.code as customer_code'])
            ->selectSub(DB::table('payments as at_sale')
                ->join('financial_accounts as at_sale_account', function ($join): void {
                    $join->on('at_sale_account.id', '=', 'at_sale.financial_account_id')
                        ->on('at_sale_account.organization_id', '=', 'at_sale.organization_id');
                })
                ->join('payment_methods as at_sale_method', function ($join) use ($org): void {
                    $join->on('at_sale_method.id', '=', 'at_sale.payment_method_id')
                        ->where(function ($query) use ($org): void {
                            $query->whereNull('at_sale_method.organization_id')->orWhere('at_sale_method.organization_id', $org);
                        });
                })
                ->where('at_sale.organization_id', $org)
                ->where('at_sale.source_document_type', 'sale_at_issue')
                ->whereColumn('at_sale.source_document_id', 'si.id')
                ->where('at_sale.status', 'posted')
                ->whereRaw("LOWER(at_sale_account.type) = 'cash'")
                ->where(function ($query): void {
                    $query->whereRaw("LOWER(at_sale_method.code) IN ('cash', 'cash_on_delivery', 'cash_payment')")
                        ->orWhereRaw("LOWER(at_sale_method.code) LIKE '%cash%'");
                })
                ->selectRaw('COALESCE(SUM(at_sale.amount),0)'), 'cash_at_sale_receipt')
            ->selectSub(DB::table('payments as at_sale')
                ->join('financial_accounts as at_sale_account', function ($join): void {
                    $join->on('at_sale_account.id', '=', 'at_sale.financial_account_id')
                        ->on('at_sale_account.organization_id', '=', 'at_sale.organization_id');
                })
                ->join('payment_methods as at_sale_method', function ($join) use ($org): void {
                    $join->on('at_sale_method.id', '=', 'at_sale.payment_method_id')
                        ->where(function ($query) use ($org): void {
                            $query->whereNull('at_sale_method.organization_id')->orWhere('at_sale_method.organization_id', $org);
                        });
                })
                ->where('at_sale.organization_id', $org)
                ->where('at_sale.source_document_type', 'sale_at_issue')
                ->whereColumn('at_sale.source_document_id', 'si.id')
                ->where('at_sale.status', 'posted')
                ->where(function ($query): void {
                    $query->whereRaw("LOWER(COALESCE(at_sale_account.type, '')) <> 'cash'")
                        ->orWhere(function ($methodQuery): void {
                            $methodQuery->whereRaw("LOWER(COALESCE(at_sale_method.code, '')) NOT IN ('cash', 'cash_on_delivery', 'cash_payment')")
                                ->whereRaw("LOWER(COALESCE(at_sale_method.code, '')) NOT LIKE '%cash%'");
                        });
                })
                ->selectRaw('COALESCE(SUM(at_sale.amount),0)'), 'non_cash_at_sale_receipt')
            ->selectSub(DB::table('payments as at_sale')->selectRaw('COALESCE(SUM(at_sale.amount),0)')
                ->where('at_sale.organization_id', $org)
                ->where('at_sale.source_document_type', 'sale_at_issue')
                ->whereColumn('at_sale.source_document_id', 'si.id')
                ->where('at_sale.status', 'posted'), 'paid_at_sale_receipt')
            ->selectSub(DB::table('ledger_entries')->selectRaw('COALESCE(SUM(debit - credit),0)')
                ->whereColumn('ledger_entries.source_document_id', 'si.id')->where('ledger_entries.source_document_type', 'sales_invoice')
                ->when($cashLedgerAccountId, fn ($query) => $query->where('ledger_entries.account_id', $cashLedgerAccountId)), 'cash_at_sale_legacy')
            ->get()
            ->map(static function (object $invoice): object {
                // New invoices use an auditable receipt against their actual
                // financial account. Keep the old generic cash-ledger lookup as
                // a fallback for invoices posted before sale-at-issue receipts.
                $paidAtIssue = (float) ($invoice->paid_at_sale_receipt ?? 0);
                $hasNewReceipt = $paidAtIssue > 0;
                $invoice->cash_at_sale = $hasNewReceipt
                    ? (float) ($invoice->cash_at_sale_receipt ?? 0)
                    : (float) ($invoice->cash_at_sale_legacy ?? 0);
                $invoice->non_cash_at_sale = $hasNewReceipt ? (float) ($invoice->non_cash_at_sale_receipt ?? 0) : 0.0;
                $invoice->paid_at_sale = $hasNewReceipt
                    ? $paidAtIssue
                    : (float) ($invoice->cash_at_sale_legacy ?? 0);
                $invoice->credit_at_sale = max(0, (float) $invoice->total - (float) $invoice->paid_at_sale);
                unset($invoice->cash_at_sale_receipt, $invoice->non_cash_at_sale_receipt, $invoice->paid_at_sale_receipt, $invoice->cash_at_sale_legacy);
                return $invoice;
            });
        $invoiceIds = $invoices->pluck('id')->all();
        $salesTotal = (float) $invoices->sum(fn ($row) => (float) $row->total);
        $salesCash = (float) $invoices->sum(fn ($row) => (float) $row->cash_at_sale);
        $salesNonCash = (float) $invoices->sum(fn ($row) => (float) $row->non_cash_at_sale);
        $salesCreditAtSale = (float) $invoices->sum(fn ($row) => (float) $row->credit_at_sale);

        $returns = DB::table('sales_returns as sr')
            ->join('customers as c', function ($join): void { $join->on('c.id', '=', 'sr.customer_id')->on('c.organization_id', '=', 'sr.organization_id'); })
            ->where('sr.organization_id', $org)->where('sr.trip_id', $trip)->where('sr.status', 'posted')
            ->orderBy('sr.return_date')->get(['sr.id', 'sr.document_number', 'sr.return_date', 'sr.total', 'sr.currency', 'c.name as customer_name']);
        $returnsTotal = (float) $returns->sum(fn ($row) => (float) $row->total);

        $loadedRows = DB::table('stock_movements as sm')
            ->join('products as p', function ($join): void { $join->on('p.id', '=', 'sm.product_id')->on('p.organization_id', '=', 'sm.organization_id'); })
            ->where('sm.organization_id', $org)->where('sm.trip_id', $trip)->where('sm.movement_type', 'vehicle_load')
            ->groupBy('p.id', 'p.sku', 'p.name_ar')
            ->select(['p.id as product_id', 'p.sku', 'p.name_ar'])
            ->selectRaw('COALESCE(SUM(ABS(sm.quantity_base)),0) as quantity_base')
            ->selectRaw('COALESCE(SUM(ABS(sm.quantity_base) * sm.unit_cost),0) as loaded_cost')
            ->get();
        $loadedValue = (float) $loadedRows->sum(fn ($row) => (float) $row->loaded_cost);

        $salesItems = $invoiceIds ? DB::table('sales_invoice_items as item')
            ->join('sales_invoices as si', function ($join): void { $join->on('si.id', '=', 'item.sales_invoice_id')->on('si.organization_id', '=', 'item.organization_id'); })
            ->join('products as p', function ($join): void { $join->on('p.id', '=', 'item.product_id')->on('p.organization_id', '=', 'item.organization_id'); })
            ->where('item.organization_id', $org)->whereIn('item.sales_invoice_id', $invoiceIds)
            ->groupBy('item.product_id', 'p.sku', 'p.name_ar')
            ->select(['item.product_id', 'p.sku', 'p.name_ar'])
            ->selectRaw('COALESCE(SUM(item.quantity_base),0) as sold_quantity_base')
            ->selectRaw('COALESCE(SUM(item.line_total),0) as sales_value')
            ->selectRaw('COALESCE(SUM(item.quantity_base * item.unit_cost_snapshot),0) as cost_value')
            ->get() : collect();

        // Count allocated receipts against only invoices belonging to this trip;
        // a single payment can be allocated across trips and must not be duplicated.
        $paymentQuery = DB::table('payments as p')
            ->join('customers as c', function ($join): void { $join->on('c.id', '=', 'p.party_id')->on('c.organization_id', '=', 'p.organization_id'); })
            ->join('financial_accounts as fa', function ($join): void { $join->on('fa.id', '=', 'p.financial_account_id')->on('fa.organization_id', '=', 'p.organization_id'); })
            ->join('payment_methods as pm', function ($join) use ($org): void {
                $join->on('pm.id', '=', 'p.payment_method_id')->where(function ($query) use ($org): void {
                    $query->whereNull('pm.organization_id')->orWhere('pm.organization_id', $org);
                });
            })
            ->where('p.organization_id', $org)->where('p.party_type', 'customer')->where('p.direction', 'inbound')->where('p.status', 'posted')
            ->where(function ($query): void {
                $query->whereNull('p.source_document_type')->orWhere('p.source_document_type', '<>', 'sale_at_issue');
            })
            ->where(function ($query) use ($org, $trip): void {
                $query->where('p.trip_id', $trip)->orWhereExists(function ($sub) use ($org, $trip): void {
                    $sub->selectRaw('1')->from('payment_allocations as pa')->join('sales_invoices as si', function ($join): void { $join->on('si.id', '=', 'pa.document_id')->on('si.organization_id', '=', 'pa.organization_id'); })
                        ->whereColumn('pa.payment_id', 'p.id')->where('pa.organization_id', $org)->where('pa.document_type', 'sales_invoice')->where('si.trip_id', $trip);
                });
            });
        $payments = (clone $paymentQuery)->orderBy('p.payment_date')->get([
            'p.id', 'p.payment_date', 'p.amount', 'p.reference', 'p.trip_id', 'c.name as customer_name',
            'fa.name as financial_account_name', 'fa.type as financial_account_type',
            'pm.name_ar as payment_method_name', 'pm.code as payment_method_code',
        ]);
        $collections = $payments->map(function ($payment) use ($org, $trip, $invoiceIds): array {
            $allocated = $invoiceIds ? (float) DB::table('payment_allocations')->where('organization_id', $org)->where('payment_id', $payment->id)->where('document_type', 'sales_invoice')->whereIn('document_id', $invoiceIds)->sum('amount') : 0.0;
            $hasAllocations = DB::table('payment_allocations')->where('organization_id', $org)->where('payment_id', $payment->id)->exists();
            $amountForTrip = (string) $payment->trip_id === $trip
                ? (float) $payment->amount
                : ($hasAllocations ? $allocated : 0.0);
            $methodCode = strtolower((string) $payment->payment_method_code);
            $isCash = strtolower((string) $payment->financial_account_type) === 'cash'
                && (in_array($methodCode, ['cash', 'cash_on_delivery', 'cash_payment'], true) || str_contains($methodCode, 'cash'));
            return ['id' => $payment->id, 'date' => $payment->payment_date, 'customer_name' => $payment->customer_name,
                'amount' => (float) $payment->amount, 'allocated_to_trip' => $amountForTrip,
                'cash_allocated_to_trip' => $isCash ? $amountForTrip : 0.0,
                'non_cash_allocated_to_trip' => $isCash ? 0.0 : $amountForTrip,
                'financial_account_name' => $payment->financial_account_name,
                'payment_method_name' => $payment->payment_method_name,
                'is_cash' => $isCash, 'reference' => $payment->reference];
        })->filter(fn ($row) => $row['allocated_to_trip'] > 0)->values();
        $collectionTotal = (float) $collections->sum('allocated_to_trip');
        $cashCollectionTotal = (float) $collections->sum('cash_allocated_to_trip');
        $nonCashCollectionTotal = (float) $collections->sum('non_cash_allocated_to_trip');

        $expenses = DB::table('expenses as e')
            ->leftJoin('financial_accounts as fa', function ($join): void { $join->on('fa.id', '=', 'e.financial_account_id')->on('fa.organization_id', '=', 'e.organization_id'); })
            ->leftJoin('vehicles as v', function ($join): void { $join->on('v.id', '=', 'e.vehicle_id')->on('v.organization_id', '=', 'e.organization_id'); })
            ->where('e.organization_id', $org)->where('e.trip_id', $trip)->where('e.status', 'posted')
            ->orderBy('e.expense_date')->get(['e.id', 'e.category', 'e.amount', 'e.expense_date', 'e.notes', 'fa.name as account_name', 'v.name as vehicle_name']);
        $expenseTotal = (float) $expenses->sum(fn ($row) => (float) $row->amount);

        $cashbox = DB::table('cashbox_transactions')->where('organization_id', $org)->where('trip_id', $trip)->where('status', 'posted')->orderBy('transaction_date')->get();
        $otherIncome = (float) $cashbox->where('transaction_type', 'other_income')->sum('amount');
        $otherOutflow = (float) $cashbox->whereIn('transaction_type', ['owner_withdrawal', 'salary_payment', 'other_expense'])->sum('amount');
        $cashboxOutflows = $cashbox->whereIn('transaction_type', ['owner_withdrawal', 'salary_payment', 'other_expense'])->values();
        $settlement = DB::table('trip_settlements')->where('organization_id', $org)->where('trip_id', $trip)->first();
        $openingItems = DB::table('trip_stock_opening_snapshot_items as item')
            ->join('products as p', function ($join): void { $join->on('p.id', '=', 'item.product_id')->on('p.organization_id', '=', 'item.organization_id'); })
            ->where('item.organization_id', $org)->where('item.trip_id', $trip)
            ->orderBy('p.name_ar')
            ->get(['item.product_id', 'p.sku', 'p.name_ar', 'item.opening_quantity_base as quantity_base', 'item.opening_average_cost as unit_cost']);
        $stockReconciliation = $settlement ? DB::table('trip_settlement_lines as line')
            ->join('products as p', function ($join): void { $join->on('p.id', '=', 'line.product_id')->on('p.organization_id', '=', 'line.organization_id'); })
            ->where('line.organization_id', $org)->where('line.trip_settlement_id', $settlement->id)
            ->orderBy('p.name_ar')
            ->get(['line.product_id', 'p.sku', 'p.name_ar', 'line.opening_quantity_base', 'line.loaded_quantity_base',
                'line.sold_quantity_base', 'line.returned_quantity_base', 'line.transferred_quantity_base', 'line.adjustment_quantity_base',
                'line.expected_closing_quantity_base', 'line.actual_closing_quantity_base', 'line.variance_quantity_base'])
            : collect();
        $openingCash = (float) ($settlement->opening_cash ?? 0);
        $expectedCash = $openingCash + $salesCash + $cashCollectionTotal + $otherIncome - $expenseTotal - $otherOutflow;
        if ($settlement) $expectedCash = (float) $settlement->expected_cash;

        return response()->json([
            'trip' => ['id' => $tripRow->id, 'trip_number' => $tripRow->trip_number, 'display_name' => $tripRow->display_name, 'status' => $tripRow->status,
                'trip_date' => $tripRow->trip_date, 'vehicle_name' => $tripRow->vehicle_name, 'plate_number' => $tripRow->plate_number,
                'rep_name' => $tripRow->rep_name, 'currency' => strtoupper((string) (DB::table('organizations')->where('id', $org)->value('default_currency') ?: 'EGP'))],
            'summary' => ['loaded_value' => $loadedValue, 'sales_total' => $salesTotal, 'sales_cash' => $salesCash,
                'sales_non_cash' => $salesNonCash, 'credit_at_sale' => $salesCreditAtSale,
                'returns_total' => $returnsTotal, 'net_sales' => $salesTotal - $returnsTotal,
                'collections_after_sale' => $collectionTotal, 'cash_collections_after_sale' => $cashCollectionTotal,
                'non_cash_collections_after_sale' => $nonCashCollectionTotal,
                'receivables_open_on_trip_invoices' => (float) $invoices->sum(fn ($row) => (float) $row->balance_due),
                'trip_expenses' => $expenseTotal, 'other_income' => $otherIncome, 'other_cash_outflow' => $otherOutflow,
                'opening_cash' => $openingCash, 'expected_cash' => $expectedCash,
                'actual_cash' => $settlement ? (float) $settlement->actual_cash : null,
                'cash_variance' => $settlement ? (float) $settlement->cash_variance : null,
                'stock_variance_value' => $settlement ? (float) $settlement->stock_variance_value : null,
                'customer_count' => $invoices->pluck('customer_id')->unique()->count(), 'invoice_count' => $invoices->count()],
            'loaded_items' => $loadedRows, 'sold_items' => $salesItems,
            'opening_items' => $openingItems,
            'stock_reconciliation' => $stockReconciliation,
            'sales_invoices' => $invoices, 'sales_returns' => $returns, 'customer_collections' => $collections,
            'expenses' => $expenses, 'other_cash_movements' => $cashboxOutflows,
            'supplier_settlements' => DB::table('trip_supplier_settlements')->where('organization_id', $org)->where('trip_id', $trip)->get(),
            'settlement' => $settlement ? [
                'opening_cash' => $settlement->opening_cash,
                'expected_cash' => $settlement->expected_cash,
                'actual_cash' => $settlement->actual_cash,
                'cash_variance' => $settlement->cash_variance,
                'stock_variance_value' => $settlement->stock_variance_value,
                'opening_stock_basis' => $settlement->opening_stock_basis ?? 'legacy_zero',
            ] : null,
        ]);
    }

    public function assignCustomer(Request $request,string $trip)
    {
        $data=$request->validate(['customer_id'=>['required','string','size:26'],'sequence'=>['nullable','integer','min:1']]);$org=$request->user()->organization_id;
        abort_unless(DB::table('trips')->where('id',$trip)->where('organization_id',$org)->exists(),404);
        abort_unless(DB::table('customers')->where('id',$data['customer_id'])->where('organization_id',$org)->exists(),404);
        $sequence=$data['sequence']??null;
        DB::transaction(function()use($trip,$data,$org,&$sequence){
            $existing=DB::table('trip_customers')->where('trip_id',$trip)->where('customer_id',$data['customer_id'])->lockForUpdate()->first();
            if($existing){$sequence=$existing->sequence;return;}
            if($sequence===null){$sequence=((int)DB::table('trip_customers')->where('trip_id',$trip)->lockForUpdate()->max('sequence'))+1;}
            DB::table('trip_customers')->insert(['id'=>(string)Str::ulid(),'organization_id'=>$org,'trip_id'=>$trip,'customer_id'=>$data['customer_id'],'sequence'=>$sequence,'planned'=>true,'visit_status'=>'planned','created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['status'=>'assigned','sequence'=>$sequence]);
    }

    public function route(Request $request,string $trip)
    {
        $org=$request->user()->organization_id;
        abort_unless(DB::table('trips')->where('id',$trip)->where('organization_id',$org)->exists(),404);
        return response()->json($this->routeCustomers($org,$trip));
    }

    public function optimizeRoute(Request $request,string $trip)
    {
        $data=$request->validate(['start_customer_id'=>['nullable','string','size:26']]);
        $org=$request->user()->organization_id;
        abort_unless(DB::table('trips')->where('id',$trip)->where('organization_id',$org)->exists(),404);

        $rows=$this->routeCustomers($org,$trip);
        if($rows->isEmpty()) return response()->json(['optimized_count'=>0,'without_coordinates'=>0,'customers'=>[]]);

        $withCoordinates=$rows->filter(fn($row)=>$row->latitude !== null && $row->longitude !== null)->values();
        $withoutCoordinates=$rows->filter(fn($row)=>$row->latitude === null || $row->longitude === null)->values();

        if($withCoordinates->count()<2){
            return response()->json(['optimized_count'=>$withCoordinates->count(),'without_coordinates'=>$withoutCoordinates->count(),'customers'=>$rows]);
        }

        $startId=$data['start_customer_id'] ?? null;
        $start=$startId ? $withCoordinates->firstWhere('customer_id',$startId) : $withCoordinates->sortBy('sequence')->first();
        if(!$start) throw ValidationException::withMessages(['start_customer_id'=>['Start customer is not assigned to this trip or has no coordinates.']]);

        $remaining=$withCoordinates->reject(fn($row)=>$row->customer_id===$start->customer_id)->values();
        $ordered=collect([$start]);
        $current=$start;

        while($remaining->isNotEmpty()){
            $next=$remaining->sortBy(fn($row)=>$this->distanceKm((float)$current->latitude,(float)$current->longitude,(float)$row->latitude,(float)$row->longitude))->first();
            $ordered->push($next);
            $remaining=$remaining->reject(fn($row)=>$row->customer_id===$next->customer_id)->values();
            $current=$next;
        }

        DB::transaction(function()use($ordered,$withoutCoordinates,$org,$trip){
            $sequence=1;
            foreach($ordered->concat($withoutCoordinates) as $row){
                DB::table('trip_customers')->where('organization_id',$org)->where('trip_id',$trip)->where('customer_id',$row->customer_id)->update(['sequence'=>$sequence++,'updated_at'=>now()]);
            }
        });

        return response()->json([
            'optimized_count'=>$ordered->count(),
            'without_coordinates'=>$withoutCoordinates->count(),
            'customers'=>$this->routeCustomers($org,$trip),
        ]);
    }

    public function reorderRoute(Request $request,string $trip)
    {
        $data=$request->validate(['customer_ids'=>['required','array','min:1'],'customer_ids.*'=>['required','string','size:26']]);
        $org=$request->user()->organization_id;
        abort_unless(DB::table('trips')->where('id',$trip)->where('organization_id',$org)->exists(),404);

        DB::transaction(function()use($data,$org,$trip){
            $assigned=DB::table('trip_customers')->where('organization_id',$org)->where('trip_id',$trip)->pluck('customer_id')->map(fn($id)=>(string)$id)->sort()->values()->all();
            $requested=collect($data['customer_ids'])->map(fn($id)=>(string)$id)->sort()->values()->all();
            if($assigned !== $requested) throw ValidationException::withMessages(['customer_ids'=>['The order must contain every assigned customer exactly once.']]);
            foreach($data['customer_ids'] as $index=>$customerId){
                DB::table('trip_customers')->where('organization_id',$org)->where('trip_id',$trip)->where('customer_id',$customerId)->update(['sequence'=>$index+1,'updated_at'=>now()]);
            }
        });

        return response()->json(['status'=>'reordered','customers'=>$this->routeCustomers($org,$trip)]);
    }

    private function distanceKm(float $lat1,float $lon1,float $lat2,float $lon2):float
    {
        $earth=6371.0;
        $dLat=deg2rad($lat2-$lat1);
        $dLon=deg2rad($lon2-$lon1);
        $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;
        return 2*$earth*asin(min(1,sqrt($a)));
    }
}
