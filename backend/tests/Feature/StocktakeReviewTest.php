<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class StocktakeReviewTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_manager_approval_posts_stock_variance_once_with_a_stocktake_source_reference(): void
    {
        [$organization, $manager, $stocktakeId, $productId, $vehicleLocationId] = $this->fixture('Stocktake Approval');
        $this->actingAs($manager, 'sanctum');

        $this->getJson('/api/v1/inventory/stocktakes?status=submitted')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $stocktakeId)
            ->assertJsonPath('data.0.items.0.variance_quantity_base', -2);

        $this->postJson('/api/v1/inventory/stocktakes/'.$stocktakeId.'/review', [
            'action' => 'approve', 'review_notes' => 'تمت مراجعة العد الفعلي للعربية.',
        ])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('posted_adjustments', 1);

        $balance = DB::table('stock_balances')->where('organization_id', $organization->id)
            ->where('product_id', $productId)->where('location_id', $vehicleLocationId)->value('quantity_base');
        $this->assertSame('5.000000', number_format((float) $balance, 6, '.', ''));

        $movement = DB::table('stock_movements')->where('organization_id', $organization->id)
            ->where('source_document_type', 'field_stock_count')->where('source_document_id', $stocktakeId)->first();
        $this->assertNotNull($movement);
        $this->assertSame('-2.000000', number_format((float) $movement->quantity_base, 6, '.', ''));
        $this->assertSame(1, DB::table('stock_movements')->where('organization_id', $organization->id)
            ->where('source_document_type', 'field_stock_count')->where('source_document_id', $stocktakeId)->count());
        $this->assertDatabaseHas('field_stock_counts', [
            'organization_id' => $organization->id, 'id' => $stocktakeId,
            'status' => 'approved', 'reviewed_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id, 'entity_type' => 'field_stock_count',
            'entity_id' => $stocktakeId, 'action' => 'inventory.stocktake.approved',
        ]);
        $this->assertDatabaseHas('message_outbox', [
            'organization_id' => $organization->id, 'aggregate_type' => 'field_stock_count',
            'aggregate_id' => $stocktakeId, 'event_type' => 'inventory.stocktake.approved', 'status' => 'pending',
        ]);

        $this->postJson('/api/v1/inventory/stocktakes/'.$stocktakeId.'/review', [
            'action' => 'approve',
        ])->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, DB::table('stock_movements')->where('organization_id', $organization->id)
            ->where('source_document_type', 'field_stock_count')->where('source_document_id', $stocktakeId)->count());
    }

    public function test_stale_stocktake_is_rejected_atomically_without_posting_any_adjustment(): void
    {
        [$organization, $manager, $stocktakeId, $productId, $vehicleLocationId] = $this->fixture('Stocktake Stale');
        DB::table('stock_balances')->where('organization_id', $organization->id)
            ->where('product_id', $productId)->where('location_id', $vehicleLocationId)
            ->update(['quantity_base' => 8]);

        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/inventory/stocktakes/'.$stocktakeId.'/review', [
            'action' => 'approve',
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.expected_quantity_base']);

        $this->assertDatabaseHas('field_stock_counts', [
            'organization_id' => $organization->id, 'id' => $stocktakeId, 'status' => 'submitted',
        ]);
        $this->assertSame('8.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)
            ->where('product_id', $productId)->where('location_id', $vehicleLocationId)->value('quantity_base'), 6, '.', ''));
        $this->assertSame(0, DB::table('stock_movements')->where('organization_id', $organization->id)
            ->where('source_document_type', 'field_stock_count')->where('source_document_id', $stocktakeId)->count());
    }

    public function test_reject_requires_a_reason_and_does_not_change_stock(): void
    {
        [$organization, $manager, $stocktakeId, $productId, $vehicleLocationId] = $this->fixture('Stocktake Reject');
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/inventory/stocktakes/'.$stocktakeId.'/review', [
            'action' => 'reject',
        ])->assertUnprocessable()->assertJsonValidationErrors(['review_notes']);

        $this->postJson('/api/v1/inventory/stocktakes/'.$stocktakeId.'/review', [
            'action' => 'reject', 'review_notes' => 'العد غير مؤكد؛ أعد الجرد بعد مراجعة الصندوق.',
        ])->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertDatabaseHas('field_stock_counts', [
            'organization_id' => $organization->id, 'id' => $stocktakeId,
            'status' => 'rejected', 'review_notes' => 'العد غير مؤكد؛ أعد الجرد بعد مراجعة الصندوق.',
        ]);
        $this->assertSame('7.000000', number_format((float) DB::table('stock_balances')->where('organization_id', $organization->id)
            ->where('product_id', $productId)->where('location_id', $vehicleLocationId)->value('quantity_base'), 6, '.', ''));
        $this->assertSame(0, DB::table('stock_movements')->where('organization_id', $organization->id)
            ->where('source_document_type', 'field_stock_count')->where('source_document_id', $stocktakeId)->count());
    }

    public function test_review_queue_does_not_expose_another_organization_stocktake(): void
    {
        [$organizationA, $userA] = $this->organizationUser('Stocktake Tenant A');
        [$organizationB, , $stocktakeB] = $this->fixture('Stocktake Tenant B');
        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/v1/inventory/stocktakes/'.$stocktakeB.'/review', [
                'action' => 'approve',
            ])->assertNotFound();
        $this->getJson('/api/v1/inventory/stocktakes?status=all')
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(0, DB::table('stock_movements')->where('organization_id', $organizationA->id)
            ->where('source_document_type', 'field_stock_count')->count());
    }

    /** @return array{Organization, User, string, string, string} */
    private function fixture(string $name): array
    {
        [$organization, $manager] = $this->organizationUser($name);
        $unitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $unitId, 'organization_id' => $organization->id, 'code' => 'PCS-'.substr($unitId, -5),
            'name_ar' => 'قطعة', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organization->id, 'base_unit_id' => $unitId,
            'sku' => 'STOCKTAKE-'.substr($productId, -6), 'name_ar' => 'صنف الجرد التجريبي',
            'default_cost' => 10, 'default_piece_price' => 20, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouseId = $this->location($organization->id, 'ST-WH-'.substr($productId, -4));
        $vehicleLocationId = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $vehicleLocationId, 'organization_id' => $organization->id,
            'code' => 'ST-VAN-'.substr($productId, -4), 'name' => 'عربية الجرد التجريبية',
            'type' => 'vehicle', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $vehicleId = (string) Str::ulid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId, 'organization_id' => $organization->id, 'location_id' => $vehicleLocationId,
            'code' => 'ST-V-'.substr($vehicleId, -4), 'name' => 'عربية الجرد التجريبية', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripId = (string) Str::ulid();
        DB::table('trips')->insert([
            'id' => $tripId, 'organization_id' => $organization->id, 'trip_number' => 'ST-TR-'.substr($tripId, -6),
            'vehicle_id' => $vehicleId, 'rep_user_id' => $manager->id, 'status' => 'loaded',
            'trip_date' => now()->toDateString(), 'origin_location_id' => $warehouseId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_balances')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'product_id' => $productId,
            'location_id' => $vehicleLocationId, 'quantity_base' => 7, 'reserved_quantity_base' => 0,
            'average_cost' => 10, 'updated_at' => now(),
        ]);

        $stocktakeId = (string) Str::ulid();
        $items = [[
            'product_id' => $productId, 'sku' => 'STOCKTAKE-'.substr($productId, -6),
            'product_name' => 'صنف الجرد التجريبي', 'base_unit_name' => 'قطعة',
            'expected_quantity_base' => 7, 'counted_quantity_base' => 5, 'variance_quantity_base' => -2,
        ]];
        DB::table('field_stock_counts')->insert([
            'id' => $stocktakeId, 'organization_id' => $organization->id, 'trip_id' => $tripId,
            'submitted_by' => $manager->id, 'status' => 'submitted', 'idempotency_key' => 'review-test-'.$stocktakeId,
            'payload_hash' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            'item_count' => 1, 'variance_items_count' => 1,
            'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'notes' => 'اختبار مراجعة الجرد', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$organization, $manager, $stocktakeId, $productId, $vehicleLocationId];
    }

    private function organizationUser(string $name): array
    {
        $organization = Organization::query()->create([
            'name' => $name, 'default_currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => $name.' Manager',
            'email' => Str::uuid().'@nexora.test', 'phone' => '01000000000',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        return [$organization, $user];
    }

    private function location(string $organizationId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => $code,
            'name' => 'مخزن اختبار', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
