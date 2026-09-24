<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

/**
 * Which source column means what.
 *
 * A row from a CSV or an ERP is a bag of strings under the source's own names.
 * A Signal needs a title; Evidence needs text and a timestamp. This class holds
 * that correspondence and nothing else — it does no IO, so a mapping can be
 * previewed, corrected, and re-previewed without touching the source.
 *
 * IT IS STORED PER SOURCE, NOT COMPILED IN. hpbrain_data_sources.field_map
 * holds it as JSON, because the two real CSVs already in play (the ISP export
 * and the scholarclone task export) have different column names for the same
 * concepts, and a mapping baked into PHP would mean a deploy per customer file.
 *
 * AN UNMAPPED CANONICAL FIELD IS NULL, NOT AN EMPTY STRING. "This source has no
 * completion remarks" and "this row's remark is blank" are different facts, and
 * the second is evidence while the first is not. Collapsing them would silently
 * manufacture empty Evidence rows for every row of a file that has no remarks
 * column at all.
 */
final class FieldMap
{
    /** Canonical targets. Anything not on this list is ignored by design. */
    public const CANONICAL = [
        'title',              // → Signal.metadata.title, and the dedupe key
        'owner',              // → Signal.metadata.owner
        'state',              // → Signal.classification / OperationalRecord.status
        'evidence_text',      // → Evidence.content.text
        'evidence_timestamp', // → Evidence.content.observedAt / occurred_at
        'external_ref',       // → Signal.metadata.externalRef / natural_key
        'measure',            // → OperationalRecord.metric_value
        'measure_unit',       // → OperationalRecord.metric_unit
        'subject_ref',        // → OperationalRecord.subject_ref
        'category',           // → OperationalRecord.category
        'sub_category',       // → OperationalRecord.sub_category
        'quantity',           // → OperationalRecord.quantity (the denominator)
    ];

    /**
     * Canonical targets that may bind SEVERAL source columns at once.
     *
     * Only the identity field, and only because identity is the one thing a
     * single column genuinely could not express for some real files. An
     * academic result is unique per student per year per subject per exam:
     * enrollment_no alone repeats 40-odd times per student, so binding it as
     * the natural key would collapse a student's whole transcript into one row
     * and report the rest as duplicates.
     *
     * Everything else stays single-column deliberately. A composite `measure`
     * or `category` would be a concatenation pretending to be a value, and the
     * aggregations downstream would have nothing to work with.
     *
     * @var array<int, string>
     */
    public const COMPOSITE_CAPABLE = ['external_ref'];

    /**
     * Joins the parts of a composite value.
     *
     * A character that cannot appear inside the source values being joined, so
     * ('10818','2018','MATHS','Written') cannot collide with any other
     * combination. Chosen over a comma because subject names contain those.
     */
    public const COMPOSITE_SEPARATOR = '|';

    /** @param array<string, string|array<int, string>> $map canonical field => source column(s) */
    public function __construct(private readonly array $map)
    {
    }

    /**
     * @param  array<string, mixed>|null  $raw  Decoded field_map JSON, or null.
     */
    public static function fromConfig(?array $raw): self
    {
        $clean = [];

        foreach ($raw ?? [] as $canonical => $column) {
            if (! in_array($canonical, self::CANONICAL, true)) {
                continue;
            }

            if (is_string($column) && $column !== '') {
                $clean[$canonical] = $column;

                continue;
            }

            // A list of columns is accepted only where a composite is
            // meaningful, and only after the entries are proved to be non-empty
            // strings — a stray null in the JSON would otherwise produce an
            // identity with a hole in it that still looked well-formed.
            if (is_array($column) && in_array($canonical, self::COMPOSITE_CAPABLE, true)) {
                $parts = array_values(array_filter(
                    $column,
                    static fn ($c): bool => is_string($c) && trim($c) !== '',
                ));

                if ($parts === []) {
                    continue;
                }

                // One entry is a plain column, not a composite. Stored as a
                // string so everything downstream sees the simpler shape.
                $clean[$canonical] = count($parts) === 1 ? $parts[0] : $parts;
            }
        }

        return new self($clean);
    }

