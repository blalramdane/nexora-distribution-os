<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogController extends Controller
{
    public function products(Request $request)
    {
        $q=trim((string)$request->query('q',''));
        $query=DB::table('products')->where('organization_id',$request->user()->organization_id)->where('active',true);
        if($q!==''){
            $query->where(function($w)use($q){
                $w->where('sku','like','%'.$q.'%')
                  ->orWhere('name_ar','like','%'.$q.'%')
                  ->orWhere('name_en','like','%'.$q.'%')
                  ->orWhereIn('id',DB::table('product_aliases')->select('product_id')->where('normalized_alias','like','%'.$q.'%'));
            });
        }
        return response()->json($query->orderBy('name_ar')->limit(100)->get());
    }

    public function meta(Request $request)
    {
        $org=$request->user()->organization_id;
        return response()->json([
            'units'=>DB::table('units')->where(fn($q)=>$q->whereNull('organization_id')->orWhere('organization_id',$org))->where('active',true)->orderBy('name_ar')->get(),
            'categories'=>DB::table('categories')->where('organization_id',$org)->where('active',true)->orderBy('name_ar')->get(),
        ]);
    }

    public function storeProduct(Request $request)
    {
        $data=$request->validate([
            'sku'=>['required','string','max:128'],
            'name_ar'=>['required','string','max:255'],
            'name_en'=>['nullable','string','max:255'],
            'category_id'=>['nullable','string','size:26'],
            'base_unit_id'=>['required','string','size:26'],
            'brand'=>['nullable','string','max:255'],
            'default_cost'=>['nullable','numeric','min:0'],
            'default_piece_price'=>['nullable','numeric','min:0'],
        ]);
        $org=$request->user()->organization_id;
        if (DB::table('products')->where('organization_id',$org)->where('sku',$data['sku'])->exists()) {
            return response()->json(['message'=>'SKU مستخدم بالفعل داخل الشركة.'],422);
        }
        $unit=DB::table('units')->where('id',$data['base_unit_id'])->where('active',true)->where(fn($q)=>$q->whereNull('organization_id')->orWhere('organization_id',$org))->first();
        if(!$unit) return response()->json(['message'=>'وحدة القياس غير صحيحة.'],422);
        if(!empty($data['category_id']) && !DB::table('categories')->where('organization_id',$org)->where('id',$data['category_id'])->where('active',true)->exists()){
            return response()->json(['message'=>'التصنيف غير صحيح.'],422);
        }

        $id=(string)Str::ulid();
        DB::table('products')->insert([
            'id'=>$id,'organization_id'=>$org,'active'=>true,
            ...$data,'default_cost'=>$data['default_cost']??0,'default_piece_price'=>$data['default_piece_price']??0,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return response()->json(DB::table('products')->where('id',$id)->first(),201);
    }
}
