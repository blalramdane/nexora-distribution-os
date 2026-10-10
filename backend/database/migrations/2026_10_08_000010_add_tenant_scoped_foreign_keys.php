<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        'product_packagings' => 'product_packagings_org_id_uq',
        'categories' => 'categories_org_id_uq',
        'locations' => 'locations_org_id_uq',
        'warehouses' => 'warehouses_org_id_uq',
        'suppliers' => 'suppliers_org_id_uq',
        'customers' => 'customers_org_id_uq',
        'vehicles' => 'vehicles_org_id_uq',
        'users' => 'users_org_id_uq',
        'devices' => 'devices_org_id_uq',
        'trips' => 'trips_org_id_uq',
        'purchase_invoices' => 'purchase_invoices_org_id_uq',
        'purchase_invoice_items' => 'purchase_items_org_id_uq',
        'purchase_returns' => 'purchase_returns_org_id_uq',
        'sales_invoices' => 'sales_invoices_org_id_uq',
        'sales_invoice_items' => 'sales_items_org_id_uq',
        'sales_returns' => 'sales_returns_org_id_uq',
        'financial_accounts' => 'financial_accounts_org_id_uq',
        'payment_methods' => 'payment_methods_org_id_uq',
        'trip_loads' => 'trip_loads_org_id_uq',
        'trip_settlements' => 'trip_settlements_org_id_uq',
        'sync_operations' => 'sync_operations_org_id_uq',
        'message_outbox' => 'message_outbox_org_id_uq',
        'expenses' => 'expenses_org_id_uq',
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
        'purchase_returns' => [
            'purchase_returns_org_supplier_fk' => ['supplier_id', 'suppliers'],
            'purchase_returns_org_location_fk' => ['location_id', 'locations'],
        ],
        'purchase_return_items' => [
            'purchase_return_items_org_return_fk' => ['purchase_return_id', 'purchase_returns'],
            'purchase_return_items_org_product_fk' => ['product_id', 'products'],
        ],
        'sales_invoices' => [
            'sales_invoices_org_customer_fk' => ['customer_id', 'customers'],
            'sales_invoices_org_location_fk' => ['source_location_id', 'locations'],
        ],
        'sales_returns' => [
            'sales_returns_org_customer_fk' => ['customer_id', 'customers'],
            'sales_returns_org_location_fk' => ['source_location_id', 'locations'],
        ],
        'sales_invoice_items' => [
            'sales_items_org_invoice_fk' => ['sales_invoice_id', 'sales_invoices'],
            'sales_items_org_product_fk' => ['product_id', 'products'],
        ],
        'sales_return_items' => [
            'sales_return_items_org_return_fk' => ['sales_return_id', 'sales_returns'],
            'sales_return_items_org_product_fk' => ['product_id', 'products'],
        ],
        'product_packagings' => [
            'packagings_org_product_fk' => ['product_id', 'products'],
        ],
        'product_barcodes' => [
            'barcodes_org_product_fk' => ['product_id', 'products'],
        ],
        'product_aliases' => [
            'aliases_org_product_fk' => ['product_id', 'products'],
        ],
        'supplier_products' => [
            'supplier_products_org_supplier_fk' => ['supplier_id', 'suppliers'],
            'supplier_products_org_product_fk' => ['product_id', 'products'],
        ],
        'customer_addresses' => [
            'customer_addresses_org_customer_fk' => ['customer_id', 'customers'],
        ],
        'customer_location_events' => [
            'location_events_org_customer_fk' => ['customer_id', 'customers'],
        ],
        'warehouses' => [
            'warehouses_org_location_fk' => ['location_id', 'locations'],
        ],
        'vehicles' => [
            'vehicles_org_location_fk' => ['location_id', 'locations'],
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
            'trip_loads_org_source_location_fk' => ['from_location_id', 'locations'],
        ],
        'trip_load_items' => [
            'trip_load_items_org_load_fk' => ['trip_load_id', 'trip_loads'],
            'trip_load_items_org_product_fk' => ['product_id', 'products'],
        ],
        'customer_visits' => [
            'customer_visits_org_trip_fk' => ['trip_id', 'trips'],
            'customer_visits_org_customer_fk' => ['customer_id', 'customers'],
            'customer_visits_org_user_fk' => ['user_id', 'users'],
        ],
        'trip_expenses' => [
            'trip_expenses_org_trip_fk' => ['trip_id', 'trips'],
            'trip_expenses_org_expense_fk' => ['expense_id', 'expenses'],
        ],
        'trip_settlements' => [
            'trip_settlements_org_trip_fk' => ['trip_id', 'trips'],
        ],
        'trip_settlement_lines' => [
            'settlement_lines_org_settlement_fk' => ['trip_settlement_id', 'trip_settlements'],
            'settlement_lines_org_product_fk' => ['product_id', 'products'],
        ],
        'sync_operations' => [
            'sync_operations_org_device_fk' => ['device_id', 'devices'],
            'sync_operations_org_user_fk' => ['user_id', 'users'],
        ],
        'sync_conflicts' => [
            'sync_conflicts_org_operation_fk' => ['sync_operation_id', 'sync_operations'],
        ],
        'outbox_attempts' => [
            'outbox_attempts_org_outbox_fk' => ['outbox_id', 'message_outbox'],
        ],
        'customer_balance_summaries' => [
            'customer_summaries_org_customer_fk' => ['customer_id', 'customers'],
        ],
        'supplier_balance_summaries' => [
            'supplier_summaries_org_supplier_fk' => ['supplier_id', 'suppliers'],
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
        // SQLite cannot drop individual foreign keys during rollback without
        // rebuilding tables, which would discard CHECK constraints introduced
        // by the existing raw ALTER TABLE migrations. MySQL 8.4 is the target
        // engine for enforcing these composite tenant constraints.
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
                foreach ($relations as $constraintName => [$foreignColumn]) {
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
