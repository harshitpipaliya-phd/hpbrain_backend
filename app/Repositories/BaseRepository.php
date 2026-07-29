<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Base for every hpbrain_* repository.
 *
 * Two rules are enforced here rather than left to each subclass, because both
 * were violated in the previous implementation and neither failure was
 * visible to the test suite:
 *
 * 1. TENANT SCOPING. Every query goes through table(), which requires a
 *    tenantId. A Cypher/SQL query missing tenant_id was a CI-failing offence
 *    in the Node build; making it structurally impossible is better than
 *    catching it in review.
 *
 * 2. MYSQL DATETIME. PHP DateTime and ISO-8601 strings must never reach a
 *    DATETIME column as RFC-3339 (2026-07-27T05:56:01.853Z) — MySQL rejects
 *    that literal outright (error 1292). now() below is the only timestamp
 *    source repositories should use.
 */
abstract class BaseRepository
{
    abstract protected function table(): string;

    protected function scoped(string $tenantId): Builder
    {
        return DB::table($this->table())->where('tenant_id', $tenantId);
    }

    protected function newId(): string
    {
        return Uuid::uuid4()->toString();
    }

    /** MySQL-legal timestamp. Never use date('c') — that emits RFC-3339. */
    protected function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Columns this table stores as JSON. Overridden by the repositories that
     * have any; empty here so a table of plain scalars needs no ceremony.
     *
     * @return array<int, string>
     */
    protected function jsonColumns(): array
    {
        return [];
    }

    /**
     * A database row as the API should express it.
     *
     * PDO hands back JSON columns as strings — `capability_tags` arrives as the
     * six characters `["a"]`, not as a list. Every consumer of this API treats
     * those fields as structured: the agent monitor calls .map on capabilityTags,
     * policy management calls .map on rules. Against a string, .map does not
     * exist, and the screen went blank rather than showing a policy.
     *
     * A column that does not parse is left exactly as it came. That case means
     * the stored text is not JSON, and quietly turning it into null would
     * destroy the only copy of whatever it actually is.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function hydrate(array $row): array
    {
        foreach ($this->jsonColumns() as $column) {
            if (! isset($row[$column]) || ! is_string($row[$column])) {
                continue;
            }

            $decoded = json_decode($row[$column], true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $row[$column] = $decoded;
            }
        }

        return $row;
    }
}