    /**
     * A best-effort mapping proposed from the source's own headers.
     *
     * THIS IS A SUGGESTION SHOWN IN A PREVIEW, NEVER AN APPLIED DEFAULT. It
     * matches on normalised substrings, which is right often enough to save
     * typing and wrong often enough that committing on it unreviewed would
     * write nonsense into the graph under a real provenance record. The commit
     * path requires an explicit stored map for exactly this reason.
     *
     * @param  array<int, string>  $headers
     */
    public static function suggestFrom(array $headers): self
    {
        $hints = [
            'title'              => ['subject', 'title', 'name', 'task'],
            'owner'              => ['assigned', 'owner', 'responsible', 'employee'],
            'state'              => ['status', 'state', 'stage'],
            'evidence_text'      => ['remark', 'comment', 'note', 'description'],
            'evidence_timestamp' => ['date', 'time', 'when'],
            'external_ref'       => ['id', 'ref', 'code', 'number'],
            'measure'            => ['amount', 'obtain'],
            'subject_ref'        => ['unique id', 'gr no', 'enrollment'],
            'category'           => ['student quota'],
            'quantity'           => ['total'],
            'sub_category'       => ['exam'],
        ];

        $map = [];

        foreach ($hints as $canonical => $needles) {
            foreach ($headers as $header) {
                $normalised = strtolower((string) $header);

                foreach ($needles as $needle) {
                    if (str_contains($normalised, $needle) && ! in_array($header, $map, true)) {
                        $map[$canonical] = $header;
                        continue 3;
                    }
                }
            }
        }

        return new self($map);
    }

    public function has(string $canonical): bool
    {
        return isset($this->map[$canonical]);
    }

    /**
     * The value a row carries for one canonical field.
     *
     * A COMPOSITE BINDING IS ALL-OR-NOTHING. When the field binds several
     * columns and any one of them is blank on this row, the whole value is
     * null rather than a shortened join. The alternative — joining whatever is
     * present — produces keys of differing shape from the same file, so
     * ('10818','2018','MATHS','') and ('10818','2018','MATHS') would be
     * distinct strings describing the same result, and the dedupe that natural
     * keys exist for would quietly stop working. Null makes the row skip and
     * say why.
     *
     * @param array<string, mixed> $row
     */
    public function value(array $row, string $canonical): ?string
    {
        if (! isset($this->map[$canonical])) {
            return null;
        }

        $binding = $this->map[$canonical];

        if (is_array($binding)) {
            $parts = [];

            foreach ($binding as $column) {
                $raw = $row[$column] ?? null;
                $part = $raw === null ? '' : trim((string) $raw);

                if ($part === '') {
                    return null;
                }

                $parts[] = $part;
            }

            return implode(self::COMPOSITE_SEPARATOR, $parts);
        }

        $raw = $row[$binding] ?? null;

        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    /**
     * The source columns a canonical field binds, always as a list.
     *
     * For the preview, so a reviewer can see that an identity is built from
     * four columns rather than guessing from a joined example value.
     *
     * @return array<int, string>
     */
    public function columnsFor(string $canonical): array
    {
        if (! isset($this->map[$canonical])) {
            return [];
        }

        $binding = $this->map[$canonical];

        return is_array($binding) ? array_values($binding) : [$binding];
    }

    public function isComposite(string $canonical): bool
    {
        return is_array($this->map[$canonical] ?? null);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->map;
    }

    /**
     * Canonical fields this map does not cover.
     *
     * Surfaced in the preview so the reviewer sees what will be MISSING from
     * every committed row, rather than discovering it after the write.
     *
     * @return array<int, string>
     */
    public function unmapped(): array
    {
        return array_values(array_diff(self::CANONICAL, array_keys($this->map)));
    }

    /**
     * The minimum a map must cover before anything may be committed.
     *
     * Without a title there is nothing to call the Signal and no stable dedupe
     * key; without a state its classification would be invented. Both are
     * required, and the commit path refuses rather than substituting defaults.
     */
    public function isCommittable(): bool
    {
        return $this->has('title') && $this->has('state');
    }
}
