<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'organization_id' => ['required','string','size:26'],
            'login' => ['required','string','max:255'],
            'password' => ['required','string'],
            'device_name' => ['required','string','max:128'],
        ]);

        $user = User::query()
            ->where('organization_id', $data['organization_id'])
            ->where(function ($query) use ($data) {
                $query->where('email', $data['login'])->orWhere('phone', $data['login']);
            })
            ->where('status', 'active')
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['بيانات الدخول غير صحيحة.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        return $this->tokenResponse($user,$data['device_name']);
    }

    public function demoLogin(Request $request)
    {
        $user=User::query()
            ->where('email','admin@nexora.local')
            ->where('status','active')
            ->whereHas('organization',fn($q)=>$q->where('name','NEXORA Demo Distribution'))
            ->first();

        if(!$user) return response()->json(['message'=>'بيانات الـDemo غير موجودة. شغّل php artisan migrate:fresh --seed.'],404);

        return $this->tokenResponse($user,$request->input('device_name','NEXORA Local Demo'));
    }

    private function tokenResponse(User $user,string $deviceName)
    {
        $user->forceFill(['last_login_at'=>now()])->save();
        $token=$user->createToken($deviceName);
        return response()->json([
            'token'=>$token->plainTextToken,'token_type'=>'Bearer',
            'user'=>['id'=>$user->id,'organization_id'=>$user->organization_id,'name'=>$user->name,'email'=>$user->email,'phone'=>$user->phone],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'id'=>$user->id,'organization_id'=>$user->organization_id,'name'=>$user->name,
            'email'=>$user->email,'phone'=>$user->phone,'status'=>$user->status,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(['status'=>'ok']);
    }
}
