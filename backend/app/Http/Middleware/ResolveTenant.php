<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->user()?->organization_id;

        abort_unless($organizationId, 403, 'Tenant context is required.');

        $organization = Organization::query()->find($organizationId);

        abort_unless($organization?->status === 'active', 403, 'Organization is inactive.');

        app()->instance(Organization::class, $organization);

        return $next($request);
    }
}