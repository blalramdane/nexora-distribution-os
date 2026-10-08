<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('document_number', 64);
            $table->string('supplier_invoice_number', 128)->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->date('invoice_date');
            $table->timestamp('posted_at')->nullable();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount', 19, 4)->default(0);
            $table->decimal('tax', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('paid_amount', 19, 4)->default(0);
            $table->char('currency', 3)->default('EGP');
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_number']);
            $table->index(['organization_id', 'supplier_id', 'invoice_date']);
        });

        Schema::create('purchase_invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('product_packagings')->nullOnDelete();
            $table->foreignUlid('entered_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('entered_quantity', 19, 6);
            $table->decimal('conversion_factor_snapshot', 19, 6);
            $table->decimal('quantity_base', 19, 6);
            $table->decimal('unit_cost_entered', 19, 4);
            $table->decimal('unit_cost_base', 19, 4);
            $table->decimal('discount', 19, 4)->default(0);
            $table->decimal('tax', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 128);
            $table->timestamps();
            $table->index(['organization_id', 'purchase_invoice_id']);



        });

        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUlid('original_purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->string('document_number', 64);
            $table->string('status', 32)->default('draft')->index();
            $table->date('return_date');
            $table->timestamp('posted_at')->nullable();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount', 19, 4)->default(0);
            $table->decimal('tax', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->char('currency', 3)->default('EGP');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_number']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('purchase_return_id')->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('original_purchase_invoice_item_id')->nullable()->constrained('purchase_invoice_items')->nullOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('product_packagings')->nullOnDelete();
            $table->decimal('entered_quantity', 19, 6);
            $table->decimal('conversion_factor_snapshot', 19, 6);
            $table->decimal('quantity_base', 19, 6);
            $table->decimal('unit_cost_base', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->timestamps();



        });

        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUlid('source_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUlid('trip_id')->nullable();
            $table->string('document_number', 64);
            $table->string('status', 32)->default('draft')->index();
            $table->date('invoice_date');
            $table->timestamp('posted_at')->nullable();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount', 19, 4)->default(0);
            $table->decimal('tax', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('paid_amount', 19, 4)->default(0);
            $table->decimal('balance_due', 19, 4)->default(0);
            $table->char('currency', 3)->default('EGP');
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_number']);
            $table->index(['organization_id', 'customer_id', 'invoice_date']);
            $table->index(['organization_id', 'trip_id', 'invoice_date']);



        });

        Schema::create('sales_invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('sales_invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('product_packagings')->nullOnDelete();
            $table->foreignUlid('entered_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('entered_quantity', 19, 6);
            $table->decimal('conversion_factor_snapshot', 19, 6);
            $table->decimal('quantity_base', 19, 6);
            $table->decimal('unit_price_entered', 19, 4);
            $table->decimal('unit_price_base', 19, 4);
            $table->decimal('discount', 19, 4)->default(0);
            $table->decimal('tax', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);
            $table->decimal('unit_cost_snapshot', 19, 4)->default(0);
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 128);
            $table->timestamps();
            $table->index(['organization_id', 'sales_invoice_id']);



        });

        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignUlid('source_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUlid('original_sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->foreignUlid('trip_id')->nullable();
            $table->string('document_number', 64);
            $table->string('status', 32)->default('draft')->index();
            $table->date('return_date');
            $table->timestamp('posted_at')->nullable();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount', 19, 4)->default(0);
            $table->decimal('tax', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->char('currency', 3)->default('EGP');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_number']);
        });

        Schema::create('sales_return_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('sales_return_id')->constrained('sales_returns')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUlid('original_sales_invoice_item_id')->nullable()->constrained('sales_invoice_items')->nullOnDelete();
            $table->foreignUlid('packaging_id')->nullable()->constrained('product_packagings')->nullOnDelete();
            $table->decimal('entered_quantity', 19, 6);
            $table->decimal('conversion_factor_snapshot', 19, 6);
            $table->decimal('quantity_base', 19, 6);
            $table->decimal('unit_price_base', 19, 4);
            $table->decimal('unit_cost_snapshot', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4);
            $table->timestamps();



        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('party_type', 32);
            $table->ulid('party_id');
            $table->foreignUlid('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignUlid('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('direction', 16);
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3)->default('EGP');
            $table->date('payment_date');
            $table->string('reference', 255)->nullable();
            $table->string('status', 32)->default('posted')->index();
            $table->foreignUlid('trip_id')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'party_type', 'party_id', 'payment_date'], 'payments_party_date_idx');

        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('document_type', 64);
            $table->ulid('document_id');
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->index(['organization_id', 'document_type', 'document_id'], 'payment_alloc_doc_idx');

        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('category', 128);
            $table->decimal('amount', 19, 4);
            $table->foreignUlid('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->date('expense_date');
            $table->foreignUlid('trip_id')->nullable();
            $table->foreignUlid('vehicle_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('posted')->index();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

        });
        DB::statement("ALTER TABLE purchase_invoice_items ADD CONSTRAINT chk_purchase_item_qty CHECK (entered_quantity > 0 AND conversion_factor_snapshot > 0 AND quantity_base > 0)");
        DB::statement("ALTER TABLE purchase_return_items ADD CONSTRAINT chk_purchase_return_item_qty CHECK (entered_quantity > 0 AND conversion_factor_snapshot > 0 AND quantity_base > 0)");
        DB::statement("ALTER TABLE sales_invoice_items ADD CONSTRAINT chk_sales_item_qty CHECK (entered_quantity > 0 AND conversion_factor_snapshot > 0 AND quantity_base > 0)");
        DB::statement("ALTER TABLE sales_return_items ADD CONSTRAINT chk_sales_return_item_qty CHECK (entered_quantity > 0 AND conversion_factor_snapshot > 0 AND quantity_base > 0)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payment_amount_positive CHECK (amount > 0)");
        DB::statement("ALTER TABLE payment_allocations ADD CONSTRAINT chk_payment_allocation_positive CHECK (amount > 0)");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT chk_expense_amount_positive CHECK (amount > 0)");
    }







    public function down(): void
    {
        foreach (['expenses','payment_allocations','payments','sales_return_items','sales_returns','sales_invoice_items','sales_invoices','purchase_return_items','purchase_returns','purchase_invoice_items','purchase_invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};