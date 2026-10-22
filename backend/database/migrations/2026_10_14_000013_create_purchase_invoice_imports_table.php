<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_path', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->string('status', 32)->default('uploaded')->index();
            $table->longText('extracted_text')->nullable();
            $table->json('extraction_json')->nullable();
            $table->string('ocr_engine', 64)->nullable();
            $table->decimal('ocr_confidence', 5, 2)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'sha256'], 'purchase_import_org_sha256_uq');
            $table->index(['organization_id', 'status', 'created_at'], 'purchase_import_org_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_imports');
    }
};
