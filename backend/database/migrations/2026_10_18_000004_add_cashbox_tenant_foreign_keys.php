<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $relations = [
        'cashbox_finance_account_org_fk' => ['financial_account_id', 'financial_accounts'],
        'cashbox_destination_account_org_fk' => ['destination_financial_account_id', 'financial_accounts'],
        'cashbox_beneficiary_user_org_fk' => ['beneficiary_user_id', 'users'],
        'cashbox_vehicle_org_fk' => ['vehicle_id', 'vehicles'],
        'cashbox_trip_org_fk' => ['trip_id', 'trips'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        foreach ($this->relations as $name => [$column, $parent]) {
            Schema::table('cashbox_transactions', function (Blueprint $table) use ($name, $column, $parent): void {
                $table->foreign(['organization_id', $column], $name)
                    ->references(['organization_id', 'id'])->on($parent)->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            foreach (array_keys($this->relations) as $name) $table->dropForeign($name);
        });
    }
};
