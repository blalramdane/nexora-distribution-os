<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbox_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('document_number', 64);
            $table->string('transaction_type', 32);
            $table->string('direction', 16);
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('EGP');
            $table->date('transaction_date');
            $table->foreignUlid('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->boolean('source_is_general_cash')->default(false);
            $table->foreignUlid('destination_financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('beneficiary_type', 32)->nullable();
            $table->foreignUlid('beneficiary_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('beneficiary_name_snapshot', 255)->nullable();
            $table->foreignUlid('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
            $table->foreignUlid('trip_id')->nullable()->constrained('trips')->restrictOnDelete();
            $table->string('category', 128);
            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('posted')->index();
            $table->string('idempotency_key', 255);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'document_number'], 'cashbox_org_document_uq');
            $table->unique(['organization_id', 'idempotency_key'], 'cashbox_org_idem_uq');
            $table->index(['organization_id', 'transaction_date', 'transaction_type'], 'cashbox_org_date_type_idx');
            $table->index(['organization_id', 'financial_account_id', 'transaction_date'], 'cashbox_org_account_date_idx');
            $table->index(['organization_id', 'trip_id', 'transaction_date'], 'cashbox_org_trip_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbox_transactions');
    }
};
