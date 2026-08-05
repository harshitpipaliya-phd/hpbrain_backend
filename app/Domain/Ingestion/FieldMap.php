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
        'state',              // → Signal.classification
        'evidence_text',      // → Evidence.content.text
        'evidence_timestamp', // → Evidence.content.observedAt
        'external_ref',       // → Signal.metadata.externalRef (stable source id)
    ];

    /** @param array<string, string> $map canonical field => source column */
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
            if (in_array($canonical, self::CANONICAL, true) && is_string($column) && $column !== '') {
                $clean[$canonical] = $column;
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

    /** @param array<string, mixed> $row */
    public function value(array $row, string $canonical): ?string
    {
        if (! isset($this->map[$canonical])) {
            return null;
        }

        $raw = $row[$this->map[$canonical]] ?? null;

        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
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
