<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Ramsey\Uuid\Uuid;

/**
 * The conventions every hpbrain_ai_* table in this layer shares, in one place.
 *
 * PLATFORM ROWS ARE `tenant_id = '*'`
 *
 * The same reserved id hpbrain_recommendations and hpbrain_prompt_templates
 * already use for "every tenant" (see TenantOwnedTables::RESERVED_TENANT_IDS).
 * G2G spelled it `sub_institute_id IS NULL`; a NOT NULL sentinel is better here
 * because it keeps unique indexes meaningful — two NULLs never collide, so G2G's
 * "one model per scope" unique index never actually held for platform rows.
 *
 * Reads are `tenant_id = X OR tenant_id = '*'`; writes stamp X. Nothing in this
 * layer writes '*' except a migration seed or a super administrator.
 *
 * TIME IS UTC `Y-m-d H:i:s`
 *
 * Matches BaseRepository::now(). MySQL rejects RFC-3339 in a DATETIME column,
 * and every period boundary (quota month, usage window) is computed from the
 * same clock so a boundary and the rows it compares against agree.
 */
final class Platform
{
    public const TENANT = '*';

    public static function id(): string
    {
        return Uuid::uuid4()->toString();
    }

    public static function now(): string
    {
        return self::clock()->format('Y-m-d H:i:s');
    }

    public static function clock(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** `Y-m-d H:i:s`, `$days` ago. */
    public static function daysAgo(int $days): string
    {
        return self::clock()->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    /** The start of the current quota period: today, or this calendar month. */
    public static function periodStart(string $period): string
    {
        $now = self::clock();

        return $period === 'day'
            ? $now->format('Y-m-d 00:00:00')
            : $now->format('Y-m-01 00:00:00');
    }

    /** Restrict a query to this tenant's rows plus the platform's. */
    public static function visible(Builder $query, string $tenantId, string $column = 'tenant_id'): Builder
    {
        return $query->where(function (Builder $inner) use ($tenantId, $column) {
            $inner->where($column, $tenantId)->orWhere($column, self::TENANT);
        });
    }

    public static function isPlatform(?object $row, string $column = 'tenant_id'): bool
    {
        return $row !== null && (string) ($row->{$column} ?? '') === self::TENANT;
    }

    /** A tenant id as the API shows it: platform rows read back as null, as in G2G. */
    public static function present(?string $tenantId): ?string
    {
        return $tenantId === null || $tenantId === self::TENANT ? null : $tenantId;
    }

    public static function encode(mixed $value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<mixed> */
    public static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
