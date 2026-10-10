<?php

namespace Tests\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

trait GrantsTestPermissions
{
    protected function grantTestPermissions(User $user): void
    {
        $keys = [
            'dashboard.view', 'products.view', 'products.manage',
            'customers.view', 'customers.manage', 'suppliers.view',
            'settings.manage', 'sales.view', 'sales.post',
            'purchases.view', 'purchases.post', 'inventory.view',
            'inventory.adjust', 'payments.record', 'reports.view',
            'trips.view', 'trips.manage', 'field.visit', 'field.sync',
        ];

        $role = Role::query()->create([
            'organization_id' => $user->organization_id,
            'name' => 'Test operator',
            'key' => 'test-operator-'.$user->id,
        ]);

        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['id' => (string) Str::ulid(), 'name' => $key],
            );
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);
    }
}
