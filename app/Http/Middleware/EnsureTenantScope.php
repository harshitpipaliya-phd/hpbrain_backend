<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant isolation. Non-negotiable and CI-enforced in the Node build; kept
 * that way here. Resolves the tenant from the authenticated token, never from
 * a client-supplied body or query parameter, so a caller cannot read another
 * tenant's data by changing a URL segment.
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
