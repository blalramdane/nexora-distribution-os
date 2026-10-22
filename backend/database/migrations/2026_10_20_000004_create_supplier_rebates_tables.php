<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_rebates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->ulid('supplier_id');
            $table->string('document_number', 64);
            $table->date('period_from');
            $table->date('period_to');
            $table->char('currency', 3)->default('EGP');
            $table->unsignedInteger('invoice_count')->default(0);
            $table->decimal('calculation_base', 19, 4)->default(0);
            $table->decimal('rate_percent', 9, 4);
            $table->decimal('rebate_amount', 19, 4)->default(0);
            // This is a tracked supplier credit claim, not a cash receipt or AP journal.
            $table->string('status', 24)->default('accrued')->index();
            $table->text('notes')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'id'], 'supplier_rebates_org_id_uq');
            $table->unique(['organization_id', 'document_number'], 'supplier_rebates_org_doc_uq');
            $table->index(['organization_id', 'supplier_id', 'status', 'created_at'], 'supplier_rebates_lookup_idx');
        });
        if (DB::getDriverName() === 'mysql') {
            Schema::table('supplier_rebates', function (Blueprint $table): void {
                $table->foreign(['organization_id', 'supplier_id'], 'supplier_rebates_org_supplier_fk')
                    ->references(['organization_id', 'id'])->on('suppliers')->restrictOnDelete();
                $table->foreign(['organization_id', 'created_by'], 'supplier_rebates_org_creator_fk')
                    ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
            });
        } else {
            Schema::table('supplier_rebates', function (Blueprint $table): void {
                $table->foreign('supplier_id', 'supplier_rebates_supplier_fk')
                    ->references('id')->on('suppliers')->restrictOnDelete();
                $table->foreign('created_by', 'supplier_rebates_creator_fk')
                    ->references('id')->on('users')->restrictOnDelete();
            });
        }

        Schema::create('supplier_rebate_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->ulid('supplier_rebate_id');
            $table->ulid('purchase_invoice_id');
            $table->string('invoice_number_snapshot', 64);
            $table->date('invoice_date_snapshot');
            $table->decimal('invoice_total_snapshot', 19, 4);
            $table->decimal('return_total_snapshot', 19, 4)->default(0);
            $table->timestamps();
            // A posted purchase invoice may contribute to at most one supplier rebate.
            $table->unique(['organization_id', 'purchase_invoice_id'], 'supplier_rebate_invoice_once_uq');
            $table->index(['organization_id', 'supplier_rebate_id'], 'supplier_rebate_invoice_parent_idx');
        });
        if (DB::getDriverName() === 'mysql') {
            Schema::table('supplier_rebate_invoices', function (Blueprint $table): void {
                $table->foreign(['organization_id', 'supplier_rebate_id'], 'supplier_rebate_invoices_org_rebate_fk')
                    ->references(['organization_id', 'id'])->on('supplier_rebates')->restrictOnDelete();
                $table->foreign(['organization_id', 'purchase_invoice_id'], 'supplier_rebate_invoices_org_purchase_fk')
                    ->references(['organization_id', 'id'])->on('purchase_invoices')->restrictOnDelete();
            });
        } else {
            Schema::table('supplier_rebate_invoices', function (Blueprint $table): void {
                $table->foreign('supplier_rebate_id', 'supplier_rebate_invoices_rebate_fk')
                    ->references('id')->on('supplier_rebates')->restrictOnDelete();
                $table->foreign('purchase_invoice_id', 'supplier_rebate_invoices_purchase_fk')
                    ->references('id')->on('purchase_invoices')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_rebate_invoices');
        Schema::dropIfExists('supplier_rebates');
    }
};
