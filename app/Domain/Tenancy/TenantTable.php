<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * One table, classified against one tenant.
 *
 * The classification — not the table name — is what decides whether rows are
 * destroyed. It is computed from the live schema at request time (see
 * TenantOwnedTables) rather than written down, because a hand-maintained list
 * of 100+ tables drifts the moment someone adds a migration, and the direction
 * it drifts in is "a table nobody remembered is silently left behind".
 */
final class TenantTable
{
    /**
     * Brain-owned. hpbrain_-prefixed and carrying tenant_id — created by this
     * application, read by nothing else. Safe to destroy.
     */
    public const TIER_BRAIN = 'brain';

    /**
     * The tenant's identity and organization records inside the shared ERP:
     * the organization row, its profile, its people, their profiles, its
     * departments and job titles, and the tenant root itself.
     *
     * These are the records that make the organization exist and make its
     * people able to sign in, so a permanent deletion that leaves them behind
     * is the bug this whole operation exists to fix. They live in tables the
     * ERP also uses, so they are deleted BY TENANT SCOPE ONLY and never by
     * truncation.
     */
    public const TIER_IDENTITY = 'identity';

    /**
     * Tenant-scoped rows in tables belonging to OTHER applications sharing this
     * database — the LMS, CRM, talent, task-management and competency suites.
     *
     * Owned by the tenant, but not by the Brain. Never deleted without an
     * explicit, separate acknowledgement from the caller, because the Brain
     * cannot know what those systems do when their rows vanish underneath them.
     */
    public const TIER_SOURCE_SYSTEM = 'source_system';

    /**
     * Global, shared or unclassifiable. Never touched.
     *
     * Includes every table with no tenant column at all, and — importantly —
     * rows inside tenant-scoped tables that carry a reserved tenant id such as
     * 'platform' or '*'. Those hold the shipped signal rules, industries,
     * industry templates and prompt templates every tenant reads.
     */
    public const TIER_PRESERVED = 'preserved';

    public function __construct(
        public readonly string $table,
        /** The column that scopes this table to a tenant. */
        public readonly string $tenantColumn,
        public readonly string $tier,
        /** Rows this tenant currently holds, or null when not yet counted. */
        public readonly ?int $rows = null,
        /**
         * A self-referencing foreign key column, if the table has one. Nulled
         * before the delete so a parent row cannot be removed while a sibling
         * child row still points at it — see TenantPurgeService.
         */
        public readonly ?string $selfReferenceColumn = null,
        /** Why this table is preserved, when it is. */
        public readonly ?string $note = null,
    ) {
    }

    public function withRows(int $rows): self
    {
        return new self(
            $this->table,
            $this->tenantColumn,
            $this->tier,
            $rows,
            $this->selfReferenceColumn,
            $this->note,
        );
    }

    public function isDeletable(): bool
    {
        return $this->tier !== self::TIER_PRESERVED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'table'  => $this->table,
            'column' => $this->tenantColumn,
            'tier'   => $this->tier,
            'rows'   => $this->rows,
            'note'   => $this->note,
        ], static fn ($v) => $v !== null);
    }
}
