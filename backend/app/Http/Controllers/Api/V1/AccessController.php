<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccessController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = (string) $request->user()->organization_id;

        // Give a newly created organization usable starting roles so the first
        // user can be assigned without requiring a separate setup step.
        if (! Role::query()->where('organization_id', $organizationId)->exists()) {
            $permissionSets = [
                'owner' => ['dashboard.view','reports.view','products.view','products.manage','customers.view','customers.manage','suppliers.view','suppliers.manage','settings.manage','users.manage','sales.view','sales.post','purchases.view','purchases.post','inventory.view','inventory.adjust','payments.record','cashbox.view','cashbox.post','expenses.view','expenses.post','trips.view','trips.manage'],
                'sales_rep' => ['dashboard.view','products.view','customers.view','sales.view','sales.post','payments.record','trips.view','field.visit','field.sync'],
                'warehouse' => ['dashboard.view','products.view','products.manage','inventory.view','inventory.adjust','purchases.view','purchases.post','trips.view'],
            ];
            $roleNames = ['owner' => 'مالك المنشأة', 'sales_rep' => 'مندوب مبيعات', 'warehouse' => 'مسؤول المخزن'];
            foreach ($permissionSets as $key => $keys) {
                $role = Role::query()->create(['organization_id' => $organizationId, 'name' => $roleNames[$key], 'key' => $key]);
                $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id')->all());
            }
        }

        $roles = Role::query()->where('organization_id', $organizationId)->with('permissions:id,key,name')
            ->orderBy('name')->get(['id', 'organization_id', 'name', 'key']);
        $users = DB::table('users as u')
            ->where('u.organization_id', $organizationId)
            ->orderBy('u.name')->get(['u.id', 'u.name', 'u.email', 'u.phone', 'u.status', 'u.last_login_at']);

        $userRows = $users->map(function (object $user) use ($organizationId): array {
            $user->roles = DB::table('user_roles as ur')->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'ur.role_id')->on('r.organization_id', '=', 'ur.organization_id');
            })->where('ur.organization_id', $organizationId)->where('ur.user_id', $user->id)
                ->orderBy('r.name')->get(['r.id', 'r.name', 'r.key']);
            return (array) $user;
        })->values();

        return response()->json([
            'users' => $userRows,
            'roles' => $roles,
            'permissions' => Permission::query()->orderBy('key')->get(['id', 'key', 'name']),
        ]);
    }

    public function storeRole(Request $request)
    {
        $organizationId = (string) $request->user()->organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'permission_keys' => ['present', 'array', 'max:100'],
            'permission_keys.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'key')],
        ]);

        $name = trim($data['name']);
        if (Role::query()->where('organization_id', $organizationId)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => ['يوجد دور بنفس الاسم بالفعل.']]);
        }

        return DB::transaction(function () use ($organizationId, $name, $data) {
            $role = Role::query()->create([
                'organization_id' => $organizationId,
                'name' => $name,
                'key' => 'role_'.Str::lower(Str::random(16)),
            ]);
            $permissionIds = Permission::query()->whereIn('key', $data['permission_keys'])->pluck('id')->all();
            $role->permissions()->sync($permissionIds);

            return response()->json($role->load('permissions:id,key,name'), 201);
        });
    }

    public function storeUser(Request $request)
    {
        $organizationId = (string) $request->user()->organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('organization_id', $organizationId)],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users', 'phone')->where('organization_id', $organizationId)],
            'password' => ['required', 'string', 'min:10', 'max:128'],
            'role_id' => ['required', 'string', 'size:26', Rule::exists('roles', 'id')->where('organization_id', $organizationId)],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            throw ValidationException::withMessages(['email' => ['أدخل البريد الإلكتروني أو رقم الهاتف لتسجيل الدخول.']]);
        }

        return DB::transaction(function () use ($organizationId, $data) {
            $user = User::query()->create([
                'organization_id' => $organizationId,
                'name' => trim($data['name']),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'status' => 'active',
            ]);
            $user->roles()->attach($data['role_id'], ['organization_id' => $organizationId]);

            return response()->json([
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'phone' => $user->phone, 'status' => $user->status, 'role_id' => $data['role_id'],
            ], 201);
        });
    }
}
