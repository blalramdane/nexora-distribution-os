<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartyBalanceProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_projection_tracks_net_customer_balance(): void
    {
        [$org,$user,$unit,$product,$customer,$location] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$org->id,'product_id'=>$product,
            'location_id'=>$location,'quantity_base'=>10,'reserved_quantity_base'=>0,'average_cost'=>40,'updated_at'=>now(),
        ]);

        app(TransactionPostingService::class)->postSale($org->id,[
            'customer_id'=>$customer,'location_id'=>$location,'items'=>[[
                'product_id'=>$product,'quantity'=>1,'conversion_factor'=>1,'unit_price'=>100,'unit_id'=>$unit,
            ]],'paid_amount'=>30,'created_by'=>$user->id,
        ]);

        $row=DB::table('customer_balance_summaries')->where('organization_id',$org->id)->where('customer_id',$customer)->first();
        $this->assertSame('100.0000',number_format((float)$row->total_sales,4,'.',''));
        $this->assertSame('30.0000',number_format((float)$row->total_paid,4,'.',''));
        $this->assertSame('70.0000',number_format((float)$row->outstanding,4,'.',''));
    }

    public function test_purchase_projection_tracks_supplier_payable(): void
    {
        [$org,$user,$unit,$product,$customer,$location] = $this->foundation();
        $supplier=(string)Str::ulid();
        DB::table('suppliers')->insert([
            'id'=>$supplier,'organization_id'=>$org->id,'code'=>'SUP-01','name'=>'Test Supplier',
            'normalized_name'=>'test supplier','active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);

        app(TransactionPostingService::class)->postPurchase($org->id,[
            'supplier_id'=>$supplier,'location_id'=>$location,'items'=>[[
                'product_id'=>$product,'quantity'=>2,'conversion_factor'=>1,'unit_cost'=>50,'unit_id'=>$unit,
            ]],'created_by'=>$user->id,
        ]);

        $row=DB::table('supplier_balance_summaries')->where('organization_id',$org->id)->where('supplier_id',$supplier)->first();
        $this->assertSame('100.0000',number_format((float)$row->total_purchases,4,'.',''));
        $this->assertSame('100.0000',number_format((float)$row->outstanding,4,'.',''));
    }

    private function foundation(): array
    {
        $org=Organization::query()->create(['name'=>'Projection Test','default_currency'=>'EGP','timezone'=>'Africa/Cairo','country_code'=>'EG','status'=>'active']);
        $user=User::query()->create(['organization_id'=>$org->id,'name'=>'Tester','email'=>'projection-'.$org->id.'@nexora.test','phone'=>'01000000002','password'=>'secret','status'=>'active']);
        $unit=(string)Str::ulid();
        DB::table('units')->insert(['id'=>$unit,'organization_id'=>$org->id,'code'=>'PCS','name_ar'=>'قطعة','precision'=>0,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $product=(string)Str::ulid();
        DB::table('products')->insert(['id'=>$product,'organization_id'=>$org->id,'base_unit_id'=>$unit,'sku'=>'SKU-'.$org->id,'name_ar'=>'Test Product','default_cost'=>40,'default_piece_price'=>100,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $customer=(string)Str::ulid();
        DB::table('customers')->insert(['id'=>$customer,'organization_id'=>$org->id,'code'=>'CUS-01','name'=>'Test Customer','normalized_name'=>'test customer','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $location=(string)Str::ulid();
        DB::table('locations')->insert(['id'=>$location,'organization_id'=>$org->id,'code'=>'WH-01','name'=>'Main Warehouse','type'=>'warehouse','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        return [$org,$user,$unit,$product,$customer,$location];
    }
}
