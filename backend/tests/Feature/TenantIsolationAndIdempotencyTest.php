<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TenantIsolationAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_cannot_reference_customer_from_another_organization(): void
    {
        [$orgA,$customerA,$locationA,$productA] = $this->foundation('A');
        [$orgB,$customerB,$locationB,$productB] = $this->foundation('B');

        DB::table('stock_balances')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$orgA->id,'product_id'=>$productA,'location_id'=>$locationA,
            'quantity_base'=>10,'reserved_quantity_base'=>0,'average_cost'=>20,'updated_at'=>now(),
        ]);

        $this->expectException(\Throwable::class);

        app(TransactionPostingService::class)->postSale($orgA->id, [
            'customer_id'=>$customerB,
            'location_id'=>$locationA,
            'items'=>[['product_id'=>$productA,'quantity'=>1,'conversion_factor'=>1,'unit_price'=>50]],
            'paid_amount'=>0,
        ]);
    }

    public function test_sale_cannot_reference_product_from_another_organization(): void
    {
        [$orgA,$customerA,$locationA,$productA] = $this->foundation('A');
        [$orgB,$customerB,$locationB,$productB] = $this->foundation('B');

        DB::table('stock_balances')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$orgA->id,'product_id'=>$productA,'location_id'=>$locationA,
            'quantity_base'=>10,'reserved_quantity_base'=>0,'average_cost'=>20,'updated_at'=>now(),
        ]);

        $this->expectException(\Throwable::class);

        app(TransactionPostingService::class)->postSale($orgA->id, [
            'customer_id'=>$customerA,
            'location_id'=>$locationA,
            'items'=>[['product_id'=>$productB,'quantity'=>1,'conversion_factor'=>1,'unit_price'=>50]],
            'paid_amount'=>0,
        ]);
    }

    public function test_same_idempotency_key_replays_same_sale_without_duplicate_stock_movement(): void
    {
        [$org,$customer,$location,$product] = $this->foundation('IDEM');

        DB::table('stock_balances')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$org->id,'product_id'=>$product,'location_id'=>$location,
            'quantity_base'=>10,'reserved_quantity_base'=>0,'average_cost'=>20,'updated_at'=>now(),
        ]);

        $key=(string)Str::uuid();
        $payload=[
            'customer_id'=>$customer,'location_id'=>$location,
            'items'=>[['product_id'=>$product,'quantity'=>1,'conversion_factor'=>1,'unit_price'=>50]],
            'paid_amount'=>0,'idempotency_key'=>$key,
        ];

        $service=app(TransactionPostingService::class);
        $first=$service->postSale($org->id,$payload);
        $second=$service->postSale($org->id,$payload);

        $this->assertSame($first['id'],$second['id']);
        $this->assertSame(1,DB::table('sales_invoices')->where('organization_id',$org->id)->count());
        $this->assertSame(1,DB::table('stock_movements')->where('organization_id',$org->id)->where('source_document_id',$first['id'])->count());
        $this->assertSame('9.000000',number_format((float)DB::table('stock_balances')->where('organization_id',$org->id)->where('product_id',$product)->where('location_id',$location)->value('quantity_base'),6,'.',''));
    }

    public function test_reusing_idempotency_key_with_different_payload_is_rejected(): void
    {
        [$org,$customer,$location,$product] = $this->foundation('IDEM-DIFF');

        DB::table('stock_balances')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$org->id,'product_id'=>$product,'location_id'=>$location,
            'quantity_base'=>10,'reserved_quantity_base'=>0,'average_cost'=>20,'updated_at'=>now(),
        ]);

        $key=(string)Str::uuid();
        $service=app(TransactionPostingService::class);
        $base=[
            'customer_id'=>$customer,'location_id'=>$location,
            'items'=>[['product_id'=>$product,'quantity'=>1,'conversion_factor'=>1,'unit_price'=>50]],
            'paid_amount'=>0,'idempotency_key'=>$key,
        ];
        $service->postSale($org->id,$base);

        $this->expectException(ValidationException::class);
        $service->postSale($org->id,array_replace_recursive($base,[
            'items'=>[['product_id'=>$product,'quantity'=>2,'conversion_factor'=>1,'unit_price'=>50]],
        ]));
    }

    private function foundation(string $suffix): array
    {
        $org=Organization::query()->create([
            'name'=>'NEXORA Tenant '.$suffix,'default_currency'=>'EGP','timezone'=>'Africa/Cairo','country_code'=>'EG','status'=>'active',
        ]);
        $unit=(string)Str::ulid();
        DB::table('units')->insert([
            'id'=>$unit,'organization_id'=>$org->id,'code'=>'PCS-'.$suffix,'name_ar'=>'قطعة','precision'=>0,'active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $product=(string)Str::ulid();
        DB::table('products')->insert([
            'id'=>$product,'organization_id'=>$org->id,'base_unit_id'=>$unit,'sku'=>'TEN-'.$suffix.'-'.$org->id,
            'name_ar'=>'Tenant Product','default_cost'=>20,'default_piece_price'=>50,'active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $customer=(string)Str::ulid();
        DB::table('customers')->insert([
            'id'=>$customer,'organization_id'=>$org->id,'code'=>'CUS-'.$suffix,'name'=>'Tenant Customer',
            'normalized_name'=>'tenant customer','status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
        $location=(string)Str::ulid();
        DB::table('locations')->insert([
            'id'=>$location,'organization_id'=>$org->id,'code'=>'WH-'.$suffix,'name'=>'Tenant Warehouse',
            'type'=>'warehouse','status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
        return [$org,$customer,$location,$product];
    }
}
