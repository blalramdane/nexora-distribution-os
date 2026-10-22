<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('document_number', 64);
            $table->foreignUlid('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUlid('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 32)->default('posted')->index();
            $table->string('reason', 255);
            $table->string('idempotency_key', 255);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'document_number']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['organization_id', 'from_location_id', 'posted_at'], 'stock_transfer_from_time_idx');
            $table->index(['organization_id', 'to_location_id', 'posted_at'], 'stock_transfer_to_time_idx');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_base', 19, 6);
            $table->decimal('unit_cost', 19, 4)->default(0);
            $table->foreignUlid('outbound_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignUlid('inbound_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_id']);
            $table->index(['organization_id', 'product_id', 'stock_transfer_id'], 'stock_transfer_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
