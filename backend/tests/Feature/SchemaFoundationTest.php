<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_identity_tables_exist(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('organizations'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('roles'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('permissions'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('devices'));
    }

    public function test_permission_seed_is_repeatable(): void
    {
        $this->seed();
        $firstCount = DB::table('permissions')->count();

        $this->seed();
        $secondCount = DB::table('permissions')->count();

        $this->assertSame($firstCount, $secondCount);
    }

    public function test_organization_tenant_key_is_required_on_users(): void
    {
        $organization = DB::table('organizations')->insertGetId([
            'id' => (string) Illuminate\Support\Str::ulid(),
            'name' => 'NEXORA Test',
            'default_currency' => 'EGP',
            'timezone' => 'Africa/Cairo',
            'country_code' => 'EG',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotNull($organization);
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('users', 'organization_id'));
    }
}