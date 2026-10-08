<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseCostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_weighted_average_uses_entered_unit_cost_not_multiplied_by_conversion(): void
    {
        $org = Organization::query()->create([
            'name' => 'NEXORA Purchase Cost Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);

        $user = User::query()->create([
            'organization_id' => $org->id,
            'name' => 'Purchase Cost Tester',
            'email' => 'purchase-cost-'.$org->id.'@nexora.test',
            'phone' => '01000000003',
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        $unit = (string) Str::ulid();
        $cartonUnit = (string) Str::ulid();
        DB::table('units')->insert([
            ['id'=>$unit,'organization_id'=>$org->id,'code'=>'PCS','name_ar'=>'قطعة','precision'=>0,'active'=>true,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>$cartonUnit,'organization_id'=>$org->id,'code'=>'CTN','name_ar'=>'كرتونة','precision'=>0,'active'=>true,'created_at'=>now(),'updated_at'=>now()],
        ]);

        $product = (string) Str::ulid();
        DB::table('products')->insert([
            'id'=>$product,'organization_id'=>$org->id,'base_unit_id'=>$unit,'sku'=>'COST-001',
            'name_ar'=>'Cost Product','default_cost'=>20,'default_piece_price'=>40,'active'=>true,
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $packaging = (string) Str::ulid();
        DB::table('product_packagings')->insert([
            'id'=>$packaging,'organization_id'=>$org->id,'product_id'=>$product,'unit_id'=>$cartonUnit,
            'name_ar'=>'كرتونة 10 قطع','conversion_to_base'=>10,'purchase_price'=>100,
            'active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $supplier = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id'=>$supplier,'organization_id'=>$org->id,'code'=>'SUP-COST','name'=>'Cost Supplier',
            'normalized_name'=>'cost supplier','active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $location = (string) Str::ulid();
        DB::table('locations')->insert([
            'id'=>$location,'organization_id'=>$org->id,'code'=>'WH-COST','name'=>'Cost Warehouse',
            'type'=>'warehouse','status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);

        $result = app(TransactionPostingService::class)->postPurchase($org->id, [
            'supplier_id'=>$supplier,
            'location_id'=>$location,
            'created_by'=>$user->id,
            'idempotency_key'=>(string) Str::uuid(),
            'items'=>[[
                'product_id'=>$product,
                'packaging_id'=>$packaging,
                'unit_id'=>$cartonUnit,
                'quantity'=>1,
                'conversion_factor'=>10,
                'unit_cost'=>100,
            ]],
        ]);

        $balance = DB::table('stock_balances')
            ->where('organization_id',$org->id)
            ->where('product_id',$product)
            ->where('location_id',$location)
            ->first();

        $item = DB::table('purchase_invoice_items')
            ->where('purchase_invoice_id',$result['id'])
            ->first();

        $movement = DB::table('stock_movements')
            ->where('source_document_id',$result['id'])
            ->first();

        $this->assertSame('10.000000', number_format((float)$balance->quantity_base, 6, '.', ''));
        $this->assertSame('10.0000', number_format((float)$balance->average_cost, 4, '.', ''));
        $this->assertSame('10.0000', number_format((float)$item->unit_cost_base, 4, '.', ''));
        $this->assertSame('10.0000', number_format((float)$movement->unit_cost, 4, '.', ''));
        $this->assertSame('100.0000', number_format((float)$item->line_total, 4, '.', ''));
    }
}
