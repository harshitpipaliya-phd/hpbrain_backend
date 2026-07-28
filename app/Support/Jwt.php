<?php

declare(strict_types=1);

namespace App\Support;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Access/refresh tokens. Mirrors api/src/auth/jwt.ts.
 *
 * The default-secret guard is deliberately fatal: the Node build refused to
 * boot in production with a placeholder secret, and that is a real security
 * control, not a developer inconvenience.
 */
final class Jwt
{
    private const ALGO = 'HS256';

    public static function secret(): string
    {
        $secret = (string) config('brain.jwt_secret', env('JWT_SECRET', ''));

        if ($secret === '' || $secret === 'dev-only-secret') {
            if (app()->environment('production')) {
                throw new RuntimeException('JWT_SECRET must be set to a real value in production.');
            }
        }

        return $secret;
    }

    public static function issueAccess(array $user, int $ttlSeconds = 900): string
    {
        return self::sign($user, $ttlSeconds, 'access');
    }

    public static function issueRefresh(array $user, int $ttlSeconds = 604800): string
    {
        return self::sign($user, $ttlSeconds, 'refresh');
    }

    private static function sign(array $user, int $ttl, string $type): string
    {
        $now = time();

        return FirebaseJwt::encode([
            'sub'      => $user['id'],
            'tenantId' => $user['tenantId'],
            'role'     => $user['role'],
            'type'     => $type,
            'iat'      => $now,
            'exp'      => $now + $ttl,
        ], self::secret(), self::ALGO);
    }

    public static function verify(string $token): array
    {
        return (array) FirebaseJwt::decode($token, new Key(self::secret(), self::ALGO));
    }
}
