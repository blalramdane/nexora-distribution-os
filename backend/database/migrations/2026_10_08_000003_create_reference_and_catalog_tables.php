<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('governorates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 16)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('centers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('governorate_id')->constrained('governorates')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['governorate_id', 'code']);
        });

        Schema::create('cities_areas', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('center_id')->constrained('centers')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['center_id', 'code']);
        });

        Schema::create('units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->unsignedTinyInteger('precision')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('code', 64);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'parent_id']);
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('requires_reference')->default(false);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('type', 32);
            $table->char('currency', 3)->default('EGP');
            $table->decimal('opening_balance', 19, 4)->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['financial_accounts','payment_methods','categories','units','cities_areas','centers','governorates','sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};