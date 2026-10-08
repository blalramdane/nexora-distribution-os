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
            DB::table('units')->updateOrInsert(
                ['organization_id'=>null,'code'=>$code],
                ['id'=>(string) Str::ulid(),'name_ar'=>$ar,'name_en'=>$en,'precision'=>$precision,'active'=>true,'updated_at'=>now(),'created_at'=>now()]
            );
        }

        $methods = [
            ['cash','نقدي','Cash',false],
            ['bank_transfer','تحويل بنكي','Bank transfer',true],
            ['postal','بريد','Postal',true],
            ['cheque','شيك','Cheque',true],
            ['other','أخرى','Other',true],
        ];

        foreach ($methods as [$code,$ar,$en,$ref]) {
            DB::table('payment_methods')->updateOrInsert(
                ['organization_id'=>null,'code'=>$code],
                ['id'=>(string) Str::ulid(),'name_ar'=>$ar,'name_en'=>$en,'requires_reference'=>$ref,'active'=>true,'updated_at'=>now(),'created_at'=>now()]
            );
        }
    }
}