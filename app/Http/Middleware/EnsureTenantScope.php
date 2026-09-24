<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant isolation.
 *
 * The tenant in the authenticated token is the only tenant a request may use.
 * Route parameters can narrow to that same tenant, but they cannot switch the
 * request to another organization, including for admin users.
 */
final class EnsureTenantScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenTenant = $request->attributes->get('auth.tenantId');

        if (! is_string($tokenTenant) || $tokenTenant === '') {
            return response()->json(['error' => 'tenant_unresolved'], 401);
        }

        $routeTenant = $request->route('tenantId');

        if (is_string($routeTenant) && $routeTenant !== $tokenTenant) {
            return response()->json(['error' => 'tenant_mismatch'], 403);
        }

        $request->attributes->set('tenantId', $tokenTenant);

        return $next($request);
    }
}
