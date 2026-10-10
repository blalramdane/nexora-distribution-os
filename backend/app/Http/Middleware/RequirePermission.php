<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        abort_unless($user, 401);

        $allowed = $user->roles()
            ->whereHas('permissions', static fn ($query) => $query->where('key', $permission))
            ->exists();

        abort_unless($allowed, 403, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
