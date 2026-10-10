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
        return response()->json(DB::table('trips as t')->join('vehicles as v',function($join){$join->on('v.id','=','t.vehicle_id')->on('v.organization_id','=','t.organization_id');})->join('users as u',function($join){$join->on('u.id','=','t.rep_user_id')->on('u.organization_id','=','t.organization_id');})->where('t.organization_id',$org)->select('t.*','v.name as vehicle_name','v.code as vehicle_code','u.name as rep_name')->orderByDesc('t.trip_date')->limit(100)->get());
    }

    public function vehicles(Request $request){return response()->json(DB::table('vehicles')->where('organization_id',$request->user()->organization_id)->where('active',true)->orderBy('name')->get());}

    public function store(Request $request)
    {
        $data=$request->validate(['vehicle_id'=>['required','string','size:26'],'rep_user_id'=>['required','string','size:26'],'origin_location_id'=>['required','string','size:26'],'trip_date'=>['nullable','date'],'notes'=>['nullable','string']]);
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
        $trip=null;
        DB::transaction(function()use(&$trip,$data,$org){$tripId=(string)Str::ulid();$trip=['id'=>$tripId,'organization_id'=>$org,'trip_number'=>$this->number($org,'trip'),'vehicle_id'=>$data['vehicle_id'],'rep_user_id'=>$data['rep_user_id'],'status'=>'planned','trip_date'=>$data['trip_date']??now()->toDateString(),'origin_location_id'=>$data['origin_location_id'],'notes'=>$data['notes']??null,'created_at'=>now(),'updated_at'=>now()];DB::table('trips')->insert($trip);});
        return response()->json($trip,201);
    }

    public function stock(Request $request,string $trip)
    {
        $org=$request->user()->organization_id;
        $row=DB::table('trips')->where('organization_id',$org)->where('id',$trip)->first();
        abort_unless($row,404);
        $location=DB::table('vehicles')->where('organization_id',$org)->where('id',$row->vehicle_id)->value('location_id');
        $productIds=DB::table('stock_movements')->where('organization_id',$org)->where('trip_id',$trip)->distinct()->pluck('product_id');
        return response()->json(DB::table('stock_balances as s')->join('products as p',function($join){$join->on('p.id','=','s.product_id')->on('p.organization_id','=','s.organization_id');})->where('s.organization_id',$org)->where('s.location_id',$location)->whereIn('s.product_id',$productIds)->select('p.id as product_id','p.sku','p.name_ar','s.quantity_base','s.average_cost')->orderBy('p.name_ar')->get());
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
