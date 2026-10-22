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

final class FieldRepController extends Controller
{
    /** List field accounts in the authenticated organization only. */
    public function index(Request $request)
    {
        $organizationId = (string) $request->user()->organization_id;

        // Use explicit tenant-scoped joins instead of whereHas('roles'). The
        // User::roles relation has a withPivotValue bound to a hydrated user's
        // organization; whereHas builds an unhydrated relation model and that
        // value is null, which makes the query throw before the trips screen
        // can finish loading its warehouse/vehicle selectors.
        $users = DB::table('users as u')
            ->join('user_roles as ur', function ($join): void {
                $join->on('ur.user_id', '=', 'u.id')
                    ->on('ur.organization_id', '=', 'u.organization_id');
            })
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'ur.role_id')
                    ->on('r.organization_id', '=', 'ur.organization_id');
            })
            ->where('u.organization_id', $organizationId)
            ->where('r.organization_id', $organizationId)
            ->where('r.key', 'field_rep')
            ->select(['u.id', 'u.name', 'u.email', 'u.phone', 'u.status'])
            ->distinct()
            ->orderBy('u.name')
            ->get();

        return response()->json($users);
    }

    /** Create a least-privilege field account; no token or public sign-in bypass is issued. */
    public function store(Request $request)
    {
        $organizationId = (string) $request->user()->organization_id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('organization_id', $organizationId)],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('users', 'phone')->where('organization_id', $organizationId)],
            'password' => ['required', 'string', 'min:10', 'max:128'],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            throw ValidationException::withMessages([
                'email' => ['أدخل بريدًا إلكترونيًا أو رقم هاتف ليستخدمه المندوب في تسجيل الدخول.'],
            ]);
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

            $role = Role::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'key' => 'field_rep'],
                ['name' => 'مندوب ميداني'],
            );

            $fieldPermissionKeys = [
                'field.visit',
                'field.sync',
                'sales.post',
                'payments.record',
                'expenses.post',
            ];

            $permissionNames = [
                'field.visit' => 'Perform field visits',
                'field.sync' => 'Synchronize field operations',
                'sales.post' => 'Post sales',
                'payments.record' => 'Record payments',
                'expenses.post' => 'Post trip expenses',
            ];
            $permissionIds = collect($fieldPermissionKeys)->mapWithKeys(static function (string $key) use ($permissionNames): array {
                $permission = Permission::query()->firstOrCreate(
                    ['key' => $key],
                    ['id' => (string) Str::ulid(), 'name' => $permissionNames[$key]],
                );
                return [$key => $permission->id];
            });

            // The field_rep role is deliberately a narrow system role. Do not
            // inherit any administrative permissions if an older role exists.
            $role->permissions()->sync($permissionIds->values()->all());
            $user->roles()->attach($role->id, ['organization_id' => $organizationId]);

            return response()->json([
                'id' => $user->id,
                'organization_id' => $user->organization_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'roles' => [['key' => 'field_rep', 'name' => 'مندوب ميداني']],
                'field_path' => '/field',
            ], 201);
        });
    }
}
