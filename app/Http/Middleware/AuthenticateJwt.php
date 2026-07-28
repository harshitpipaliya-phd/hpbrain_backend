<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Jwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bearer-token authentication. Mirrors api/src/auth/auth.middleware.ts.
 *
 * The decoded tenantId is stashed on the request attributes, which is where
 * EnsureTenantScope reads it from. Nothing downstream may take a tenantId from
 * the request body or query string.
 */
final class AuthenticateJwt
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Auth is disabled — all requests are treated as the demo admin user.
        $request->attributes->set('auth.userId', 'dev-user-1');
        $request->attributes->set('auth.tenantId', '6');
        $request->attributes->set('auth.role', 'admin');
        return $next($request);
    }
}
