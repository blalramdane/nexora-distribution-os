<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalCrudFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_login_can_create_product_trader_purchase_and_sale(): void
    {
        $this->seed(DatabaseSeeder::class);

        $org=DB::table('organizations')->where('name','NEXORA Demo Distribution')->first();
        $this->assertNotNull($org);

        $login=$this->postJson('/api/v1/auth/login',[
            'organization_id'=>$org->id,
            'login'=>'admin@nexora.local',
            'password'=>'Nexora@12345',
            'device_name'=>'phpunit-e2e',
        ])->assertOk();

        $token=$login->json('token');
        $headers=['Authorization'=>'Bearer '.$token];

        $product=$this->withHeaders($headers)->postJson('/api/v1/products',[
            'sku'=>'E2E-001',
            'name_ar'=>'منتج اختبار E2E',
            'name_en'=>'E2E Test Product',
            'brand'=>'NEXORA',
            'base_unit_id'=>DB::table('units')->whereNull('organization_id')->where('code','piece')->value('id'),
            'default_cost'=>100,
            'default_piece_price'=>160,
        ])->assertCreated()->json();

        $customer=$this->withHeaders($headers)->postJson('/api/v1/customers',[
            'name'=>'تاجر E2E',
            'phone'=>'01555555555',
            'credit_limit'=>5000,
            'payment_terms_days'=>30,
        ])->assertCreated()->json();

        $supplier=DB::table('suppliers')->where('organization_id',$org->id)->where('code','SUP-DEMO')->first();
        $location=DB::table('locations')->where('organization_id',$org->id)->where('code','MAIN-WH')->first();

        $this->withHeaders($headers)->postJson('/api/v1/purchases',[
            'supplier_id'=>$supplier->id,
            'location_id'=>$location->id,
            'idempotency_key'=>'e2e-purchase-001',
            'items'=>[[
                'product_id'=>$product['id'],
                'quantity'=>10,
                'unit_cost'=>100,
            ]],
        ])->assertCreated()->assertJsonPath('status','posted');

        $sale=$this->withHeaders($headers)->postJson('/api/v1/sales',[
            'customer_id'=>$customer['id'],
            'location_id'=>$location->id,
            'idempotency_key'=>'e2e-sale-001',
            'paid_amount'=>160,
            'items'=>[[
                'product_id'=>$product['id'],
                'quantity'=>1,
                'unit_price'=>160,
            ]],
        ])->assertCreated()->json();

        $this->assertSame(160.0,(float)$sale['total']);
        $this->assertSame(160.0,(float)$sale['paid_amount']);
        $this->assertSame(0.0,(float)$sale['balance_due']);

        $this->assertSame(9.0,(float)DB::table('stock_balances')
            ->where('organization_id',$org->id)
            ->where('product_id',$product['id'])
            ->where('location_id',$location->id)
            ->value('quantity_base'));

        $this->assertDatabaseHas('customers',['id'=>$customer['id'],'name'=>'تاجر E2E']);
        $this->assertDatabaseHas('products',['id'=>$product['id'],'sku'=>'E2E-001']);
        $this->assertDatabaseHas('sales_invoices',['id'=>$sale['id'],'customer_id'=>$customer['id'],'total'=>160]);
    }
}
