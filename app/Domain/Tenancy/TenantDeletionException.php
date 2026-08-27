<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * A permanent deletion that must not proceed.
 *
 * Carries a machine-readable reason and a payload, because every one of these
 * is rendered to an administrator who is about to destroy an organization and
 * needs to know precisely what stopped it. A bare message would make
 * "you did not type the name correctly" indistinguishable from "this tenant
 * holds 6,613 rows belonging to the LMS".
 */
final class TenantDeletionException extends RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    public static function reservedTenant(string $tenantId): self
    {
        return new self(
            'reserved_tenant_id',
            "'{$tenantId}' is a reserved tenant id used for platform-wide rows "
            .'(shipped signal rules, industries, templates). It is not an organization and cannot be deleted.',
            422,
            ['tenantId' => $tenantId],
        );
    }

    public static function notFound(string $tenantId): self
    {
        return new self(
            'organization_not_found',
            "No organization exists for tenant '{$tenantId}'.",
            404,
            ['tenantId' => $tenantId],
        );
    }

    public static function nameMismatch(): self
    {
        return new self(
            'confirmation_mismatch',
            'The confirmation text does not match the organization name exactly.',
            422,
        );
    }

    /**
     * A row belonging to ANOTHER tenant points at a row this tenant owns.
     *
     * Deleting it would destroy a different organization's data purely to let
     * this deletion through, which is the one thing this operation must never
     * do. The alternative — leaving it and letting the foreign key refuse — is
     * the rollback bug this class of error exists to explain rather than
     * reproduce, so the caller is told exactly which table and which rows.
     *
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    public static function crossTenantReference(string $tenantId, array $conflicts): self
    {
        $rows = array_sum(array_column($conflicts, 'rows'));

        return new self(
            'cross_tenant_reference',
            "This organization cannot be deleted because {$rows} row(s) in ".count($conflicts).' table(s) '
            .'belong to a DIFFERENT organization but reference records this one owns. Deleting them would '
            .'destroy the other organization\'s data, so nothing has been deleted. The references must be '
            .'resolved in the source system first.',
            409,
            ['tenantId' => $tenantId, 'conflicts' => $conflicts, 'rows' => $rows],
        );
    }

    /** @param array<int, array<string, mixed>> $tables */
    public static function sourceSystemData(string $tenantId, array $tables, int $rows): self
    {
        return new self(
            'source_system_data_present',
            "This organization holds {$rows} row(s) in ".count($tables).' table(s) belonging to other '
            .'applications that share this database. They are tenant-scoped, but the Brain does not own '
            .'them. Re-send with acknowledgeSourceSystemData=true to destroy them as well.',
            409,
            ['tenantId' => $tenantId, 'tables' => $tables, 'rows' => $rows],
        );
    }
}
