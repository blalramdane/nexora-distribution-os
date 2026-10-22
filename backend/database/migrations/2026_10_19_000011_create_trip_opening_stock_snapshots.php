<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_stock_opening_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUlid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUlid('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->unique(['organization_id', 'trip_id'], 'trip_opening_org_trip_uq');
            $table->unique(['organization_id', 'id'], 'trip_opening_org_id_uq');
            $table->index(['organization_id', 'location_id', 'captured_at'], 'trip_opening_location_idx');
        });

        Schema::create('trip_stock_opening_snapshot_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('snapshot_id')->constrained('trip_stock_opening_snapshots')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('opening_quantity_base', 19, 6);
            $table->decimal('opening_average_cost', 19, 4)->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'trip_id', 'product_id'], 'trip_opening_item_org_trip_product_uq');
            $table->index(['organization_id', 'snapshot_id'], 'trip_opening_item_snapshot_idx');
        });

        Schema::table('trip_settlements', function (Blueprint $table): void {
            // Existing settlements must not be presented as if a snapshot existed.
            // The existing schema has closing_stock_reference but no opening_stock_reference;
            // omit `after` so this additive migration is portable to MySQL and SQLite.
            $table->string('opening_stock_basis', 24)->default('legacy_zero');
        });

        // SQLite's existing migration harness enforces these references in the
        // application/tests; production MySQL also gets composite tenant FKs.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('trip_stock_opening_snapshots', function (Blueprint $table): void {
                $table->foreign(['organization_id', 'trip_id'], 'trip_opening_org_trip_fk')
                    ->references(['organization_id', 'id'])->on('trips')->restrictOnDelete();
                $table->foreign(['organization_id', 'vehicle_id'], 'trip_opening_org_vehicle_fk')
                    ->references(['organization_id', 'id'])->on('vehicles')->restrictOnDelete();
                $table->foreign(['organization_id', 'location_id'], 'trip_opening_org_location_fk')
                    ->references(['organization_id', 'id'])->on('locations')->restrictOnDelete();
            });
            Schema::table('trip_stock_opening_snapshot_items', function (Blueprint $table): void {
                $table->foreign(['organization_id', 'snapshot_id'], 'trip_opening_item_org_snapshot_fk')
                    ->references(['organization_id', 'id'])->on('trip_stock_opening_snapshots')->restrictOnDelete();
                $table->foreign(['organization_id', 'trip_id'], 'trip_opening_item_org_trip_fk')
                    ->references(['organization_id', 'id'])->on('trips')->restrictOnDelete();
                $table->foreign(['organization_id', 'product_id'], 'trip_opening_item_org_product_fk')
                    ->references(['organization_id', 'id'])->on('products')->restrictOnDelete();
                $table->foreign(['organization_id', 'location_id'], 'trip_opening_item_org_location_fk')
                    ->references(['organization_id', 'id'])->on('locations')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('trip_stock_opening_snapshot_items', function (Blueprint $table): void {
                $table->dropForeign('trip_opening_item_org_snapshot_fk');
                $table->dropForeign('trip_opening_item_org_trip_fk');
                $table->dropForeign('trip_opening_item_org_product_fk');
                $table->dropForeign('trip_opening_item_org_location_fk');
            });
            Schema::table('trip_stock_opening_snapshots', function (Blueprint $table): void {
                $table->dropForeign('trip_opening_org_trip_fk');
                $table->dropForeign('trip_opening_org_vehicle_fk');
                $table->dropForeign('trip_opening_org_location_fk');
            });
        }

        Schema::table('trip_settlements', function (Blueprint $table): void {
            $table->dropColumn('opening_stock_basis');
        });
        Schema::dropIfExists('trip_stock_opening_snapshot_items');
        Schema::dropIfExists('trip_stock_opening_snapshots');
    }
};
