<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use Illuminate\Support\Facades\DB;

/**
 * The four SQL expressions this engine needs that MySQL and SQLite spell differently.
 *
 * WHY THIS EXISTS, AND WHY IT IS DELIBERATELY TINY. ADR-006 makes MySQL the
 * relational store and production is MariaDB, so portability is not a product goal.
 * But the test suite runs on in-memory SQLite — pinned there by phpunit.xml
 * specifically so no test can touch the shared ERP database — and an engine written
 * in raw MySQL is an engine no test can execute. The existing analytics endpoints
 * that use TIMESTAMPDIFF have no test coverage at all, and that is the reason.
 *
 * So the seam is exactly four expressions wide, and every one of them is a date or
 * aggregate function with a direct equivalent. Nothing here abstracts a query, a
 * table name or a predicate: those stay written out in the analyzers where they can
 * be read. An abstraction layer over the whole query surface would cost far more in
 * legibility than portable tests are worth.
 *
 * ANYTHING WITHOUT AN EQUIVALENT IS GUARDED AT THE CALL SITE INSTEAD.
 * PERCENTILE_CONT is the example: SQLite has no order statistics, so
 * OrganizationDataProfiler wraps it in a try/catch and reports median and p95 as
 * null. Faking a percentile with a subquery that behaves differently would be worse
 * than not having one — the figure would look computed and would not be comparable
 * across engines.
 */
final class SqlDialect
{
    /** True on SQLite, which is the test connection. */
    public static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * A datetime column grouped to its calendar month, as `YYYY-MM`.
     *
     * The format string is identical on both engines, which is what lets the
     * resulting `period` values be compared and sorted the same way.
     */
    public static function yearMonth(string $column): string
    {
        return self::isSqlite()
            ? "strftime('%Y-%m', `{$column}`)"
            : "DATE_FORMAT(`{$column}`, '%Y-%m')";
    }

    /** Whole seconds between two datetime columns, later minus earlier. */
    public static function secondsBetween(string $from, string $to): string
    {
        return self::isSqlite()
            ? "(strftime('%s', {$to}) - strftime('%s', {$from}))"
            : "TIMESTAMPDIFF(SECOND, {$from}, {$to})";
    }

    /** Whole days between a datetime column and now. */
    public static function daysSince(string $column): string
    {
        return self::isSqlite()
            ? "CAST((julianday('now') - julianday({$column})) AS INTEGER)"
            : "TIMESTAMPDIFF(DAY, {$column}, NOW())";
    }

    /**
     * Sample standard deviation.
     *
     * SQLite has no aggregate for this and no way to express one without a
     * self-join, so it returns NULL — which the profiler already handles, because a
     * missing dispersion figure has to be reportable as missing anyway. The one
     * confidence component that reads it uses the median-to-p95 spread instead, and
     * that is null on SQLite too, so the weight redistributes rather than the score
     * silently changing meaning between engines.
     */
    public static function stdDev(string $column): string
    {
        return self::isSqlite() ? 'NULL' : "STDDEV_SAMP(`{$column}`)";
    }
}
