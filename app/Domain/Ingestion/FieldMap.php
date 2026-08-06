<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

/**
<<<<<<< HEAD
 * The canonical ingestion vocabulary, and header → canonical suggestion.
 *
 * THIS CLASS IS REFERENCED BY CODE THAT SHIPPED BEFORE IT EXISTED.
 * web/src/api/ingestion.ts says its CANONICAL_FIELDS list mirrors
 * "FieldMap::CANONICAL server-side" — and there was no such class, which is
 * part of why the Ingestion screen could not work (docs/API-FUNCTIONAL-AUDIT.md
 * F4). The list below is byte-identical to the client's, in the same order,
 * because the two are a contract and a drift between them is a silent mapping
 * bug rather than a compile error.
 *
 * SUGGESTION IS ADVISORY, NEVER AUTOMATIC. suggest() proposes; a human confirms
 * in the mapping step before commit() writes anything. A wrong auto-mapping
 * that nobody reviewed produces Signals whose content is confidently attached
 * to the wrong column, which is worse than no mapping at all — the row looks
 * complete and is false.
 */
final class FieldMap
{
    /**
     * Mirrors CANONICAL_FIELDS in web/src/api/ingestion.ts. Keep in step.
     *
     * @var array<int, string>
     */
    public const CANONICAL = [
        'title',
        'state',
        'owner',
        'evidence_text',
        'evidence_timestamp',
        'external_ref',
    ];

    /**
     * Without a title there is nothing to name the Signal; without a state its
     * classification would be invented. Mirrors REQUIRED_FIELDS client-side.
     *
     * @var array<int, string>
     */
    public const REQUIRED = ['title', 'state'];

    /**
     * Header synonyms, normalised. First match wins, so the more specific
     * spellings are listed before the generic ones.
     *
     * @var array<string, array<int, string>>
     */
    private const SYNONYMS = [
        'title'              => ['title', 'name', 'subject', 'summary', 'headline', 'issue', 'ticket', 'task'],
        'state'              => ['state', 'status', 'stage', 'phase', 'condition', 'disposition'],
        'owner'              => ['owner', 'assignee', 'assignedto', 'responsible', 'engineer', 'agent', 'handler'],
        'evidence_text'      => ['evidencetext', 'evidence', 'description', 'details', 'notes', 'remarks', 'comment', 'body'],
        'evidence_timestamp' => ['evidencetimestamp', 'timestamp', 'date', 'createdat', 'createddate', 'occurredat', 'generationdate', 'reporteddate'],
        'external_ref'       => ['externalref', 'ref', 'reference', 'externalid', 'sourceid', 'ticketno', 'ticketnumber', 'id'],
    ];

    /**
     * Case-, space- and punctuation-insensitive.
     *
     * The real workbooks in this installation contain 'Junction Box ' with a
     * trailing space and 'zone' lowercase beside title-case siblings — see
     * RowMapper, which normalises for exactly the same reason. Requiring an
     * exact header match would make every mapping fragile against the next
     * export, where somebody tidies a column name.
     */
    public static function normalise(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($header))) ?? '';
    }

    /**
     * Propose canonical => source header for the headers a file actually has.
     *
     * A canonical field with no plausible header is OMITTED rather than mapped
     * to a guess. The client seeds its form from CANONICAL and renders the
     * missing ones as "not mapped", so an absent key is a visible prompt to a
     * human, not a silent hole.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string>
     */
    public static function suggest(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $header) {
            $key = self::normalise((string) $header);

            // First header wins a collision: two columns normalising the same
            // is a defect in the file, and picking the later one silently would
            // depend on column order nobody controls.
            if ($key !== '' && ! isset($normalised[$key])) {
                $normalised[$key] = (string) $header;
            }
        }

        $suggested = [];
        $taken = [];

        foreach (self::SYNONYMS as $canonical => $candidates) {
            foreach ($candidates as $candidate) {
                if (! isset($normalised[$candidate])) {
                    continue;
                }

                $header = $normalised[$candidate];

                // One source header may not fill two canonical fields. Without
                // this, a file whose only column is 'id' would bind both
                // external_ref and title to it, and every Signal would be named
                // after its own reference number.
                if (in_array($header, $taken, true)) {
                    continue;
                }

                $suggested[$canonical] = $header;
                $taken[] = $header;
                break;
            }
        }

        return $suggested;
    }

    /**
     * Headers no canonical field claimed. Shown to the reviewer so a column
     * carrying the real content cannot be dropped without anyone noticing.
     *
     * @param  array<int, string>  $headers
     * @param  array<string, string>  $suggested
     * @return array<int, string>
     */
    public static function unmapped(array $headers, array $suggested): array
    {
        $used = array_values($suggested);

        return array_values(array_filter(
            array_map('strval', $headers),
            static fn (string $h): bool => ! in_array($h, $used, true),
        ));
    }

    /**
     * Whether a mapping binds every required field.
     *
     * @param  array<string, string>  $map
     */
    public static function isCommittable(array $map): bool
    {
        foreach (self::REQUIRED as $field) {
            if (($map[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical fields present in a submitted map, discarding anything not in
     * CANONICAL.
     *
     * Fails closed against a client that posts extra keys: an unknown canonical
     * name is dropped rather than written into a Signal's metadata, where it
     * would look like a field the system understands.
     *
     * @param  array<string, mixed>  $map
     * @return array<string, string>
     */
    public static function sanitise(array $map): array
    {
        $clean = [];

        foreach (self::CANONICAL as $field) {
            $value = $map[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $clean[$field] = trim($value);
            }
        }

        return $clean;
=======
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
>>>>>>> c469402d456a8c3f32d2b480606cba4e1f1e4042
    }
}
