<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignUlid('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->string('sku', 128);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->decimal('default_cost', 19, 4)->default(0);
            $table->decimal('default_piece_price', 19, 4)->default(0);
            $table->string('tax_code', 64)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'name_ar']);
        });

        Schema::create('product_packagings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('name_ar');
            $table->decimal('conversion_to_base', 19, 6);
            $table->string('barcode_primary', 128)->nullable();
            $table->decimal('sale_price', 19, 4)->nullable();
            $table->decimal('purchase_price', 19, 4)->nullable();
            $table->boolean('is_default_sale_unit')->default(false);
            $table->boolean('is_default_purchase_unit')->default(false);
            $table->boolean('active')->default(true)->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'product_id', 'unit_id', 'name_ar']);
            $table->index(['organization_id', 'product_id', 'active']);
            $table->check('conversion_to_base > 0');
        });

        Schema::create('product_barcodes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('product_packagings')->nullOnDelete();
            $table->string('barcode', 128);
            $table->string('barcode_type', 32)->default('ean');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'barcode']);
            $table->index(['organization_id', 'product_id']);
        });

        Schema::create('product_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('alias', 255);
            $table->string('normalized_alias', 255);
            $table->string('source', 64)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->index(['organization_id', 'normalized_alias']);
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->string('tax_identifier', 128)->nullable();
            $table->unsignedInteger('credit_terms_days')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'phone']);
        });

        Schema::create('supplier_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('supplier_sku', 128)->nullable();
            $table->string('supplier_name')->nullable();
            $table->foreignUlid('preferred_packaging_id')->nullable()->constrained('product_packagings')->nullOnDelete();
            $table->decimal('last_cost', 19, 4)->nullable();
            $table->timestamp('last_purchase_at')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'supplier_id', 'product_id']);
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('normalized_name');
            $table->string('phone', 32)->nullable();
            $table->string('alternate_phone', 32)->nullable();
            $table->foreignUlid('governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->foreignUlid('center_id')->nullable()->constrained('centers')->nullOnDelete();
            $table->foreignUlid('city_area_id')->nullable()->constrained('cities_areas')->nullOnDelete();
            $table->text('address_text')->nullable();
            $table->decimal('credit_limit', 19, 4)->default(0);
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->string('status', 32)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'normalized_name']);
            $table->index(['organization_id', 'phone']);
            $table->index(['organization_id', 'governorate_id', 'center_id', 'city_area_id']);
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('label', 64);
            $table->foreignUlid('governorate_id')->nullable()->constrained('governorates')->nullOnDelete();
            $table->foreignUlid('center_id')->nullable()->constrained('centers')->nullOnDelete();
            $table->foreignUlid('city_area_id')->nullable()->constrained('cities_areas')->nullOnDelete();
            $table->text('address_text')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('location_accuracy', 10, 2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->index(['organization_id', 'customer_id']);
        });

        Schema::create('customer_location_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUlid('address_id')->nullable()->constrained('customer_addresses')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->foreignUlid('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('source', 32)->default('field');
            $table->timestamp('captured_at');
            $table->string('verification_status', 32)->default('pending');
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        foreach (['customer_location_events','customer_addresses','customers','supplier_products','suppliers','product_aliases','product_barcodes','product_packagings','products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};