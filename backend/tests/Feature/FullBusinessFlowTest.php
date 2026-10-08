<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Transactions\TransactionPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FullBusinessFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_return_reconciles_original_invoice_balance(): void
    {
        [$org,$user,$unit,$product,$customer,$location] = $this->foundation();
        DB::table('stock_balances')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$org->id,'product_id'=>$product,'location_id'=>$location,
            'quantity_base'=>10,'reserved_quantity_base'=>0,'average_cost'=>40,'updated_at'=>now(),
        ]);
        $service=app(TransactionPostingService::class);
        $sale=$service->postSale($org->id,[
            'customer_id'=>$customer,'location_id'=>$location,'items'=>[['product_id'=>$product,'quantity'=>2,'conversion_factor'=>1,'unit_price'=>100,'unit_id'=>$unit]],
            'paid_amount'=>0,'created_by'=>$user->id,
        ]);
        $item=DB::table('sales_invoice_items')->where('sales_invoice_id',$sale['id'])->first();
        $return=$service->postSalesReturn($org->id,[
            'customer_id'=>$customer,'location_id'=>$location,'original_sales_invoice_id'=>$sale['id'],
            'items'=>[['product_id'=>$product,'quantity'=>1,'conversion_factor'=>1,'unit_price'=>100,'original_sales_invoice_item_id'=>$item->id]],
            'created_by'=>$user->id,'idempotency_key'=>(string)Str::uuid(),
        ]);
        $invoice=DB::table('sales_invoices')->where('id',$sale['id'])->first();
        $this->assertSame('100.0000',number_format((float)$return['total'],4,'.',''));
        $this->assertSame('100.0000',number_format((float)$invoice->balance_due,4,'.',''));
        $this->assertSame('9.000000',number_format((float)DB::table('stock_balances')->where('product_id',$product)->where('location_id',$location)->value('quantity_base'),6,'.',''));
    }

    public function test_vehicle_load_keeps_weighted_average_cost(): void
    {
        [$org,$user,$unit,$product,$customer,$location] = $this->foundation();
        $vehicleLocation=(string)Str::ulid();
        DB::table('locations')->insert([
            'id'=>$vehicleLocation,'organization_id'=>$org->id,'code'=>'VH-01','name'=>'Vehicle Location','type'=>'vehicle','status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]);
        $vehicleId=(string)Str::ulid();
        DB::table('vehicles')->insert([
            'id'=>$vehicleId,'organization_id'=>$org->id,'location_id'=>$vehicleLocation,'code'=>'V-01','plate_number'=>'TEST-01','name'=>'Test Vehicle','active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $tripId=(string)Str::ulid();
        DB::table('trips')->insert([
            'id'=>$tripId,'organization_id'=>$org->id,'trip_number'=>'TR-TEST-01','vehicle_id'=>$vehicleId,'rep_user_id'=>$user->id,'status'=>'planned',
            'trip_date'=>now()->toDateString(),'origin_location_id'=>$location,'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('stock_balances')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$org->id,'product_id'=>$product,'location_id'=>$location,'quantity_base'=>10,'reserved_quantity_base'=>0,'average_cost'=>40,'updated_at'=>now(),
        ]);
        DB::table('stock_balances')->insert([
            'id'=>(string)Str::ulid(),'organization_id'=>$org->id,'product_id'=>$product,'location_id'=>$vehicleLocation,'quantity_base'=>10,'reserved_quantity_base'=>0,'average_cost'=>60,'updated_at'=>now(),
        ]);
        app(TransactionPostingService::class)->postTripLoad($org->id,[
            'trip_id'=>$tripId,'from_location_id'=>$location,'idempotency_key'=>(string)Str::uuid(),'created_by'=>$user->id,
            'items'=>[['product_id'=>$product,'quantity_base'=>10]],
        ]);
        $vehicle=DB::table('stock_balances')->where('product_id',$product)->where('location_id',$vehicleLocation)->first();
        $this->assertSame('20.000000',number_format((float)$vehicle->quantity_base,6,'.',''));
        $this->assertSame('50.0000',number_format((float)$vehicle->average_cost,4,'.',''));
    }

    private function foundation(): array
    {
        $org=Organization::query()->create(['name'=>'NEXORA Full Flow Test','default_currency'=>'EGP','timezone'=>'Africa/Cairo','country_code'=>'EG','status'=>'active']);
        $user=User::query()->create(['organization_id'=>$org->id,'name'=>'Flow Tester','email'=>'flow-'.$org->id.'@nexora.test','phone'=>'01000000002','password'=>'secret-password','status'=>'active']);
        $unit=(string)Str::ulid();
        DB::table('units')->insert(['id'=>$unit,'organization_id'=>$org->id,'code'=>'PCS','name_ar'=>'قطعة','precision'=>0,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $product=(string)Str::ulid();
        DB::table('products')->insert(['id'=>$product,'organization_id'=>$org->id,'base_unit_id'=>$unit,'sku'=>'FLOW-'.$org->id,'name_ar'=>'Flow Product','default_cost'=>40,'default_piece_price'=>100,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $customer=(string)Str::ulid();
        DB::table('customers')->insert(['id'=>$customer,'organization_id'=>$org->id,'code'=>'CUS-FLOW','name'=>'Flow Customer','normalized_name'=>'flow customer','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $location=(string)Str::ulid();
        DB::table('locations')->insert(['id'=>$location,'organization_id'=>$org->id,'code'=>'WH-FLOW','name'=>'Flow Warehouse','type'=>'warehouse','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        return [$org,$user,$unit,$product,$customer,$location];
    }
}
