<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * No migration may declare a fractional column without a scale.
 *
 * WHY THIS TEST READS FILES INSTEAD OF QUERYING A SCHEMA. The damage this
 * guards against is invisible to every other kind of test. SQLite — which the
 * whole suite runs on — treats DECIMAL as advisory: 0.85 written to a
 * DECIMAL(10,0) column comes back as 0.85, so a round-trip assertion passes
 * happily while the same code on MySQL returns 1. The defect only exists in the
 * DDL, so the DDL is what gets asserted.
 *
 * The cost of missing it, measured on the live database before it was fixed:
 * twenty-seven columns, including EVERY confidence score in the product, could
 * hold only 0 or 1. Inserting 0.85, 0.30, 0.49 and 0.78 returned 1, 0, 0, 1.
 */
final class DecimalPrecisionTest extends TestCase
{
    private const MIGRATIONS = __DIR__.'/../../../database/migrations';

    /**
     * A raw DDL type with no scale: DECIMAL, DECIMAL(10) and DECIMAL(10,0) are
     * one column type with three spellings, and MySQL supplies the 0 itself
     * whenever it is not written.
     *
     * `(?<!->)` keeps this off Laravel's fluent builder, where `decimal` is a
     * method whose arguments are commas, not parentheses. That form is checked
     * separately by FLUENT below.
     */
    private const RAW_DDL = '/(?<!->)\b(DECIMAL|NUMERIC)\b(?!\s*\(\s*\d+\s*,\s*[1-9])/i';

    /**
     * The fluent equivalent. `->decimal('x')` is fine — Laravel defaults to
     * (8,2) — so only an explicitly zero scale offends.
     */
    private const FLUENT = "/->\s*decimal\s*\(\s*'[^']+'\s*,\s*\d+\s*,\s*0\s*\)/i";

    /** @test */
    public function every_decimal_column_declares_a_scale(): void
    {
        $offences = [];

        foreach (glob(self::MIGRATIONS.'/*.php') ?: [] as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $i => $line) {
                // The migration that FIXES this problem necessarily names the
                // broken type — in its explanation, and in the pattern it uses
                // to recognise columns still carrying it.
                if ($this->isProse($line) || $this->inspectsColumnTypes($line)) {
                    continue;
                }

                if (preg_match(self::RAW_DDL, $line) || preg_match(self::FLUENT, $line)) {
                    $offences[] = basename($file).':'.($i + 1).'  '.trim($line);
                }
            }
        }

        $this->assertSame([], $offences, implode("\n", array_merge(
            ['A fractional column was declared without a scale. MySQL reads that as',
                'DECIMAL(x,0) and rounds every value to a whole number on write —',
                'confidence 0.85 stores as 1, and 0.49 stores as 0.',
                'Use DECIMAL(6,4) for anything in 0..1, DECIMAL(4,2) for a 0..5 level.',
                ''],
            $offences,
        )));
    }

    /**
     * A line that talks ABOUT the type rather than declaring one.
     *
     * Comment markers are checked at the start of the trimmed line, so a real
     * declaration with a trailing comment is still inspected.
     */
    private function isProse(string $line): bool
    {
        $t = ltrim($line);

        foreach (['*', '//', '#', "'", '"'] as $marker) {
            if (str_starts_with($t, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A line that READS a column type rather than declaring one — the guard in
     * the repair migration that decides whether a column still needs widening.
     */
    private function inspectsColumnTypes(string $line): bool
    {
        return str_contains($line, 'preg_match')
            || str_contains($line, 'column_type')
            || str_contains($line, 'data_type');
    }
}
