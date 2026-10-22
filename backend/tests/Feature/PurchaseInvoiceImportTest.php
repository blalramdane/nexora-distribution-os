<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\GrantsTestPermissions;
use Tests\TestCase;

class PurchaseInvoiceImportTest extends TestCase
{
    use GrantsTestPermissions;
    use RefreshDatabase;

    private array $uploadedPaths = [];

    protected function tearDown(): void
    {
        $disk = Storage::build(['driver' => 'local', 'root' => storage_path('app'), 'throw' => true]);
        foreach ($this->uploadedPaths as $path) {
            $disk->delete($path);
        }

        parent::tearDown();
    }

    public function test_uploaded_purchase_invoice_is_private_reviewed_and_linked_to_exactly_one_posting(): void
    {
        [$organization, $user] = $this->organizationUser('Invoice Import Tenant');
        $unitId = $this->unit($organization->id);
        $productId = $this->product($organization->id, $unitId);
        $supplierId = $this->supplier($organization->id);
        $locationId = $this->location($organization->id);
        $this->actingAs($user, 'sanctum');

        $imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/6ocAAAAASUVORK5CYII=', true);
        $this->assertNotFalse($imageBytes);
        $file = UploadedFile::fake()->createWithContent('supplier-invoice.png', $imageBytes);
        $upload = $this->post('/api/v1/purchase-imports', ['documents' => [$file]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['documents' => [['id', 'original_name', 'mime_type', 'status']]])
            ->json();

        $documentId = $upload['documents'][0]['id'];
        $document = DB::table('purchase_invoice_imports')->where('organization_id', $organization->id)->where('id', $documentId)->first();
        $this->assertNotNull($document);
        $this->assertSame('uploaded', $document->status);
        $this->assertStringStartsWith('private/purchase-invoice-imports/'.$organization->id.'/', $document->storage_path);
        $this->uploadedPaths[] = $document->storage_path;
        $this->get('/api/v1/purchase-imports/'.$documentId.'/file', ['Accept' => '*/*'])->assertOk();

        $this->putJson('/api/v1/purchase-imports/'.$documentId.'/extraction', [
            'extracted_text' => "Invoice No: TEST-1001\nTotal 10.00",
            'extraction_json' => ['invoice_number' => 'TEST-1001', 'invoice_date' => now()->toDateString(), 'total' => '10.00'],
            'ocr_engine' => 'feature-test',
            'ocr_confidence' => 92.5,
        ])->assertOk()->assertJsonPath('status', 'needs_review');

        $purchase = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'supplier_invoice_number' => 'TEST-1001',
            'invoice_date' => now()->toDateString(),
            'import_document_id' => $documentId,
            'idempotency_key' => 'import-post-'.$documentId,
            'items' => [['product_id' => $productId, 'quantity' => 2, 'unit_id' => $unitId, 'unit_cost' => 5]],
        ])->assertCreated()->json();

        $this->assertDatabaseHas('purchase_invoice_imports', [
            'id' => $documentId,
            'organization_id' => $organization->id,
            'status' => 'posted',
            'purchase_invoice_id' => $purchase['id'],
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'location_id' => $locationId,
            'quantity_base' => '2.000000',
        ]);
        $this->assertSame(1, DB::table('purchase_invoices')->where('organization_id', $organization->id)->where('id', $purchase['id'])->count());
        $this->assertSame(1, DB::table('stock_movements')->where('organization_id', $organization->id)->where('source_document_type', 'purchase_invoice')->where('source_document_id', $purchase['id'])->count());

