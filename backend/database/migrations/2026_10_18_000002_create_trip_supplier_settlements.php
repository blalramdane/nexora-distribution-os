<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_supplier_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUlid('trip_settlement_id')->constrained('trip_settlements')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_name_snapshot', 255);
            $table->decimal('sold_quantity_base', 19, 6)->default(0);
            $table->decimal('returned_quantity_base', 19, 6)->default(0);
            $table->decimal('net_quantity_base', 19, 6)->default(0);
            $table->decimal('sold_source_cost', 19, 4)->default(0);
            $table->decimal('returned_source_cost', 19, 4)->default(0);
            $table->decimal('settlement_amount', 19, 4)->default(0);
            $table->json('items_json');
            $table->timestamps();
            $table->unique(['organization_id', 'trip_settlement_id', 'supplier_id'], 'trip_supplier_settle_uq');
            $table->index(['organization_id', 'supplier_id', 'created_at'], 'trip_supplier_settle_supplier_idx');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUlid('trip_supplier_settlement_id')->nullable()
                ->constrained('trip_supplier_settlements')->restrictOnDelete();
            $table->index(['organization_id', 'trip_supplier_settlement_id'], 'payments_trip_supplier_settlement_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_trip_supplier_settlement_idx');
            $table->dropForeign(['trip_supplier_settlement_id']);
            $table->dropColumn('trip_supplier_settlement_id');
        });

        Schema::dropIfExists('trip_supplier_settlements');
    }
};
