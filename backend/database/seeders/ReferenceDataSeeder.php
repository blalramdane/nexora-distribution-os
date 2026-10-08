<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['piece','قطعة','Piece',0],
            ['carton','كرتونة','Carton',0],
            ['meter','متر','Meter',2],
            ['kilogram','كيلو','Kilogram',3],
            ['box','علبة','Box',0],
        ];

        foreach ($units as [$code,$ar,$en,$precision]) {
            DB::table('units')->insertOrIgnore([
                'id'=>(string) Str::ulid(),
                'organization_id'=>null,
                'code'=>$code,
                'name_ar'=>$ar,
                'name_en'=>$en,
                'precision'=>$precision,
                'active'=>true,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            DB::table('units')
                ->whereNull('organization_id')
                ->where('code',$code)
                ->update([
                    'name_ar'=>$ar,
                    'name_en'=>$en,
                    'precision'=>$precision,
                    'active'=>true,
                    'updated_at'=>now(),
                ]);
        }

        $methods = [
            ['cash','نقدي','Cash',false],
            ['bank_transfer','تحويل بنكي','Bank transfer',true],
            ['postal','بريد','Postal',true],
            ['cheque','شيك','Cheque',true],
            ['other','أخرى','Other',true],
        ];

        foreach ($methods as [$code,$ar,$en,$ref]) {
            DB::table('payment_methods')->insertOrIgnore([
                'id'=>(string) Str::ulid(),
                'organization_id'=>null,
                'code'=>$code,
                'name_ar'=>$ar,
                'name_en'=>$en,
                'requires_reference'=>$ref,
                'active'=>true,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            DB::table('payment_methods')
                ->whereNull('organization_id')
                ->where('code',$code)
                ->update([
                    'name_ar'=>$ar,
                    'name_en'=>$en,
                    'requires_reference'=>$ref,
                    'active'=>true,
                    'updated_at'=>now(),
                ]);
        }
    }
}
