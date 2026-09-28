<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

/**
 * Who is asking, and which tenant they are asking about.
 *
 * HP Brain's counterpart of G2G's `AiRequestScope`. Built only from the
 * attributes the `jwt` and `tenant` middleware put on the request — never from
 * input — so a caller cannot reach another tenant's rows by editing a body or
 * a query string. Every AI & Intelligence controller and domain class reads the
 * tenant from here.
 */
final class AiIntelligenceScope
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly string $role,
    ) {
    }

    /**
     * The platform super administrator.
     *
     * Only this role may write platform rows (`tenant_id = '*'`) that every tenant
     * reads. G2G let any organisation administrator publish a template to every
     * organisation; here a tenant administrator writes their own tenant only.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'role' => $this->role,
        ];
    }
}
