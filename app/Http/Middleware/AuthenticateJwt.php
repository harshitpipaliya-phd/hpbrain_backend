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

        /*
          THE SESSION'S OWN IDENTITY. This API keeps no server-side session —
          it is stateless and the session driver is `array` — so the verified
          token IS the session, and until now nothing downstream could name the
          one it was serving.

          Only these two claims are published, and neither is a secret: `jti` is
          a random per-issue identifier the caller is already holding inside the
          token it just presented, and `exp` is when that token stops working.
          The raw token, the Authorization header and the signing secret stay
          here. Revocation in this application is recorded against REFRESH token
          jtis (AuthController::refresh), not access ones, so this value is not
          a handle on anything.

          Additive: every existing attribute keeps its name and meaning.
        */
        $request->attributes->set('auth.sessionId', $claims['jti'] ?? null);
        $request->attributes->set('auth.expiresAt', $claims['exp'] ?? null);

        return $next($request);
    }
}
