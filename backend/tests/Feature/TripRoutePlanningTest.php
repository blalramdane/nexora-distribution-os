<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TripRoutePlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_optimizer_orders_multiple_customers_by_distance_and_allows_manual_reorder(): void
    {
        [$organization,$user,$tripId,$customers] = $this->foundation();
        Sanctum::actingAs($user);

        foreach ($customers as $customerId) {
            $this->postJson('/api/v1/trips/'.$tripId.'/customers', ['customer_id'=>$customerId])->assertOk();
        }

        $route = $this->getJson('/api/v1/trips/'.$tripId.'/route')->assertOk()->json();
        $this->assertCount(3,$route);
        $this->assertSame(1,$route[0]['sequence']);

        $optimized = $this->postJson('/api/v1/trips/'.$tripId.'/route/optimize', [])->assertOk()->json();

        $this->assertSame(2,$optimized['optimized_count']);
        $this->assertSame(1,$optimized['without_coordinates']);
        $this->assertSame($customers[0],$optimized['customers'][0]['customer_id']);
        $this->assertSame($customers[1],$optimized['customers'][1]['customer_id']);
        $this->assertSame($customers[2],$optimized['customers'][2]['customer_id']);

        $reordered = $this->postJson('/api/v1/trips/'.$tripId.'/route/reorder', [
            'customer_ids'=>[$customers[1],$customers[0],$customers[2]],
        ])->assertOk()->json();

        $this->assertSame([$customers[1],$customers[0],$customers[2]],array_column($reordered['customers'],'customer_id'));
        $this->assertSame([1,2,3],array_column($reordered['customers'],'sequence'));

        $this->assertDatabaseHas('trip_customers',['organization_id'=>$organization->id,'trip_id'=>$tripId,'customer_id'=>$customers[1],'sequence'=>1]);
    }

    public function test_route_reorder_rejects_partial_customer_lists(): void
    {
        [, $user, $tripId, $customers] = $this->foundation();
        Sanctum::actingAs($user);

        foreach ($customers as $customerId) {
            $this->postJson('/api/v1/trips/'.$tripId.'/customers', ['customer_id'=>$customerId])->assertOk();
        }

        $this->postJson('/api/v1/trips/'.$tripId.'/route/reorder', [
            'customer_ids'=>[$customers[0],$customers[1]],
        ])->assertStatus(422);
    }

    private function foundation(): array
    {
        $organization=Organization::query()->create([
            'name'=>'NEXORA Route Test',
            'default_currency'=>'EGP',
            'timezone'=>'Africa/Cairo',
            'country_code'=>'EG',
            'status'=>'active',
        ]);

        $user=User::query()->create([
            'organization_id'=>$organization->id,
            'name'=>'Route User',
            'email'=>'route-'.$organization->id.'@nexora.test',
            'phone'=>'01000000009',
            'password'=>'secret-password',
            'status'=>'active',
        ]);

        $locationId=(string)Str::ulid();
        DB::table('locations')->insert([
            'id'=>$locationId,'organization_id'=>$organization->id,'code'=>'WH-ROUTE',
            'name'=>'Route Warehouse','type'=>'warehouse','status'=>'active',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $vehicleId=(string)Str::ulid();
        DB::table('vehicles')->insert([
            'id'=>$vehicleId,'organization_id'=>$organization->id,'location_id'=>$locationId,
            'code'=>'V-ROUTE','plate_number'=>'EG-ROUTE','name'=>'Route Vehicle','active'=>true,
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $tripId=(string)Str::ulid();
        DB::table('trips')->insert([
            'id'=>$tripId,'organization_id'=>$organization->id,'trip_number'=>'TR-ROUTE-001',
            'vehicle_id'=>$vehicleId,'rep_user_id'=>$user->id,'status'=>'planned',
            'trip_date'=>now()->toDateString(),'origin_location_id'=>$locationId,
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $customers=[];
        $points=[
            ['code'=>'CUS-R1','name'=>'Near A','lat'=>31.2000000,'lon'=>31.6000000],
            ['code'=>'CUS-R2','name'=>'Near B','lat'=>31.2010000,'lon'=>31.6010000],
            ['code'=>'CUS-R3','name'=>'No Coordinates','lat'=>null,'lon'=>null],
        ];

        foreach($points as $point){
            $customerId=(string)Str::ulid();
            DB::table('customers')->insert([
                'id'=>$customerId,'organization_id'=>$organization->id,'code'=>$point['code'],
                'name'=>$point['name'],'normalized_name'=>strtolower($point['name']),
                'status'=>'active','created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('customer_addresses')->insert([
                'id'=>(string)Str::ulid(),'organization_id'=>$organization->id,'customer_id'=>$customerId,
                'label'=>'primary','latitude'=>$point['lat'],'longitude'=>$point['lon'],
                'is_primary'=>true,'active'=>true,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $customers[]=$customerId;
        }

        return [$organization,$user,$tripId,$customers];
    }
}
