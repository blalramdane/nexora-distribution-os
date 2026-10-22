<?php

use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\ApiSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel's native throttle supports separate guest/authenticated ceilings:
        // guests are keyed by IP, authenticated users by their identity.
        $middleware->throttleApi('60|180');
        $middleware->api(append: [ApiSecurityHeaders::class]);

        $middleware->redirectGuestsTo(static function (Request $request): ?string {
            return $request->is('api/*') ? null : route('login');
        });

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(static function (Request $request, Throwable $exception): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
