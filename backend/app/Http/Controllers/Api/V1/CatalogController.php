<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
        return response()->json($query->orderBy('name_ar')->limit(50)->get());
    }

    public function references(Request $request)
    {
        $org = $request->user()->organization_id;

        return response()->json([
            'units' => DB::table('units')
                ->where(function ($q) use ($org) {
                    $q->whereNull('organization_id')->orWhere('organization_id', $org);
                })
                ->where('active', true)
                ->orderBy('name_ar')
                ->get(['id', 'code', 'name_ar', 'name_en', 'precision']),
            'categories' => DB::table('categories')
                ->where('organization_id', $org)
                ->where('active', true)
                ->orderBy('name_ar')
                ->get(['id', 'code', 'name_ar', 'name_en', 'parent_id']),
        ]);
    }

    public function storeProduct(Request $request)
    {
        $org = $request->user()->organization_id;

        $data=$request->validate([
            'sku'=>[
                'required',
                'string',
                'max:128',
                Rule::unique('products', 'sku')->where(fn ($q) => $q->where('organization_id', $org)),
            ],
            'name_ar'=>['required','string','max:255'],
            'name_en'=>['nullable','string','max:255'],
            'category_id'=>[
                'nullable',
                'string',
                'size:26',
                Rule::exists('categories', 'id')->where(fn ($q) => $q->where('organization_id', $org)->where('active', true)),
            ],
            'base_unit_id'=>[
                'required',
                'string',
                'size:26',
                Rule::exists('units', 'id')->where(fn ($q) => $q->where('active', true)->where(function ($w) use ($org) {
                    $w->whereNull('organization_id')->orWhere('organization_id', $org);
                })),
            ],
            'brand'=>['nullable','string','max:255'],
            'default_cost'=>['nullable','numeric','min:0'],
            'default_piece_price'=>['nullable','numeric','min:0'],
        ]);

        $id=(string)Str::ulid();
        DB::table('products')->insert([
            'id'=>$id,'organization_id'=>$org,'active'=>true,
            ...$data,'default_cost'=>$data['default_cost']??0,'default_piece_price'=>$data['default_piece_price']??0,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return response()->json(DB::table('products')->where('id',$id)->first(),201);
    }
}
