<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class InvoiceShareLinksTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    public function test_sales_invoice_share_is_view_only_logged_revocable_and_tenant_scoped(): void
    {
        [$organization, $user] = $this->organizationUser('Sales Share Tenant');
        $fixture = $this->salesInvoice($organization->id, $user->id);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/v1/invoice-shares', [
            'invoice_type' => 'sales',
            'invoice_id' => $fixture['invoice_id'],
            'expires_in_days' => 7,
        ])->assertCreated()->assertJsonStructure(['id', 'invoice_type', 'invoice_id', 'path', 'expires_at']);

        $share = $response->json();
        $token = basename($share['path']);
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('invoice_share_links', [
            'id' => $share['id'],
            'organization_id' => $organization->id,
            'token_hash' => hash('sha256', $token),
            'invoice_type' => 'sales',
            'invoice_id' => $fixture['invoice_id'],
            'revoked_at' => null,
        ]);

        $publicInvoice = $this->getJson('/api/v1/shared/invoice/'.$token)
            ->assertOk()
            ->assertJsonPath('invoice.document_number', 'SALE-SHARE-001')
            ->assertJsonPath('invoice.party_name', 'تاجر مشاركة')
            ->assertJsonMissingPath('invoice.customer_phone')
            ->assertJsonMissingPath('invoice.items.0.unit_cost_snapshot');
        $cacheControlDirectives = array_map(
            'trim',
            explode(',', strtolower((string) $publicInvoice->baseResponse->headers->get('Cache-Control'))),
        );
        sort($cacheControlDirectives);
        $this->assertSame(['no-store', 'private'], $cacheControlDirectives, 'Shared invoice responses must be private and non-cacheable regardless of directive order.');
        $this->assertSame(2.0, (float) $publicInvoice->json('invoice.items.0.quantity'));

        $this->assertDatabaseHas('invoice_share_links', ['id' => $share['id'], 'access_count' => 1]);
        $this->assertDatabaseHas('invoice_share_access_logs', ['share_link_id' => $share['id'], 'action' => 'viewed']);
        $this->getJson('/api/v1/invoice-shares?invoice_type=sales&invoice_id='.$fixture['invoice_id'])
            ->assertOk()->assertJsonPath('0.id', $share['id']);

        [$foreignOrganization, $foreignUser] = $this->organizationUser('Foreign Sales Share Tenant');
        $this->actingAs($foreignUser, 'sanctum');
        $this->deleteJson('/api/v1/invoice-shares/'.$share['id'])->assertNotFound();
        $this->getJson('/api/v1/invoice-shares?invoice_type=sales&invoice_id='.$fixture['invoice_id'])->assertNotFound();
        $this->getJson('/api/v1/shared/invoice/'.$token)->assertOk();
        $this->assertNotSame($organization->id, $foreignOrganization->id);

        $this->actingAs($user, 'sanctum');
        $this->deleteJson('/api/v1/invoice-shares/'.$share['id'])->assertOk()->assertJsonPath('revoked', true);
        $this->getJson('/api/v1/shared/invoice/'.$token)->assertNotFound();
        $this->deleteJson('/api/v1/invoice-shares/'.$share['id'])->assertOk();
        $this->assertDatabaseHas('invoice_share_access_logs', ['share_link_id' => $share['id'], 'action' => 'revoked']);
    }

    public function test_purchase_invoice_share_can_only_be_created_by_a_user_with_purchase_permission(): void
    {
        [$organization, $user] = $this->organizationUser('Purchase Share Tenant');
        $fixture = $this->purchaseInvoice($organization->id, $user->id);
        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/v1/invoice-shares', [
            'invoice_type' => 'purchase',
            'invoice_id' => $fixture['invoice_id'],
            'expires_in_days' => 1,
        ])->assertCreated();
        $token = basename($response->json('path'));
        $publicInvoice = $this->getJson('/api/v1/shared/invoice/'.$token)
            ->assertOk()
            ->assertJsonPath('invoice.document_number', 'PUR-SHARE-001')
            ->assertJsonPath('invoice.party_name', 'مورد مشاركة');
        $this->assertSame(25.0, (float) $publicInvoice->json('invoice.items.0.unit_price'));
    }

    public function test_supplier_payment_voucher_can_be_shared_safely_and_revoked(): void
    {
        [$organization, $user] = $this->organizationUser('Supplier Payment Share Tenant');
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organization->id, 'code' => 'PAY-SHARE-SUP',
            'name' => 'مورد سند صرف', 'normalized_name' => 'مورد سند صرف', 'phone' => '01000000000', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $accountId = (string) Str::ulid();
        DB::table('financial_accounts')->insert([
            'id' => $accountId, 'organization_id' => $organization->id, 'code' => 'PAY-SHARE-CASH',
            'name' => 'الخزنة الداخلية', 'type' => 'cash', 'currency' => 'EGP', 'opening_balance' => 1000,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = (string) Str::ulid();
        DB::table('payment_methods')->insert([
            'id' => $methodId, 'organization_id' => $organization->id, 'code' => 'PAY-SHARE-CASH',
            'name_ar' => 'نقدي', 'requires_reference' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentId = (string) Str::ulid();
        DB::table('payments')->insert([
            'id' => $paymentId, 'organization_id' => $organization->id, 'party_type' => 'supplier', 'party_id' => $supplierId,
            'financial_account_id' => $accountId, 'payment_method_id' => $methodId, 'direction' => 'outbound',
            'amount' => 725.50, 'currency' => 'EGP', 'payment_date' => now()->toDateString(),
            'reference' => 'SUPPLIER-TRANSFER-7788', 'status' => 'posted', 'trip_id' => null,
            'trip_supplier_settlement_id' => null, 'created_by' => $user->id, 'device_id' => null,
            'idempotency_key' => 'supplier-payment-share-fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson('/api/v1/invoice-shares', [
            'invoice_type' => 'supplier_payment', 'invoice_id' => $paymentId, 'expires_in_days' => 7,
        ])->assertCreated()->assertJsonPath('invoice_type', 'supplier_payment');
        $token = basename($response->json('path'));

        $voucher = $this->getJson('/api/v1/shared/invoice/'.$token)->assertOk()
            ->assertJsonPath('document_type', 'supplier_payment')
            ->assertJsonPath('payment_receipt.document_number', 'SP-'.strtoupper(substr($paymentId, 0, 8)))
            ->assertJsonPath('payment_receipt.party_name', 'مورد سند صرف')
            ->assertJsonPath('payment_receipt.payment_method_name', 'نقدي')
            ->assertJsonMissingPath('payment_receipt.financial_account_id');
        // DECIMAL values are serialized as strings by MySQL and numbers by SQLite.
        $this->assertEquals(725.5, (float) $voucher->json('payment_receipt.amount'));
        $this->assertSame(['no-store', 'private'], collect(explode(',', strtolower((string) $voucher->baseResponse->headers->get('Cache-Control'))))->map(fn ($value) => trim($value))->sort()->values()->all());
        $this->assertDatabaseHas('invoice_share_access_logs', ['share_link_id' => $response->json('id'), 'action' => 'viewed']);

        $this->deleteJson('/api/v1/invoice-shares/'.$response->json('id'))->assertOk()->assertJsonPath('revoked', true);
        $this->getJson('/api/v1/shared/invoice/'.$token)->assertNotFound();
    }

    public function test_invoice_share_requires_a_posted_invoice_and_an_expiry_window(): void
    {
        [$organization, $user] = $this->organizationUser('Invalid Share Tenant');
        $fixture = $this->salesInvoice($organization->id, $user->id, 'draft');
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/invoice-shares', [
            'invoice_type' => 'sales',
            'invoice_id' => $fixture['invoice_id'],
            'expires_in_days' => 7,
        ])->assertNotFound();

        $posted = $this->salesInvoice($organization->id, $user->id, 'posted', 'SALE-SHARE-002');
        $this->postJson('/api/v1/invoice-shares', [
            'invoice_type' => 'sales',
            'invoice_id' => $posted['invoice_id'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['expires_in_days']);
    }

    public function test_user_without_invoice_view_permission_cannot_create_share_links(): void
    {
        [$organization, $user] = $this->organizationUser('No Share Permission Tenant', false);
        $fixture = $this->salesInvoice($organization->id, $user->id);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/invoice-shares', [
            'invoice_type' => 'sales',
            'invoice_id' => $fixture['invoice_id'],
            'expires_in_days' => 7,
        ])->assertForbidden();
    }

    private function organizationUser(string $name, bool $grant = true): array
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id,
            'name' => $name.' User',
            'email' => Str::uuid().'@nexora.test',
            'phone' => '01000000001',
            'password' => 'test-password',
            'status' => 'active',
        ]);
        if ($grant) {
            $this->grantTestPermissions($user);
        }

        return [$organization, $user];
    }

    private function salesInvoice(string $organizationId, string $userId, string $status = 'posted', string $number = 'SALE-SHARE-001'): array
    {
        $locationId = $this->location($organizationId, 'SALES-WH-'.substr($number, -5));
        $customerId = (string) Str::ulid();
        DB::table('customers')->insert([
            'id' => $customerId, 'organization_id' => $organizationId, 'code' => 'SHARE-CUS-'.substr($number, -5),
            'name' => 'تاجر مشاركة', 'normalized_name' => 'تاجر مشاركة', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (string) Str::ulid();
        DB::table('sales_invoices')->insert([
            'id' => $invoiceId, 'organization_id' => $organizationId, 'customer_id' => $customerId,
            'source_location_id' => $locationId, 'document_number' => $number, 'status' => $status,
            'invoice_date' => now()->toDateString(), 'subtotal' => 50, 'discount' => 0, 'tax' => 0,
            'total' => 50, 'paid_amount' => 10, 'balance_due' => 40, 'currency' => 'EGP',
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = $this->unit($organizationId);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organizationId, 'base_unit_id' => $unitId,
            'sku' => 'SHARE-SKU-'.substr($invoiceId, -6), 'name_ar' => 'صنف مشاركة', 'default_cost' => 20,
            'default_piece_price' => 25, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_invoice_items')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'sales_invoice_id' => $invoiceId,
            'product_id' => $productId, 'entered_unit_id' => $unitId, 'entered_quantity' => 2,
            'conversion_factor_snapshot' => 1, 'quantity_base' => 2, 'unit_price_entered' => 25,
            'unit_price_base' => 25, 'discount' => 0, 'tax' => 0, 'line_total' => 50,
            'unit_cost_snapshot' => 20, 'product_name_snapshot' => 'صنف مشاركة', 'sku_snapshot' => 'SHARE-SKU-'.substr($invoiceId, -6),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['invoice_id' => $invoiceId];
    }

    private function purchaseInvoice(string $organizationId, string $userId): array
    {
        $locationId = $this->location($organizationId, 'PURCHASE-WH');
        $supplierId = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'organization_id' => $organizationId, 'code' => 'SHARE-SUP',
            'name' => 'مورد مشاركة', 'normalized_name' => 'مورد مشاركة', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceId = (string) Str::ulid();
        DB::table('purchase_invoices')->insert([
            'id' => $invoiceId, 'organization_id' => $organizationId, 'supplier_id' => $supplierId,
            'location_id' => $locationId, 'document_number' => 'PUR-SHARE-001', 'status' => 'posted',
            'invoice_date' => now()->toDateString(), 'subtotal' => 25, 'discount' => 0, 'tax' => 0,
            'total' => 25, 'paid_amount' => 0, 'currency' => 'EGP', 'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = $this->unit($organizationId);
        $productId = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $productId, 'organization_id' => $organizationId, 'base_unit_id' => $unitId,
            'sku' => 'PUR-SHARE-SKU', 'name_ar' => 'صنف مشتريات مشاركة', 'default_cost' => 25,
            'default_piece_price' => 35, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_invoice_items')->insert([
            'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'purchase_invoice_id' => $invoiceId,
            'product_id' => $productId, 'entered_unit_id' => $unitId, 'entered_quantity' => 1,
            'conversion_factor_snapshot' => 1, 'quantity_base' => 1, 'unit_cost_entered' => 25,
            'unit_cost_base' => 25, 'discount' => 0, 'tax' => 0, 'line_total' => 25,
            'product_name_snapshot' => 'صنف مشتريات مشاركة', 'sku_snapshot' => 'PUR-SHARE-SKU',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['invoice_id' => $invoiceId];
    }

    private function location(string $organizationId, string $code): string
    {
        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => $code,
            'name' => 'موقع تجريبي', 'type' => 'warehouse', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function unit(string $organizationId): string
    {
        $id = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => 'SHARE-PCS-'.substr($id, -5),
            'name_ar' => 'قطعة', 'precision' => 0, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
