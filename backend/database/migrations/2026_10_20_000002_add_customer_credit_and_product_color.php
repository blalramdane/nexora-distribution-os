<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'credit_enabled')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->boolean('credit_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('products', 'color')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('color', 100)->nullable();
            });
        }

        DB::table('customers')->where(function ($query): void {
            $query->where('credit_limit', '>', 0)
                ->orWhereIn('id', DB::table('sales_invoices')->select('customer_id')
                    ->where('status', 'posted')->where('balance_due', '>', 0));
        })->update(['credit_enabled' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'color')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('color'));
        }
        if (Schema::hasColumn('customers', 'credit_enabled')) {
            Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('credit_enabled'));
        }
    }
};
