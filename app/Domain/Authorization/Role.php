<?php

declare(strict_types=1);

namespace App\Domain\Authorization;

/**
 * Roles and the permissions each grants.
 *
 * WHY THIS EXISTS: until now the build treated authentication as
 * authorization. `AuthenticateJwt` accepted optional role arguments but no
 * route ever passed any, and there were no policies or gates — so any
 * authenticated user could approve decisions, execute ESOs, rotate API keys
 * and change settings within their tenant. That is the single most serious
 * defect the audit found.
 *
 * The permission names below are deliberately verb-scoped rather than
 * table-scoped, because the sensitive operations in this product are not CRUD:
 * approving a decision and executing an ESO are governance acts (Agent Brain,
 * ADR-004) and must be separable from ordinary writes.
 */
enum Role: string
{
    case VIEWER      = 'viewer';
    case ANALYST     = 'analyst';
    case MANAGER     = 'manager';
    case ADMIN       = 'admin';
    case TENANT_ADMIN = 'tenant_admin';

    /** @return array<int, string> */
    public function permissions(): array
    {
        return match ($this) {
            // The Analyst persona: keeps the Brain honest, must be able to read
            // everything and curate evidence, but must not approve or execute.
            self::VIEWER => [
                Permission::READ->value,
            ],
            self::ANALYST => [
                Permission::READ->value,
                Permission::CREATE->value,
                Permission::UPDATE->value,
                Permission::EVIDENCE_CURATE->value,
            ],
            // The Manager persona: receives a diagnosis, chooses an
            // intervention, approves consequential decisions.
            self::MANAGER => [
                Permission::READ->value,
                Permission::CREATE->value,
                Permission::UPDATE->value,
                Permission::EVIDENCE_CURATE->value,
                Permission::DECISION_APPROVE->value,
                Permission::ESO_EXECUTE->value,
            ],
            self::ADMIN, self::TENANT_ADMIN => Permission::allValues(),
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions(), true);
    }

    public static function tryFromName(?string $name): ?self
    {
        return $name === null ? null : self::tryFrom(strtolower($name));
    }
}
