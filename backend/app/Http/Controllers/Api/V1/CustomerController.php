<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $q=trim((string)$request->query('q',''));
        $query=DB::table('customers')->where('organization_id',$request->user()->organization_id)->where('status','active');
        if($q!=='') $query->where(function($w)use($q){$w->where('name','like','%'.$q.'%')->orWhere('phone','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%');});
        return response()->json($query->orderBy('name')->limit(50)->get());
    }

    public function store(Request $request)
    {
        $org = $request->user()->organization_id;

        $data=$request->validate([
            'name'=>['required','string','max:255'],
            'phone'=>['nullable','string','max:32'],
            'code'=>[
                'nullable',
                'string',
                'max:64',
                Rule::unique('customers', 'code')->where(fn ($q) => $q->where('organization_id', $org)),
            ],
            'governorate_id'=>['nullable','string','size:26'],
            'center_id'=>['nullable','string','size:26'],
            'city_area_id'=>['nullable','string','size:26'],
            'address_text'=>['nullable','string'],
            'latitude'=>['nullable','numeric','between:-90,90'],
            'longitude'=>['nullable','numeric','between:-180,180'],
            'credit_limit'=>['nullable','numeric','min:0'],
            'payment_terms_days'=>['nullable','integer','min:0'],
            'notes'=>['nullable','string'],
        ]);

        $id=(string)Str::ulid();
        $code=$data['code']??'CUS-'.strtoupper(Str::random(8));

        DB::table('customers')->insert([
            'id'=>$id,'organization_id'=>$org,'code'=>$code,
            'name'=>$data['name'],'normalized_name'=>mb_strtolower(trim($data['name']),'UTF-8'),
            'phone'=>$data['phone']??null,'alternate_phone'=>null,'governorate_id'=>$data['governorate_id']??null,
            'center_id'=>$data['center_id']??null,'city_area_id'=>$data['city_area_id']??null,
            'address_text'=>$data['address_text']??null,'credit_limit'=>$data['credit_limit']??0,
            'payment_terms_days'=>$data['payment_terms_days']??0,'status'=>'active','notes'=>$data['notes']??null,
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        if(isset($data['latitude'],$data['longitude'])){
            DB::table('customer_addresses')->insert([
                'id'=>(string)Str::ulid(),'organization_id'=>$org,'customer_id'=>$id,
                'label'=>'primary','governorate_id'=>$data['governorate_id']??null,'center_id'=>$data['center_id']??null,
                'city_area_id'=>$data['city_area_id']??null,'address_text'=>$data['address_text']??null,
                'latitude'=>$data['latitude'],'longitude'=>$data['longitude'],'is_primary'=>true,'active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }

        return response()->json(DB::table('customers')->where('id',$id)->first(),201);
    }
}
