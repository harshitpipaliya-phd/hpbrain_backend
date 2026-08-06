<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

/**
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
    }
}
