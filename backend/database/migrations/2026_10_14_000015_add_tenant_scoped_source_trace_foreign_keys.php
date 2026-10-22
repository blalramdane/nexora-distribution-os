<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the same database-enforced organization boundary used by the core
     * stock/transaction schema to the new invoice-import and source-trace rows.
     * SQLite test runs intentionally skip composite tenant FK DDL; MySQL 8.4
     * remains the target engine for verifying those constraints.
     */
    private array $parentIndexes = [
        'purchase_invoice_imports' => 'purchase_imports_org_id_uq',
        'stock_source_layers' => 'stock_src_layers_org_id_uq',
        'stock_source_allocations' => 'stock_src_alloc_org_id_uq',
        'stock_movements' => 'stock_movements_org_id_uq',
    ];

    private array $relations = [
        'purchase_invoice_imports' => [
            'purchase_imports_org_uploader_fk' => ['uploaded_by', 'users'],
            'purchase_imports_org_invoice_fk' => ['purchase_invoice_id', 'purchase_invoices'],
        ],
        'stock_source_layers' => [
            'stock_src_layers_org_product_fk' => ['product_id', 'products'],
            'stock_src_layers_org_location_fk' => ['location_id', 'locations'],
            'stock_src_layers_org_purchase_fk' => ['source_purchase_invoice_id', 'purchase_invoices'],
            'stock_src_layers_org_purchase_item_fk' => ['source_purchase_invoice_item_id', 'purchase_invoice_items'],
            'stock_src_layers_org_parent_fk' => ['parent_layer_id', 'stock_source_layers'],
        ],
        'stock_source_allocations' => [
            'stock_src_alloc_org_source_layer_fk' => ['source_layer_id', 'stock_source_layers'],
            'stock_src_alloc_org_destination_layer_fk' => ['destination_layer_id', 'stock_source_layers'],
            'stock_src_alloc_org_movement_fk' => ['stock_movement_id', 'stock_movements'],
            'stock_src_alloc_org_reversal_fk' => ['reverses_allocation_id', 'stock_source_allocations'],
            'stock_src_alloc_org_purchase_fk' => ['source_purchase_invoice_id', 'purchase_invoices'],
            'stock_src_alloc_org_purchase_item_fk' => ['source_purchase_invoice_item_id', 'purchase_invoice_items'],
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->parentIndexes as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->unique(['organization_id', 'id'], $indexName);
            });
        }

        foreach ($this->relations as $tableName => $relations) {
            Schema::table($tableName, function (Blueprint $table) use ($relations): void {
                foreach ($relations as $constraintName => [$foreignColumn, $parentTable]) {
                    $table->foreign(['organization_id', $foreignColumn], $constraintName)
                        ->references(['organization_id', 'id'])
                        ->on($parentTable)
                        ->restrictOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (array_reverse($this->relations, true) as $tableName => $relations) {
            Schema::table($tableName, function (Blueprint $table) use ($relations): void {
                foreach ($relations as $constraintName => $_relation) {
                    $table->dropForeign($constraintName);
                }
            });
        }

        foreach (array_reverse($this->parentIndexes, true) as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropUnique($indexName);
            });
        }
    }
};
