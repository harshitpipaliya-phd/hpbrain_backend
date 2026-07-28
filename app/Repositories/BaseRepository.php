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
}
