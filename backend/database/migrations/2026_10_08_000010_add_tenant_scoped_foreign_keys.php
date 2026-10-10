<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These constraints complement API authorization by making it impossible
     * to connect records from different organizations in core stock/sales flows.
     * Existing single-column foreign keys remain in place for referential safety.
     */
    private array $parentIndexes = [
        'products' => 'products_org_id_uq',
        'locations' => 'locations_org_id_uq',
        'suppliers' => 'suppliers_org_id_uq',
        'customers' => 'customers_org_id_uq',
        'vehicles' => 'vehicles_org_id_uq',
        'users' => 'users_org_id_uq',
        'trips' => 'trips_org_id_uq',
        'purchase_invoices' => 'purchase_invoices_org_id_uq',
        'sales_invoices' => 'sales_invoices_org_id_uq',
        'financial_accounts' => 'financial_accounts_org_id_uq',
        'payment_methods' => 'payment_methods_org_id_uq',
        'trip_loads' => 'trip_loads_org_id_uq',
        'payments' => 'payments_org_id_uq',
        'ledger_accounts' => 'ledger_accounts_org_id_uq',
    ];

    private array $relations = [
        'purchase_invoices' => [
            'purchase_invoices_org_supplier_fk' => ['supplier_id', 'suppliers'],
            'purchase_invoices_org_location_fk' => ['location_id', 'locations'],
        ],
        'purchase_invoice_items' => [
            'purchase_items_org_invoice_fk' => ['purchase_invoice_id', 'purchase_invoices'],
            'purchase_items_org_product_fk' => ['product_id', 'products'],
        ],
        'sales_invoices' => [
            'sales_invoices_org_customer_fk' => ['customer_id', 'customers'],
            'sales_invoices_org_location_fk' => ['source_location_id', 'locations'],
        ],
        'sales_invoice_items' => [
            'sales_items_org_invoice_fk' => ['sales_invoice_id', 'sales_invoices'],
            'sales_items_org_product_fk' => ['product_id', 'products'],
        ],
        'trips' => [
            'trips_org_vehicle_fk' => ['vehicle_id', 'vehicles'],
            'trips_org_rep_fk' => ['rep_user_id', 'users'],
            'trips_org_origin_fk' => ['origin_location_id', 'locations'],
        ],
        'trip_customers' => [
            'trip_customers_org_trip_fk' => ['trip_id', 'trips'],
            'trip_customers_org_customer_fk' => ['customer_id', 'customers'],
        ],
        'trip_loads' => [
            'trip_loads_org_trip_fk' => ['trip_id', 'trips'],
        ],
        'stock_balances' => [
            'stock_balances_org_product_fk' => ['product_id', 'products'],
            'stock_balances_org_location_fk' => ['location_id', 'locations'],
        ],
        'stock_movements' => [
            'stock_movements_org_product_fk' => ['product_id', 'products'],
            'stock_movements_org_location_fk' => ['location_id', 'locations'],
        ],
        'ledger_entries' => [
            'ledger_entries_org_account_fk' => ['account_id', 'ledger_accounts'],
        ],
        'payments' => [
            'payments_org_account_fk' => ['financial_account_id', 'financial_accounts'],
            'payments_org_method_fk' => ['payment_method_id', 'payment_methods'],
        ],
        'payment_allocations' => [
            'payment_allocations_org_payment_fk' => ['payment_id', 'payments'],
        ],
        'expenses' => [
            'expenses_org_account_fk' => ['financial_account_id', 'financial_accounts'],
        ],
    ];

    public function up(): void
    {
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
        foreach (array_reverse($this->relations, true) as $tableName => $relations) {
            Schema::table($tableName, function (Blueprint $table) use ($relations): void {
                foreach (array_keys($relations) as $constraintName) {
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
