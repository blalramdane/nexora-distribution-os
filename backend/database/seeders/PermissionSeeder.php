<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['key' => 'dashboard.view', 'name' => 'View dashboard'],
            ['key' => 'sales.view', 'name' => 'View sales'],
            ['key' => 'sales.post', 'name' => 'Post sales'],
            ['key' => 'purchases.view', 'name' => 'View purchases'],
            ['key' => 'purchases.post', 'name' => 'Post purchases'],
            ['key' => 'inventory.view', 'name' => 'View inventory'],
            ['key' => 'inventory.adjust', 'name' => 'Adjust inventory'],
            ['key' => 'payments.record', 'name' => 'Record payments'],
            ['key' => 'reports.view', 'name' => 'View reports'],
            ['key' => 'settings.manage', 'name' => 'Manage settings'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'key' => $permission['key'],
                'name' => $permission['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('permissions')
                ->where('key', $permission['key'])
                ->update([
                    'name' => $permission['name'],
                    'updated_at' => now(),
                ]);
        }
    }
}