        // A delayed OCR/review save must never move a posted import back to
        // needs_review or replace the extraction linked to the posted invoice.
        $this->putJson('/api/v1/purchase-imports/'.$documentId.'/extraction', [
            'extracted_text' => 'late OCR result that must not overwrite the posted source',
            'extraction_json' => ['invoice_number' => 'LATE-OVERWRITE', 'total' => '9999.00'],
            'ocr_engine' => 'late-feature-test',
            'ocr_confidence' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors(['document']);
        $this->assertDatabaseHas('purchase_invoice_imports', [
            'id' => $documentId,
            'organization_id' => $organization->id,
            'status' => 'posted',
            'purchase_invoice_id' => $purchase['id'],
            'extracted_text' => "Invoice No: TEST-1001\nTotal 10.00",
        ]);

        $payables = $this->getJson('/api/v1/purchases/open-balances')->assertOk()->json();
        $this->assertSame(10.0, (float) $payables['total_payable']);
        $this->assertSame(10.0, (float) $payables['invoices'][0]['remaining_payable']);
        $filteredPayables = $this->getJson('/api/v1/purchases/open-balances?q=TEST-1001&per_page=10')->assertOk()->json();
        $this->assertSame(1, $filteredPayables['pagination']['total']);
        $this->assertSame('TEST-1001', $filteredPayables['invoices'][0]['supplier_invoice_number']);

        $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'import_document_id' => $documentId,
            'idempotency_key' => 'try-repost-'.$documentId,
            'items' => [['product_id' => $productId, 'quantity' => 2, 'unit_id' => $unitId, 'unit_cost' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['import_document_id']);
        $this->assertSame(1, DB::table('purchase_invoices')->where('organization_id', $organization->id)->count());

        // Reuse the exact bytes rather than a newly generated image to exercise content hashing.
        $duplicate = UploadedFile::fake()->createWithContent('same-content.png', $imageBytes);
        $this->post('/api/v1/purchase-imports', ['documents' => [$duplicate]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('documents.0.id', $documentId)
            ->assertJsonPath('documents.0.duplicate', true);
        $this->assertSame(1, DB::table('purchase_invoice_imports')->where('organization_id', $organization->id)->count());

        [$foreignOrganization, $foreignUser] = $this->organizationUser('Foreign Invoice Import Tenant');
        $this->actingAs($foreignUser, 'sanctum');
        $this->getJson('/api/v1/purchase-imports')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/purchases/open-balances')->assertOk()->assertJsonPath('total_payable', 0);
        $this->get('/api/v1/purchase-imports/'.$documentId.'/file')->assertNotFound();
        $this->assertNotSame($organization->id, $foreignOrganization->id);
    }

    public function test_new_catalog_products_from_import_are_created_atomically_with_the_purchase_receipt(): void
    {
        [$organization, $user] = $this->organizationUser('New Invoice Product Tenant');
        $unitId = $this->unit($organization->id);
        $existingProductId = $this->product($organization->id, $unitId);
        $supplierId = $this->supplier($organization->id);
        $locationId = $this->location($organization->id);
        $this->actingAs($user, 'sanctum');

        $imageBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/6ocAAAAASUVORK5CYII=', true);
        $this->assertNotFalse($imageBytes);
        $file = UploadedFile::fake()->createWithContent('new-products-invoice.png', $imageBytes."\nnew-invoice-content");
        $upload = $this->post('/api/v1/purchase-imports', ['documents' => [$file]], ['Accept' => 'application/json'])
            ->assertCreated()->json();
        $documentId = $upload['documents'][0]['id'];
        $document = DB::table('purchase_invoice_imports')->where('organization_id', $organization->id)->where('id', $documentId)->first();
        $this->assertNotNull($document);
        $this->uploadedPaths[] = $document->storage_path;

        $this->putJson('/api/v1/purchase-imports/'.$documentId.'/extraction', [
            'extracted_text' => "فاتورة مشتريات جديدة\nمكيف تورنيدو جديد\nالإجمالي 150.00",
            'extraction_json' => ['invoice_number' => 'NEW-ITEM-INV-01', 'invoice_date' => now()->toDateString(), 'total' => '150.00'],
            'ocr_engine' => 'feature-test',
            'ocr_confidence' => 90,
        ])->assertOk();

        $basePayload = [
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'supplier_invoice_number' => 'NEW-ITEM-INV-01',
            'invoice_date' => now()->toDateString(),
            'import_document_id' => $documentId,
            'discount' => 0,
            'tax' => 0,
        ];

        // A later invalid line must roll back a product inserted for an earlier
        // line in the same posting attempt.
        $invalidAttempt = $this->postJson('/api/v1/purchases', [
            ...$basePayload,
            'idempotency_key' => 'new-product-rollback-'.$documentId,
            'items' => [
                [
                    'product_id' => null,
                    'quantity' => 1,
                    'unit_cost' => 50,
                    'new_product' => ['sku' => 'ROLLBACK-NEW-001', 'name_ar' => 'صنف يجب أن يتراجع', 'base_unit_id' => $unitId],
                ],
                [
                    'product_id' => null,
                    'quantity' => 1,
                    'unit_cost' => 25,
                    'new_product' => ['sku' => 'IMPORT-SKU', 'name_ar' => 'كود مكرر', 'base_unit_id' => $unitId],
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.1.new_product.sku']);

        $this->assertDatabaseMissing('products', ['organization_id' => $organization->id, 'sku' => 'ROLLBACK-NEW-001']);
        $this->assertSame(0, DB::table('purchase_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('organization_id', $organization->id)->count());
        $this->assertSame('needs_review', DB::table('purchase_invoice_imports')->where('id', $documentId)->value('status'));

        [$foreignOrganization] = $this->organizationUser('Foreign Unit for New Product');
        $foreignUnitId = $this->unit($foreignOrganization->id);
        $this->postJson('/api/v1/purchases', [
            ...$basePayload,
            'idempotency_key' => 'new-product-foreign-unit-'.$documentId,
            'items' => [[
                'product_id' => null,
                'quantity' => 1,
                'unit_cost' => 50,
                'new_product' => ['sku' => 'FOREIGN-UNIT-NEW-001', 'name_ar' => 'وحدة خارج المؤسسة', 'base_unit_id' => $foreignUnitId],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.new_product.base_unit_id']);
        $this->assertDatabaseMissing('products', ['organization_id' => $organization->id, 'sku' => 'FOREIGN-UNIT-NEW-001']);
        $this->assertSame(0, DB::table('purchase_invoices')->where('organization_id', $organization->id)->count());

        $payload = [
            ...$basePayload,
            'idempotency_key' => 'new-product-success-'.$documentId,
            'items' => [[
                'product_id' => null,
                'quantity' => 3,
                'unit_cost' => 50,
                'unit_id' => $unitId,
                'new_product' => [
                    'sku' => 'NEW-ATOMIC-001',
                    'supplier_sku' => 'SUP-MINI-01',
                    'supplier_name' => 'مكيف جديد تورنيدو',
                    'name_ar' => 'مكيف تورنيدو جديد',
                    'name_en' => 'New Tornado AC',
                    'brand' => 'Tornado',
                    'base_unit_id' => $unitId,
                    'default_piece_price' => 70,
                ],
            ]],
        ];

        $purchase = $this->postJson('/api/v1/purchases', $payload)
            ->assertCreated()
            ->assertJsonPath('created_products.0.sku', 'NEW-ATOMIC-001')
            ->assertJsonPath('created_products.0.name_ar', 'مكيف تورنيدو جديد')
            ->json();

        $newProduct = DB::table('products')->where('organization_id', $organization->id)->where('sku', 'NEW-ATOMIC-001')->first();
        $this->assertNotNull($newProduct);
        $this->assertSame('مكيف تورنيدو جديد', $newProduct->name_ar);
        $this->assertSame(50.0, (float) $newProduct->default_cost);
        $this->assertSame(70.0, (float) $newProduct->default_piece_price);
        $this->assertDatabaseHas('supplier_products', [
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'product_id' => $newProduct->id,
            'supplier_sku' => 'SUP-MINI-01',
        ]);
        $this->assertDatabaseHas('product_aliases', [
            'organization_id' => $organization->id,
            'product_id' => $newProduct->id,
            'normalized_alias' => 'مكيف جديد تورنيدو',
            'source' => 'purchase_invoice_import',
        ]);
        $this->assertDatabaseHas('purchase_invoice_items', [
            'organization_id' => $organization->id,
            'purchase_invoice_id' => $purchase['id'],
            'product_id' => $newProduct->id,
            'sku_snapshot' => 'NEW-ATOMIC-001',
            'product_name_snapshot' => 'مكيف تورنيدو جديد',
        ]);
        $this->assertDatabaseHas('stock_balances', [
            'organization_id' => $organization->id,
            'product_id' => $newProduct->id,
            'location_id' => $locationId,
            'quantity_base' => '3.000000',
        ]);
        $this->assertDatabaseHas('purchase_invoice_imports', [
            'id' => $documentId,
            'status' => 'posted',
            'purchase_invoice_id' => $purchase['id'],
        ]);

        // A retry with the identical key replays the invoice and cannot create
        // the new product, invoice or stock movement a second time.
        $replayed = $this->postJson('/api/v1/purchases', $payload)
            ->assertCreated()
            ->assertJsonPath('id', $purchase['id'])
            ->json();
        $this->assertSame($purchase['id'], $replayed['id']);
        $this->assertSame(2, DB::table('products')->where('organization_id', $organization->id)->count());
        $this->assertSame(1, DB::table('purchase_invoices')->where('organization_id', $organization->id)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('organization_id', $organization->id)->where('source_document_id', $purchase['id'])->count());
        $this->assertNotSame($existingProductId, $newProduct->id);
    }

    public function test_new_product_purchase_pack_keeps_carton_quantity_and_converts_cost_to_base_piece(): void
    {
        [$organization, $user] = $this->organizationUser('Purchase Pack Conversion Tenant');
        $pieceUnitId = $this->unit($organization->id);
        $cartonUnitId = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $cartonUnitId, 'organization_id' => $organization->id,
            'code' => 'IMPORT-CTN', 'name_ar' => 'كرتونة', 'precision' => 0,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplierId = $this->supplier($organization->id);
        $locationId = $this->location($organization->id);
        $this->actingAs($user, 'sanctum');

        $purchase = $this->postJson('/api/v1/purchases', [
            'supplier_id' => $supplierId,
            'location_id' => $locationId,
            'supplier_invoice_number' => 'RAF-10936-TEST',
            'invoice_date' => '2026-10-14',
            'idempotency_key' => 'purchase-pack-conversion-' . Str::uuid(),
            'discount' => 0,
            'tax' => 0,
            'items' => [[
                'product_id' => null,
                'quantity' => 3,
                'unit_cost' => 10980,
                'new_product' => [
                    'sku' => 'RAF-7937-TEST',
                    'supplier_sku' => 'R.7937',
                    'supplier_name' => 'كاتيل جودة عالية صحي RAF R.7937 1.5L متعدد الاستخدام',
                    'name_ar' => 'كاتيل جودة عالية صحي RAF R.7937 1.5L متعدد الاستخدام',
                    'brand' => 'RAF',
                    'base_unit_id' => $pieceUnitId,
                    'purchase_packaging_name' => 'كرتونة 12 قطعة',
                    'purchase_packaging_unit_id' => $cartonUnitId,
                    'purchase_conversion_to_base' => 12,
                ],
            ]],
        ])->assertCreated()->json();

        $product = DB::table('products')->where('organization_id', $organization->id)->where('sku', 'RAF-7937-TEST')->first();
        $this->assertNotNull($product);
        $this->assertSame(915.0, (float) $product->default_cost);
        $pack = DB::table('product_packagings')->where('organization_id', $organization->id)->where('product_id', $product->id)->first();
        $this->assertNotNull($pack);
        $this->assertSame('كرتونة 12 قطعة', $pack->name_ar);
        $this->assertSame($cartonUnitId, $pack->unit_id);
        $this->assertSame(12.0, (float) $pack->conversion_to_base);
        $this->assertSame(10980.0, (float) $pack->purchase_price);
        $this->assertSame(1, (int) $pack->is_default_purchase_unit);

        $item = DB::table('purchase_invoice_items')->where('organization_id', $organization->id)->where('purchase_invoice_id', $purchase['id'])->first();
        $this->assertSame($pack->id, $item->packaging_id);
        $this->assertSame(3.0, (float) $item->entered_quantity);
        $this->assertSame(12.0, (float) $item->conversion_factor_snapshot);
        $this->assertSame(36.0, (float) $item->quantity_base);
        $this->assertSame(10980.0, (float) $item->unit_cost_entered);
        $this->assertSame(915.0, (float) $item->unit_cost_base);
        $this->assertSame(32940.0, (float) DB::table('purchase_invoices')->where('id', $purchase['id'])->value('total'));
        $this->assertSame(36.0, (float) DB::table('stock_balances')->where('organization_id', $organization->id)->where('product_id', $product->id)->where('location_id', $locationId)->value('quantity_base'));
        $this->assertSame(36.0, (float) DB::table('stock_movements')->where('organization_id', $organization->id)->where('source_document_type', 'purchase_invoice')->where('source_document_id', $purchase['id'])->sum('quantity_base'));
    }

    private function organizationUser(string $name): array
    {
        $organization = Organization::query()->create([
            'name' => $name, 'default_currency' => 'EGP', 'timezone' => 'Africa/Cairo',
            'country_code' => 'EG', 'status' => 'active',
        ]);
        $user = User::query()->create([
            'organization_id' => $organization->id, 'name' => $name.' Manager',
            'email' => Str::uuid().'@nexora.test', 'phone' => '01000000001',
            'password' => 'test-password', 'status' => 'active',
        ]);
        $this->grantTestPermissions($user);
        return [$organization, $user];
    }

    private function unit(string $organizationId): string
    {
        $id = (string) Str::ulid();
        DB::table('units')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => 'IMPORT-PCS',
            'name_ar' => 'قطعة', 'precision' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function product(string $organizationId, string $unitId): string
    {
        $id = (string) Str::ulid();
        DB::table('products')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'base_unit_id' => $unitId,
            'sku' => 'IMPORT-SKU', 'name_ar' => 'صنف استيراد', 'default_cost' => 5,
            'default_piece_price' => 8, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function supplier(string $organizationId): string
    {
        $id = (string) Str::ulid();
        DB::table('suppliers')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => 'IMPORT-SUP',
            'name' => 'مورد استيراد', 'normalized_name' => 'مورد استيراد', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function location(string $organizationId): string
    {
        $id = (string) Str::ulid();
        DB::table('locations')->insert([
            'id' => $id, 'organization_id' => $organizationId, 'code' => 'IMPORT-WH',
            'name' => 'المخزن', 'type' => 'warehouse', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }
}
