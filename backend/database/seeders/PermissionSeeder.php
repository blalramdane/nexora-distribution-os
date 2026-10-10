<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['key' => 'dashboard.view', 'name' => 'View dashboard'],
            ['key' => 'products.view', 'name' => 'View products'],
            ['key' => 'products.manage', 'name' => 'Manage products'],
            ['key' => 'customers.view', 'name' => 'View customers'],
            ['key' => 'customers.manage', 'name' => 'Manage customers'],
            ['key' => 'suppliers.view', 'name' => 'View suppliers'],
            ['key' => 'suppliers.manage', 'name' => 'Manage suppliers'],
            ['key' => 'settings.manage', 'name' => 'Manage organization settings'],
            ['key' => 'sales.view', 'name' => 'View sales'],
            ['key' => 'sales.post', 'name' => 'Post sales'],
            ['key' => 'purchases.view', 'name' => 'View purchases'],
            ['key' => 'purchases.post', 'name' => 'Post purchases'],
            ['key' => 'inventory.view', 'name' => 'View inventory'],
            ['key' => 'inventory.adjust', 'name' => 'Adjust inventory'],
            ['key' => 'payments.record', 'name' => 'Record payments'],
            ['key' => 'reports.view', 'name' => 'View reports'],
            ['key' => 'trips.view', 'name' => 'View trips and routes'],
            ['key' => 'trips.manage', 'name' => 'Manage trips and routes'],
            ['key' => 'field.visit', 'name' => 'Perform field visits'],
            ['key' => 'field.sync', 'name' => 'Synchronize field operations'],
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
