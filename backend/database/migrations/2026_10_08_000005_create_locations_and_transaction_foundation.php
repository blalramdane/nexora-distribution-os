<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('type', 32);
            $table->string('status', 32)->default('active')->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('address')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'location_id']);
        });

        Schema::create('vehicles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('plate_number', 64)->nullable();
            $table->string('name');
            $table->string('vehicle_type', 64)->nullable();
            $table->foreignUlid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'location_id']);
        });

        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('document_type', 64);
            $table->string('prefix', 32)->nullable();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->string('reset_policy', 32)->default('never');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'document_type']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('operation_type', 128);
            $table->string('idempotency_key', 255);
            $table->char('request_fingerprint', 64);
            $table->string('status', 32)->default('processing')->index();
            $table->string('response_reference', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->unique(['organization_id', 'operation_type', 'idempotency_key'], 'idem_org_op_key_uq');
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('action', 128);
            $table->string('entity_type', 128);
            $table->string('entity_id', 128)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->json('before_summary')->nullable();
            $table->json('after_summary')->nullable();
            $table->json('metadata_json')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'entity_type', 'entity_id']);
            $table->index(['organization_id', 'actor_user_id', 'created_at']);
        });

        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('type', 32);
            $table->char('currency', 3)->default('EGP');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('transaction_uuid');
            $table->foreignUlid('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('party_type', 64)->nullable();
            $table->ulid('party_id')->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->char('currency', 3)->default('EGP');
            $table->string('source_document_type', 64)->nullable();
            $table->ulid('source_document_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'account_id', 'occurred_at']);
            $table->index(['organization_id', 'transaction_uuid'], 'ledger_tx_idx');



        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->uuid('transaction_uuid');
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('movement_type', 64);
            $table->decimal('quantity_base', 19, 6);
            $table->decimal('unit_cost', 19, 4)->default(0);
            $table->string('source_document_type', 64)->nullable();
            $table->ulid('source_document_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('posted_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignUlid('trip_id')->nullable();
            $table->string('reference', 255)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'product_id', 'location_id', 'occurred_at'], 'stock_mov_org_prod_loc_time_idx');
            $table->index(['organization_id', 'source_document_type', 'source_document_id'], 'stock_mov_org_source_idx');
            $table->index(['organization_id', 'transaction_uuid'], 'stock_mov_tx_idx');


        });

        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('quantity_base', 19, 6)->default(0);
            $table->decimal('reserved_quantity_base', 19, 6)->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['organization_id', 'product_id', 'location_id']);
        });
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_entry_side CHECK ((debit = 0 AND credit > 0) OR (credit = 0 AND debit > 0))");
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_entry_nonnegative CHECK (debit >= 0 AND credit >= 0)");
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement_quantity_nonzero CHECK (quantity_base <> 0)");
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement_cost_nonnegative CHECK (unit_cost >= 0)");
    }




    public function down(): void
    {
        foreach (['stock_balances','stock_movements','ledger_entries','ledger_accounts','audit_logs','idempotency_keys','document_sequences','vehicles','warehouses','locations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};