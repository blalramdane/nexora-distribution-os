<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->decimal('average_cost', 19, 4)->default(0)->after('reserved_quantity_base');
        });
    }

    public function down(): void
    {
        Schema::table('stock_balances', function (Blueprint $table): void {
            $table->dropColumn('average_cost');
        });
    }
};