<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->string('settlement_method', 32)->default('purchase_debt');
            $table->decimal('settlement_unit_price', 19, 4)->nullable();
        });

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->decimal('payable_amount', 19, 4)->default(0);
        });
        Schema::table('purchase_invoice_items', function (Blueprint $table): void {
            $table->decimal('payable_amount', 19, 4)->default(0);
            $table->string('settlement_method_snapshot', 32)->default('purchase_debt');
            $table->decimal('settlement_unit_price_snapshot', 19, 4)->nullable();
        });

        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->decimal('payable_amount', 19, 4)->default(0);
        });
        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->decimal('payable_amount', 19, 4)->default(0);
        });

        Schema::table('trip_supplier_settlements', function (Blueprint $table): void {
            // Existing source-cost snapshots are preserved as historical reports,
            // not silently reclassified as a new liability.
            $table->string('settlement_basis', 32)->default('legacy_source_cost');
            $table->decimal('deferred_source_cost', 19, 4)->default(0);
        });

        DB::table('purchase_invoices')->update(['payable_amount' => DB::raw('total')]);
        DB::table('purchase_invoice_items')->update(['payable_amount' => DB::raw('line_total')]);
        DB::table('purchase_returns')->update(['payable_amount' => DB::raw('total')]);
        DB::table('purchase_return_items')->update(['payable_amount' => DB::raw('line_total')]);
    }

    public function down(): void
    {
        Schema::table('trip_supplier_settlements', function (Blueprint $table): void {
            $table->dropColumn(['settlement_basis', 'deferred_source_cost']);
        });
        Schema::table('purchase_return_items', function (Blueprint $table): void {
            $table->dropColumn('payable_amount');
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropColumn('payable_amount');
        });
        Schema::table('purchase_invoice_items', function (Blueprint $table): void {
            $table->dropColumn(['payable_amount', 'settlement_method_snapshot', 'settlement_unit_price_snapshot']);
        });
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropColumn('payable_amount');
        });
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->dropColumn(['settlement_method', 'settlement_unit_price']);
        });
    }
};
