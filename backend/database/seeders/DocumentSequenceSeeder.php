<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class DocumentSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $organizations = DB::table('organizations')->pluck('id');

        $types = [
            ['sales_invoice','SI'],
            ['sales_return','SR'],
            ['purchase_invoice','PI'],
            ['purchase_return','PR'],
            ['payment','PAY'],
            ['trip','TRIP'],
            ['trip_load','LOAD'],
        ];

        foreach ($organizations as $organizationId) {
            foreach ($types as [$type,$prefix]) {
                DB::table('document_sequences')->insertOrIgnore([
                    'id'=>(string) Str::ulid(),
                    'organization_id'=>$organizationId,
                    'document_type'=>$type,
                    'prefix'=>$prefix,
                    'next_number'=>1,
                    'padding'=>6,
                    'reset_policy'=>'never',
                    'active'=>true,
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);

                DB::table('document_sequences')
                    ->where('organization_id',$organizationId)
                    ->where('document_type',$type)
                    ->update([
                        'prefix'=>$prefix,
                        'padding'=>6,
                        'reset_policy'=>'never',
                        'active'=>true,
                        'updated_at'=>now(),
                    ]);
            }
        }
    }
}
