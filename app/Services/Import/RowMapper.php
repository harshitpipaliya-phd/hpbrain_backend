<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Turns raw spreadsheet cells into the shape a loader can write.
 *
 * Three jobs: resolve headers to column positions, cast values, and unpivot
 * matrix sheets. All three were places the naive version got things wrong, and
 * the comments record why each rule is the way it is.
 */
final class RowMapper
{
    /**
     * Excel's day zero. Serial 1 is 1900-01-01, but Excel also believes 1900
     * was a leap year (it was not) — a bug inherited from Lotus 1-2-3 and
     * frozen into the file format. Every serial from 61 (1900-03-01) onward is
     * therefore one greater than a true day count from 1899-12-31. Anchoring at
     * 1899-12-30 absorbs the off-by-one for all modern dates, which is why this
     * constant is not 1900-01-01. Dates before 1900-03-01 do not occur in this
     * data and would need special handling if they ever did.
     */
    private const EXCEL_EPOCH = '1899-12-30';

    /** @var array<string, int> normalised header => column index */
    private array $headerIndex = [];

    /** @var array<int, string> column index => original header text */
    private array $headers = [];

    /**
     * @param  array<int, ?string>  $headerRow
     */
    public function __construct(array $headerRow)
    {
        foreach ($headerRow as $index => $header) {
            if ($header === null || trim($header) === '') {
                continue;
            }

            $this->headers[$index] = trim($header);

            // Headers are matched case- and whitespace-insensitively. The real
            // workbooks contain 'Junction Box ' with a trailing space and 'zone'
            // lowercase where every sibling column is title case. Requiring the
            // config to reproduce those exactly would make it fragile against
            // the next export, where someone tidies the header.
            $this->headerIndex[$this->normalise($header)] = $index;
        }
    }

    /** @return array<int, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function has(string $header): bool
    {
        return isset($this->headerIndex[$this->normalise($header)]);
    }

    /**
     * Raw cell value for a header, or null if the column or cell is absent.
     *
     * A short row is normal: XlsxReader densifies only to the last cell present,
     * so a row whose final column is empty is shorter than the header. That is
     * 12 of the 65,268 complaint rows.
     *
     * @param  array<int, ?string>  $row
     */
    public function value(array $row, string $header): ?string
    {
        $index = $this->headerIndex[$this->normalise($header)] ?? null;

        if ($index === null) {
            return null;
        }

        $value = $row[$index] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Verify the workbook actually contains every column the profile names.
     *
     * Run once per import, before any row is written. A profile that references
     * a renamed column would otherwise import 65,000 rows with a silently null
     * field — the failure mode this whole class exists to prevent.
     *
     * @return array<int, string> missing headers
     */
    public function missingColumns(ImportProfile $profile): array
    {
        $needed = array_merge(
            array_values($profile->map()),
            $profile->keyColumns(),
            $profile->requiredColumns(),
            $profile->payloadColumns(),
            $profile->activeFlags(),
        );

        foreach ($profile->derivations() as $rule) {
            if (str_contains($rule, ':')) {
                $needed[] = explode(':', $rule, 2)[1];
            }
        }

        if ($profile->isMatrix()) {
            $matrix = $profile->matrix();
            $needed[] = (string) $matrix['row_key'];

            foreach ((array) ($matrix['carry'] ?? []) as $header) {
                $needed[] = (string) $header;
            }
        }

        $missing = [];

        foreach (array_unique(array_filter($needed)) as $header) {
            if (! $this->has($header)) {
                $missing[] = $header;
            }
        }

        return $missing;
    }

    /**
     * Map one tabular row into loader-ready fields.
     *
     * @param  array<int, ?string>  $row
     * @return array<string, mixed>
     */
    public function mapRow(array $row, ImportProfile $profile): array
    {
        $casts  = $profile->casts();
        $fields = $profile->constants();

        foreach ($profile->map() as $target => $sourceHeader) {
            $fields[$target] = $this->cast(
                $this->value($row, $sourceHeader),
                $casts[$target] ?? 'string'
            );
        }

        foreach ($profile->derivations() as $target => $rule) {
            $fields[$target] = $this->derive($row, $rule);
        }

        $payload = [];

        foreach ($profile->payloadColumns() as $header) {
            $value = $this->value($row, $header);

            if ($value !== null) {
                $payload[$header] = $value;
            }
        }

        $fields['payload'] = $payload;

        return $fields;
    }

    /**
     * The natural key for a row, or null when a key column is empty.
     *
     * Null means "cannot be identified", and the caller records the row as an
     * error rather than inventing a key. A generated key would defeat the whole
     * idempotency contract: the same row would insert again on every re-import.
     *
     * @param  array<int, ?string>  $row
     */
    public function naturalKey(array $row, ImportProfile $profile): ?string
    {
        $parts = [];

        foreach ($profile->keyColumns() as $header) {
            $value = $this->value($row, $header);

            if ($value === null) {
                return null;
            }

            $parts[] = $value;
        }

        if ($parts === []) {
            return null;
        }

        $key = implode('|', $parts);

        // Guard the column width rather than truncating blind: a key longer
        // than the column would be silently cut and could collide with another.
        return strlen($key) > 191 ? substr(hash('sha256', $key), 0, 64) : $key;
    }

    /**
     * Which required columns are empty on this row.
     *
     * @param  array<int, ?string>  $row
     * @return array<int, string>
     */
    public function missingRequired(array $row, ImportProfile $profile): array
    {
        $missing = [];

        foreach ($profile->requiredColumns() as $header) {
            if ($this->value($row, $header) === null) {
                $missing[] = $header;
            }
        }

        return $missing;
    }

    /**
     * Unpivot one row of a matrix sheet into many records.
     *
     * The sheet's columns ARE the entities: 'Row Labels' plus one column per
     * agent, cells holding Present / Absent / Week-off / Half Day. This yields
     * one record per (date, entity) pair that actually has a value.
     *
     * @param  array<int, ?string>  $row
     * @return array<int, array<string, mixed>>
     */
    public function unpivot(array $row, ImportProfile $profile): array
    {
        $matrix    = $profile->matrix();
        $rowKeyCol = (string) $matrix['row_key'];
        $entityTo  = (string) ($matrix['entity_to'] ?? 'owner_name');
        $valueTo   = (string) ($matrix['value_to'] ?? 'status');
        $skipBlank = (bool) ($matrix['skip_blank'] ?? true);

        $rowKeyRaw = $this->value($row, $rowKeyCol);

        if ($rowKeyRaw === null) {
            return [];
        }

        $rowKey = $this->cast($rowKeyRaw, (string) ($matrix['row_key_cast'] ?? 'string'));

        if ($rowKey === null) {
            return [];
        }

        // Columns that are context or noise rather than entities.
        $excluded = [$this->normalise($rowKeyCol)];

        foreach ((array) ($matrix['ignore'] ?? []) as $header) {
            $excluded[] = $this->normalise((string) $header);
        }

        $carried = [];

        foreach ((array) ($matrix['carry'] ?? []) as $target => $header) {
            $excluded[] = $this->normalise((string) $header);
            $carried[$target] = $this->value($row, (string) $header);
        }

        $records = [];

        foreach ($this->headers as $index => $header) {
            if (in_array($this->normalise($header), $excluded, true)) {
                continue;
            }

            $cell = $row[$index] ?? null;
            $cell = $cell === null ? null : trim($cell);

            if ($skipBlank && ($cell === null || $cell === '')) {
                // A blank cell means "no submission recorded", which the sheet
                // distinguishes from an explicit 'Absent'. Importing it as an
                // absence would manufacture an accusation against a named
                // person out of a missing form.
                continue;
            }

            $records[] = $profile->constants() + $carried + [
                $entityTo     => $header,
                $valueTo      => $cell,
                'occurred_at' => $rowKey,
                // The key must be stable across re-imports, hence composed of
                // the date and the entity rather than the sheet position.
                '__key'       => substr($rowKey, 0, 10).'|'.$header,
                'payload'     => [],
            ];
        }

        return $records;
    }

    /**
     * Cast a raw cell string to the declared type.
     *
     * Returns null rather than a zero/epoch default for anything unparseable.
     * The Product Bible is explicit: "a fact the system hasn't verified is
     * null, never defaulted to 0". A complaint with an unreadable close date is
     * a complaint of unknown duration, not a complaint closed in zero hours,
     * and the difference propagates straight into SLA reporting.
     */
    public function cast(?string $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'datetime' => $this->toDateTime($value),
            'decimal'  => is_numeric($value) ? round((float) $value, 4) : null,
            'int'      => is_numeric($value) ? (int) round((float) $value) : null,
            default    => $value,
        };
    }

