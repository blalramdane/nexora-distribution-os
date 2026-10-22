<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // A receipt created together with a sale invoice is a real payment,
            // but must be distinguished from a later collection in trip closing.
            $table->string('source_document_type', 64)->nullable();
            $table->ulid('source_document_id')->nullable();
            $table->unique(
                ['organization_id', 'source_document_type', 'source_document_id'],
                'payments_source_document_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_source_document_uq');
            $table->dropColumn(['source_document_type', 'source_document_id']);
        });
    }
};
