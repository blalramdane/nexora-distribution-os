<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_share_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invoice_type', 32);
            $table->ulid('invoice_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'invoice_type', 'invoice_id'], 'invoice_share_document_idx');
            $table->index(['organization_id', 'revoked_at', 'expires_at'], 'invoice_share_active_idx');
        });

        Schema::create('invoice_share_access_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('share_link_id')->constrained('invoice_share_links')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'share_link_id', 'created_at'], 'invoice_share_audit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_share_access_logs');
        Schema::dropIfExists('invoice_share_links');
    }
};
