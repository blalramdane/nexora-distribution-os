<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_product_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('product_packagings')->nullOnDelete();
            $table->string('unit_key', 32);
            $table->decimal('unit_price', 19, 4);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'customer_id', 'product_id', 'unit_key'], 'customer_product_price_unit_uq');
            $table->index(['organization_id', 'customer_id', 'active'], 'customer_product_prices_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_product_prices');
    }
};
