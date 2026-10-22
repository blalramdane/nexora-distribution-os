<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may commit the column/index DDL before a later FK statement
        // fails. Guard each addition so this migration can be safely retried.
        Schema::table('field_stock_counts', function (Blueprint $table): void {
            if (! Schema::hasColumn('field_stock_counts', 'reviewed_by')) {
                $table->ulid('reviewed_by')->nullable()->after('submitted_by');
            }
            if (! Schema::hasColumn('field_stock_counts', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('field_stock_counts', 'review_notes')) {
                $table->text('review_notes')->nullable()->after('notes');
            }
        });

        $reviewIndexExists = DB::getDriverName() === 'mysql'
            ? DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', 'field_stock_counts')
                ->where('index_name', 'field_counts_review_queue_idx')
                ->exists()
            : false;

        if (! $reviewIndexExists) {
            Schema::table('field_stock_counts', function (Blueprint $table): void {
                $table->index(['organization_id', 'status', 'submitted_at'], 'field_counts_review_queue_idx');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            $reviewerForeignKeyExists = DB::table('information_schema.referential_constraints')
                ->where('constraint_schema', DB::getDatabaseName())
                ->where('constraint_name', 'field_counts_org_reviewer_fk')
                ->exists();

            if (! $reviewerForeignKeyExists) {
                Schema::table('field_stock_counts', function (Blueprint $table): void {
                    // A composite tenant FK cannot SET NULL because organization_id
                    // is required. Restrict deletion to preserve reviewer audit history.
                    $table->foreign(['organization_id', 'reviewed_by'], 'field_counts_org_reviewer_fk')
                        ->references(['organization_id', 'id'])->on('users')->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasTable('field_stock_counts')) {
            Schema::table('field_stock_counts', function (Blueprint $table): void {
                $table->dropForeign('field_counts_org_reviewer_fk');
            });
        }

        Schema::table('field_stock_counts', function (Blueprint $table): void {
            $table->dropIndex('field_counts_review_queue_idx');
            $table->dropColumn(['reviewed_by', 'reviewed_at', 'review_notes']);
        });
    }
};
