<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DistributionSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribution_schema_tables_exist(): void
    {
        $tables = [
            'governorates','centers','cities_areas','units','categories','payment_methods','financial_accounts',
            'products','product_packagings','product_barcodes','product_aliases','suppliers','supplier_products',
            'customers','customer_addresses','customer_location_events','locations','warehouses','vehicles',
            'document_sequences','idempotency_keys','audit_logs','ledger_accounts','ledger_entries',
            'stock_movements','stock_balances','purchase_invoices','purchase_invoice_items','purchase_returns',
            'purchase_return_items','sales_invoices','sales_invoice_items','sales_returns','sales_return_items',
            'payments','payment_allocations','expenses','trips','trip_customers','trip_loads','trip_load_items',
            'customer_visits','trip_expenses','trip_settlements','trip_settlement_lines','sync_operations',
            'sync_conflicts','message_outbox','outbox_attempts','customer_balance_summaries',
            'supplier_balance_summaries','dashboard_daily_summaries',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), $table.' is missing');
        }
    }

    public function test_packaging_conversion_must_be_positive(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('organizations')->insert([
            'id'=>(string) Str::ulid(), 'name'=>'Test Org', 'default_currency'=>'EGP',
            'timezone'=>'Africa/Cairo', 'country_code'=>'EG', 'status'=>'active',
            'created_at'=>now(), 'updated_at'=>now(),
        ]);
        $org = DB::table('organizations')->latest('created_at')->first();
        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id'=>$unitId,'organization_id'=>$org->id,'code'=>'pc','name_ar'=>'قطعة',
            'precision'=>0,'active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $productId=(string) Str::ulid();
        $categoryId=(string) Str::ulid();
        DB::table('categories')->insert([
            'id'=>$categoryId,'organization_id'=>$org->id,'code'=>'TEST','name_ar'=>'Test',
            'active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('products')->insert([
            'id'=>$productId,'organization_id'=>$org->id,'category_id'=>$categoryId,'base_unit_id'=>$unitId,
            'sku'=>'TEST-001','name_ar'=>'Test Product','default_cost'=>10,'default_piece_price'=>20,
            'active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('product_packagings')->insert([
            'id'=>(string) Str::ulid(),'organization_id'=>$org->id,'product_id'=>$productId,'unit_id'=>$unitId,
            'name_ar'=>'Invalid','conversion_to_base'=>0,'active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function test_idempotency_key_is_unique_per_tenant_and_operation(): void
    {
        $orgId=(string) Str::ulid();
        DB::table('organizations')->insert([
            'id'=>$orgId,'name'=>'Idempotency Org','default_currency'=>'EGP',
            'timezone'=>'Africa/Cairo','country_code'=>'EG','status'=>'active',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $payload=[
            'id'=>(string) Str::ulid(),'organization_id'=>$orgId,'operation_type'=>'sale.post',
            'idempotency_key'=>'same-key','request_fingerprint'=>hash('sha256','a'),
            'status'=>'processing','created_at'=>now(),
        ];
        DB::table('idempotency_keys')->insert($payload);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('idempotency_keys')->insert([...$payload,'id'=>(string) Str::ulid(),'request_fingerprint'=>hash('sha256','b')]);
    }

    public function test_vehicle_is_backed_by_a_stock_location(): void
    {
        $orgId=(string) Str::ulid();
        DB::table('organizations')->insert([
            'id'=>$orgId,'name'=>'Vehicle Org','default_currency'=>'EGP',
            'timezone'=>'Africa/Cairo','country_code'=>'EG','status'=>'active',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $locationId=(string) Str::ulid();
        DB::table('locations')->insert([
            'id'=>$locationId,'organization_id'=>$orgId,'code'=>'VEH-01','name'=>'Vehicle 01',
            'type'=>'vehicle','status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
        $vehicleId=(string) Str::ulid();
        DB::table('vehicles')->insert([
            'id'=>$vehicleId,'organization_id'=>$orgId,'location_id'=>$locationId,'code'=>'V01',
            'name'=>'Vehicle 01','active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);

        $this->assertDatabaseHas('vehicles',['id'=>$vehicleId,'location_id'=>$locationId]);
        $this->assertDatabaseHas('locations',['id'=>$locationId,'type'=>'vehicle']);
    }

    public function test_packaging_snapshots_exist_on_transaction_items(): void
    {
        foreach ([
            ['table'=>'purchase_invoice_items','column'=>'conversion_factor_snapshot'],
            ['table'=>'sales_invoice_items','column'=>'conversion_factor_snapshot'],
            ['table'=>'purchase_return_items','column'=>'conversion_factor_snapshot'],
            ['table'=>'sales_return_items','column'=>'conversion_factor_snapshot'],
        ] as $assertion) {
            $this->assertTrue(DB::getSchemaBuilder()->hasColumn($assertion['table'],$assertion['column']));
        }
    }
}