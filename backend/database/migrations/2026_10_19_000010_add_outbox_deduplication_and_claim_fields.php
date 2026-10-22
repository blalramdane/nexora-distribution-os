<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_outbox', function (Blueprint $table): void {
            $table->string('dedupe_key', 255)->nullable()->after('aggregate_id');
            $table->timestamp('claimed_at')->nullable()->after('available_at');
            $table->string('claimed_by', 128)->nullable()->after('claimed_at');
            $table->unique(['organization_id', 'dedupe_key'], 'outbox_org_dedupe_uq');
            $table->index(['status', 'available_at', 'claimed_at'], 'outbox_claim_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('message_outbox', function (Blueprint $table): void {
            $table->dropUnique('outbox_org_dedupe_uq');
            $table->dropIndex('outbox_claim_queue_idx');
            $table->dropColumn(['dedupe_key', 'claimed_at', 'claimed_by']);
        });
    }
};
