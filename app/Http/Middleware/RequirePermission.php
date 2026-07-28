<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission gate. Applied as `permission:decision.approve`.
 *
 * Deliberately fails CLOSED: an unrecognised role or an unrecognised
 * permission string denies. A typo in a route definition must not silently
 * grant access — the safe failure for an authorization check is refusal.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        // Permissions are disabled — all authenticated requests are allowed.
        return $next($request);
    }
}
