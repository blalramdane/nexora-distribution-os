<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_stock_counts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUlid('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('submitted')->index();
            $table->string('idempotency_key', 255)->nullable();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('variance_items_count')->default(0);
            $table->json('items_json');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'trip_id', 'created_at'], 'field_stock_counts_trip_idx');
            $table->unique(['organization_id', 'idempotency_key'], 'field_counts_org_idempotency_uq');
        });

        if (DB::getDriverName() === 'mysql') {
            Schema::table('field_stock_counts', function (Blueprint $table): void {
                $table->foreign(['organization_id', 'trip_id'], 'field_counts_org_trip_fk')
                    ->references(['organization_id', 'id'])->on('trips')->restrictOnDelete();
                $table->foreign(['organization_id', 'submitted_by'], 'field_counts_org_user_fk')
                    ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('field_stock_counts')) {
            Schema::table('field_stock_counts', function (Blueprint $table): void {
                $table->dropForeign('field_counts_org_trip_fk');
                $table->dropForeign('field_counts_org_user_fk');
            });
        }
        Schema::dropIfExists('field_stock_counts');
    }
};