    /**
     * Excel dates arrive one of two ways and both occur in these workbooks:
     * as a serial number (45777) when the cell is date-formatted, and as a
     * literal string ('2025-04-01 07:33:01.743000') when the export wrote text.
     *
     * Output is always MySQL DATETIME format. Never date('c') — that emits
     * RFC-3339, which MySQL rejects outright with error 1292 (the rule
     * BaseRepository::now() exists to enforce).
     */
    public function toDateTime(string $value): ?string
    {
        $value = trim($value);

        // Serial number path. The lower bound of 1 excludes 0 and negatives;
        // the upper bound of 2958465 is 9999-12-31, past which it is certainly
        // not a date.
        if (is_numeric($value)) {
            $serial = (float) $value;

            if ($serial < 1 || $serial > 2958465) {
                return null;
            }

            $days    = (int) floor($serial);
            $seconds = (int) round(($serial - $days) * 86400);

            $date = (new \DateTimeImmutable(self::EXCEL_EPOCH, new \DateTimeZone('UTC')))
                ->modify("+{$days} days")
                ->modify("+{$seconds} seconds");

            return $date->format('Y-m-d H:i:s');
        }

        // Textual path. The complaint export writes microseconds
        // ('2025-04-01 07:33:01.743000') and the date columns write
        // day-first ('01-04-2025'). strtotime reads 01-04-2025 as
        // 1 April only because of the dashes; with slashes it would read it as
        // 4 January. Day-first with dashes is handled explicitly rather than
        // left to that coincidence.
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m)) {
            return sprintf('%s-%s-%s 00:00:00', $m[3], $m[2], $m[1]);
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param  array<int, ?string>  $row
     */
    private function derive(array $row, string $rule): ?string
    {
        [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);

        return match ($name) {
            // A record is closed exactly when its close column has a value.
            // The complaint sheet has no status column; the presence of
            // CloseDate is the only statement it makes about state.
            'closed_if_present' => $argument !== null && $this->value($row, $argument) !== null
                ? 'closed'
                : 'open',
            default => null,
        };
    }

    private function normalise(string $header): string
    {
        // Collapse internal whitespace too: 'Total Calls(Total  Answer...)'
        // differs from the config by a double space in some exports.
        return strtolower(preg_replace('/\s+/', ' ', trim($header)) ?? '');
    }
}
