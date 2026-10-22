<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoOrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalCrudFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_login_can_create_product_trader_purchase_and_sale(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoOrganizationSeeder::class);

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
        $cashAccount=DB::table('financial_accounts')->where('organization_id',$org->id)->where('code','CASH')->first();
        $cashMethod=DB::table('payment_methods')->whereNull('organization_id')->where('code','cash')->first();

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
            'financial_account_id'=>$cashAccount->id,
            'payment_method_id'=>$cashMethod->id,
            'payment_reference'=>'E2E-CASH-RECEIPT-001',
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

        $atSalePayment=DB::table('payments')->where('organization_id',$org->id)->where('source_document_type','sale_at_issue')->where('source_document_id',$sale['id'])->first();
        $this->assertNotNull($atSalePayment, 'Money received at invoice issue must create a real payment receipt.');
        $this->assertSame($cashAccount->id,$atSalePayment->financial_account_id);
        $this->assertSame($cashMethod->id,$atSalePayment->payment_method_id);
        $this->assertSame('E2E-CASH-RECEIPT-001',$atSalePayment->reference);
        $this->assertDatabaseHas('payment_allocations',[
            'organization_id'=>$org->id,'payment_id'=>$atSalePayment->id,
            'document_type'=>'sales_invoice','document_id'=>$sale['id'],'amount'=>'160.0000',
        ]);
        $financialLedgerId=DB::table('ledger_accounts')->where('organization_id',$org->id)->where('code','FA-CASH')->value('id');
        $this->assertNotNull($financialLedgerId);
        $this->assertDatabaseHas('ledger_entries',[
            'organization_id'=>$org->id,'account_id'=>$financialLedgerId,
            'source_document_type'=>'payment','source_document_id'=>$atSalePayment->id,'debit'=>'160.0000',
        ]);
        $this->assertDatabaseHas('ledger_entries',[
            'organization_id'=>$org->id,'source_document_type'=>'payment','source_document_id'=>$atSalePayment->id,
            'account_id'=>DB::table('ledger_accounts')->where('organization_id',$org->id)->where('code','1200')->value('id'),
            'credit'=>'160.0000',
        ]);
        $this->assertSame(160.0,(float)DB::table('payments')->where('organization_id',$org->id)->where('party_id',$customer['id'])->where('direction','inbound')->sum('amount'));

        // Credit is an invoice-level payment choice; it must not be blocked by
        // the customer-master credit_enabled flag or by a missing credit limit.
        $creditCustomer=$this->withHeaders($headers)->postJson('/api/v1/customers',[
            'name'=>'تاجر أجل حسب الفاتورة',
            'phone'=>'01555555556',
            'credit_limit'=>0,
            'payment_terms_days'=>0,
        ])->assertCreated()->json();
        $creditSale=$this->withHeaders($headers)->postJson('/api/v1/sales',[
            'customer_id'=>$creditCustomer['id'],
            'location_id'=>$location->id,
            'idempotency_key'=>'e2e-sale-full-credit-001',
            'paid_amount'=>0,
            'items'=>[[
                'product_id'=>$product['id'],
                'quantity'=>1,
                'unit_price'=>160,
            ]],
        ])->assertCreated()->json();

        $this->assertSame(160.0,(float)$creditSale['total']);
        $this->assertSame(0.0,(float)$creditSale['paid_amount']);
        $this->assertSame(160.0,(float)$creditSale['balance_due']);
        $this->assertSame(0,DB::table('payments')->where('organization_id',$org->id)->where('source_document_type','sale_at_issue')->where('source_document_id',$creditSale['id'])->count());
        $this->assertDatabaseHas('sales_invoices',['id'=>$creditSale['id'],'customer_id'=>$creditCustomer['id'],'balance_due'=>160]);
    }
}
