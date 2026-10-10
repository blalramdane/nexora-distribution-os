<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SQLite must rebuild the legacy payments table outside a transaction to
     * remove the stale composite FK without losing posted payment rows.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $exists = DB::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                ['payments', 'payments_org_method_fk'],
            );

            if ($exists) {
                DB::statement('ALTER TABLE payments DROP FOREIGN KEY payments_org_method_fk');
            }

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        $foreignKeys = collect(DB::select('PRAGMA foreign_key_list("payments")'));
        $hasInvalidCompositeMethodForeignKey = $foreignKeys
            ->groupBy('id')
            ->contains(static function ($group): bool {
                if ($group->count() < 2) {
                    return false;
                }

                $methodRelation = $group->where('table', 'payment_methods');

                return $methodRelation->contains(static fn ($key): bool => $key->from === 'organization_id' && $key->to === 'organization_id')
                    && $methodRelation->contains(static fn ($key): bool => $key->from === 'payment_method_id' && $key->to === 'id');
            });

        // Fresh SQLite databases already follow migration 10's corrected contract.
        // Only rebuild databases created by the older, invalid composite-FK version.
        // Inspect SQLite's parsed FK metadata rather than relying on whitespace/quoting
        // in sqlite_master DDL, which varies across SQLite versions and schema builders.
        if (! $hasInvalidCompositeMethodForeignKey) {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::statement(<<<'SQL'
                CREATE TABLE payments__tenant_safe (
                    id varchar NOT NULL PRIMARY KEY,
                    organization_id varchar NOT NULL,
                    party_type varchar NOT NULL,
                    party_id varchar NOT NULL,
                    financial_account_id varchar NOT NULL,
                    payment_method_id varchar NOT NULL,
                    direction varchar NOT NULL,
                    amount numeric NOT NULL,
                    currency varchar NOT NULL DEFAULT ('EGP'),
                    payment_date date NOT NULL,
                    reference varchar,
                    status varchar NOT NULL DEFAULT ('posted'),
                    trip_id varchar,
                    created_by varchar,
                    device_id varchar,
                    idempotency_key varchar,
                    created_at datetime,
                    updated_at datetime,
                    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT,
                    FOREIGN KEY (financial_account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
                    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
                    FOREIGN KEY (organization_id, financial_account_id) REFERENCES financial_accounts(organization_id, id) ON DELETE RESTRICT
                )
                SQL);

            DB::statement(<<<'SQL'
                INSERT INTO payments__tenant_safe (
                    id, organization_id, party_type, party_id, financial_account_id, payment_method_id,
                    direction, amount, currency, payment_date, reference, status, trip_id, created_by,
                    device_id, idempotency_key, created_at, updated_at
                )
                SELECT
                    id, organization_id, party_type, party_id, financial_account_id, payment_method_id,
                    direction, amount, currency, payment_date, reference, status, trip_id, created_by,
                    device_id, idempotency_key, created_at, updated_at
                FROM payments
                SQL);

            DB::statement('DROP TABLE payments');
            DB::statement('ALTER TABLE payments__tenant_safe RENAME TO payments');
            DB::statement('CREATE UNIQUE INDEX payments_org_id_uq ON payments (organization_id, id)');
            DB::statement('CREATE INDEX payments_party_date_idx ON payments (organization_id, party_type, party_id, payment_date)');
            DB::statement('CREATE INDEX payments_status_index ON payments (status)');
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $violations = DB::select('PRAGMA foreign_key_check');
        if ($violations !== []) {
            throw new RuntimeException('Payment table normalization produced foreign-key violations.');
        }
    }

    public function down(): void
    {
        // This migration corrects a historical schema drift. Reintroducing the
        // invalid composite FK would make global payment methods unusable again.
    }
};
