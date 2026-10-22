<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:128'],
        ]);

        $user = User::query()
            ->where('organization_id', $data['organization_id'])
            ->where(function ($query) use ($data) {
                $query->where('email', $data['login'])
                    ->orWhere('phone', $data['login']);
            })
            ->where('status', 'active')
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($data['device_name']);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['status' => 'ok']);
    }

    private function userPayload(User $user): array
    {
        $roles = $user->roles()
            ->where('roles.organization_id', $user->organization_id)
            ->with('permissions')
            ->get();

        $rolePayload = $roles->map(static fn ($role) => [
            'key' => $role->key,
            'name' => $role->name,
        ])->values();

        $permissions = $roles
            ->flatMap(static fn ($role) => $role->permissions)
            ->unique('key')
            ->sortBy('key')
            ->values()
            ->map(static fn ($permission) => [
                'key' => $permission->key,
                'name' => $permission->name,
            ]);

        return [
            'id' => $user->id,
            'organization_id' => $user->organization_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'roles' => $rolePayload,
            'permissions' => $permissions,
        ];
    }
}
