<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_source_layers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUlid('source_purchase_invoice_id')->nullable()->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignUlid('source_purchase_invoice_item_id')->nullable()->constrained('purchase_invoice_items')->restrictOnDelete();
            $table->foreignUlid('parent_layer_id')->nullable()->constrained('stock_source_layers')->restrictOnDelete();
            $table->string('origin_type', 32);
            $table->string('origin_document_type', 64)->nullable();
            $table->string('origin_document_id', 64)->nullable();
            $table->decimal('quantity_received_base', 19, 6);
            $table->decimal('quantity_remaining_base', 19, 6);
            $table->decimal('unit_cost_base', 19, 4)->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'product_id', 'location_id', 'quantity_remaining_base'], 'stock_src_org_product_location_idx');
            $table->index(['organization_id', 'source_purchase_invoice_id'], 'stock_src_org_purchase_idx');
            $table->index(['organization_id', 'source_purchase_invoice_item_id'], 'stock_src_org_purchase_item_idx');
        });

        Schema::create('stock_source_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('source_layer_id')->constrained('stock_source_layers')->restrictOnDelete();
            $table->foreignUlid('destination_layer_id')->nullable()->constrained('stock_source_layers')->restrictOnDelete();
            $table->foreignUlid('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->foreignUlid('reverses_allocation_id')->nullable()->constrained('stock_source_allocations')->restrictOnDelete();
            $table->foreignUlid('source_purchase_invoice_id')->nullable()->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignUlid('source_purchase_invoice_item_id')->nullable()->constrained('purchase_invoice_items')->restrictOnDelete();
            $table->string('source_document_type', 64);
            $table->string('source_document_id', 64);
            $table->string('source_document_item_id', 64)->nullable();
            $table->string('allocation_type', 32);
            $table->decimal('quantity_base', 19, 6);
            $table->decimal('unit_cost_base', 19, 4)->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'source_purchase_invoice_item_id', 'allocation_type'], 'stock_alloc_source_item_type_idx');
            $table->index(['organization_id', 'source_document_type', 'source_document_id'], 'stock_alloc_source_doc_idx');
            $table->index(['organization_id', 'stock_movement_id'], 'stock_alloc_movement_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_source_allocations');
        Schema::dropIfExists('stock_source_layers');
    }
};
