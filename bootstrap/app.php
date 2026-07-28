<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnsureTenantScope;
use App\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The API is stateless and token-authenticated. No session, no CSRF.
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->alias([
            'jwt'    => AuthenticateJwt::class,
            'tenant'     => EnsureTenantScope::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every error leaves as JSON. The React SPA has no HTML error handling.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })
    ->create();
