<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('trip_number', 64);
            $table->foreignUlid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignUlid('rep_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('planned')->index();
            $table->date('trip_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignUlid('origin_location_id')->constrained('locations')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'trip_number']);
            $table->index(['organization_id', 'trip_date', 'vehicle_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreign('trip_id')->references('id')->on('trips')->nullOnDelete();
        });
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->foreign('trip_id')->references('id')->nullOnDelete();
        });
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->foreign('trip_id')->references('id')->nullOnDelete();
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('trip_id')->references('id')->nullOnDelete();
        });
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreign('trip_id')->references('id')->nullOnDelete();
        });
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
        });

        Schema::create('trip_customers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->boolean('planned')->default(true);
            $table->string('visit_status', 32)->default('planned');
            $table->timestamps();
            $table->unique(['trip_id', 'customer_id']);
            $table->index(['organization_id', 'trip_id', 'sequence']);
        });

        Schema::create('trip_loads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUlid('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUlid('to_vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->timestamp('loaded_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('trip_load_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_load_id')->constrained('trip_loads')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_base', 19, 6);
            $table->foreignUlid('source_stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();
            
        });

        Schema::create('customer_visits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('planned')->index();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'trip_id', 'customer_id']);
        });

        Schema::create('trip_expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->foreignUlid('expense_id')->constrained('expenses')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['trip_id', 'expense_id']);
        });

        Schema::create('trip_settlements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->constrained('trips')->restrictOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->string('opening_stock_reference', 255)->nullable();
            $table->string('closing_stock_reference', 255)->nullable();
            $table->decimal('opening_cash', 19, 4)->default(0);
            $table->decimal('expected_cash', 19, 4)->default(0);
            $table->decimal('actual_cash', 19, 4)->default(0);
            $table->decimal('stock_variance_value', 19, 4)->default(0);
            $table->decimal('cash_variance', 19, 4)->default(0);
            $table->foreignUlid('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'trip_id']);
        });

        Schema::create('trip_settlement_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('trip_settlement_id')->constrained('trip_settlements')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('opening_quantity_base', 19, 6)->default(0);
            $table->decimal('loaded_quantity_base', 19, 6)->default(0);
            $table->decimal('sold_quantity_base', 19, 6)->default(0);
            $table->decimal('returned_quantity_base', 19, 6)->default(0);
            $table->decimal('transferred_quantity_base', 19, 6)->default(0);
            $table->decimal('adjustment_quantity_base', 19, 6)->default(0);
            $table->decimal('expected_closing_quantity_base', 19, 6)->default(0);
            $table->decimal('actual_closing_quantity_base', 19, 6)->default(0);
            $table->decimal('variance_quantity_base', 19, 6)->default(0);
            $table->timestamps();
            $table->unique(['trip_settlement_id', 'product_id']);
        });

        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('operation_uuid');
            $table->foreignUlid('device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation_type', 128);
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('idempotency_key', 255);
            $table->char('payload_hash', 64);
            $table->string('payload_reference', 255)->nullable();
            $table->timestamp('client_created_at');
            $table->timestamp('received_at')->useCurrent();
            $table->string('status', 32)->default('received')->index();
            $table->uuid('server_transaction_uuid')->nullable();
            $table->string('rejection_code', 64)->nullable();
            $table->json('rejection_details')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'operation_uuid']);
            $table->unique(['organization_id', 'operation_type', 'idempotency_key']);
            $table->index(['organization_id', 'device_id', 'status']);
        });

        Schema::create('sync_conflicts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('sync_operation_id')->constrained('sync_operations')->restrictOnDelete();
            $table->string('conflict_type', 64);
            $table->string('entity_type', 128);
            $table->ulid('entity_id')->nullable();
            $table->string('server_state_reference', 255)->nullable();
            $table->string('client_state_reference', 255)->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->string('resolution', 64)->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('message_outbox', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('event_type', 128);
            $table->string('aggregate_type', 128);
            $table->ulid('aggregate_id');
            $table->string('channel', 64);
            $table->string('destination', 255);
            $table->json('payload_json');
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('available_at')->useCurrent();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'available_at']);
        });

        Schema::create('outbox_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('outbox_id')->constrained('message_outbox')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 32);
            $table->string('provider_reference', 255)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['outbox_id', 'attempt_number']);
        });

        Schema::create('customer_balance_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('total_sales', 19, 4)->default(0);
            $table->decimal('total_returns', 19, 4)->default(0);
            $table->decimal('total_paid', 19, 4)->default(0);
            $table->decimal('outstanding', 19, 4)->default(0);
            $table->timestamp('last_sale_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['organization_id', 'customer_id']);
        });

        Schema::create('supplier_balance_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->decimal('total_purchases', 19, 4)->default(0);
            $table->decimal('total_returns', 19, 4)->default(0);
            $table->decimal('total_paid', 19, 4)->default(0);
            $table->decimal('outstanding', 19, 4)->default(0);
            $table->timestamp('last_purchase_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['organization_id', 'supplier_id']);
        });

        Schema::create('dashboard_daily_summaries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->date('business_date');
            $table->decimal('sales_total', 19, 4)->default(0);
            $table->decimal('purchase_total', 19, 4)->default(0);
            $table->decimal('collections_total', 19, 4)->default(0);
            $table->decimal('expenses_total', 19, 4)->default(0);
            $table->decimal('gross_profit', 19, 4)->default(0);
            $table->decimal('net_profit', 19, 4)->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['organization_id', 'business_date']);
        });
        DB::statement("ALTER TABLE trip_load_items ADD CONSTRAINT chk_trip_load_item_qty CHECK (quantity_base > 0)");
    }

    public function down(): void
    {
        foreach (['dashboard_daily_summaries','supplier_balance_summaries','customer_balance_summaries','outbox_attempts','message_outbox','sync_conflicts','sync_operations','trip_settlement_lines','trip_settlements','trip_expenses','customer_visits','trip_load_items','trip_loads','trip_customers','expenses','payments','sales_returns','sales_invoices','purchase_returns','purchase_invoices','trips'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};