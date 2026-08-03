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
 *
 * No backdoors. No dev-bypass tokens. Every request must carry a valid,
 * correctly-typed JWT signed with the configured JWT_SECRET.
 */
final class AuthenticateJwt
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['error' => 'missing_token'], 401);
        }

        $rawToken = substr($header, 7);

        try {
            $claims = Jwt::verify($rawToken);
        } catch (Throwable) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        if (($claims['type'] ?? null) !== 'access') {
            return response()->json(['error' => 'wrong_token_type'], 401);
        }

        if ($roles !== [] && ! in_array($claims['role'] ?? '', $roles, true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $request->attributes->set('auth.userId', $claims['sub'] ?? null);
        $request->attributes->set('auth.tenantId', $claims['tenantId'] ?? null);
        $request->attributes->set('auth.role', $claims['role'] ?? null);

        return $next($request);
    }
}
