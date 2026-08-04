<?php

declare(strict_types=1);

namespace App\Domain\Industry;

/**
 * Maps whatever the ERP calls an industry onto a pack code.
 *
 * The ERP stores industry as free text typed by whoever created the
 * organization. The live values are 'technology', 'Finance', 'Healthcare' and
 * 'Telecommunications' — four spellings, two of which match a pack code exactly
 * and two of which do not match anything.
 *
 * IT RETURNS NULL RATHER THAN A DEFAULT, and that is the whole design.
 *
 * Falling back to a generic pack would be the obvious convenience and the wrong
 * answer: a hospital provisioned with 'corporate' capabilities gets a register
 * containing Commercial Acumen and Written Communication and containing nothing
 * about medication safety or safeguarding. Every downstream screen would then
 * report coverage, deficit and criticality against a set of capabilities that
 * has nothing to do with what the organization actually does — confidently, and
 * with no visible sign that the premise is wrong. An unclassified organization
 * that provisions NOTHING is obviously incomplete; a misclassified one is not.
 *
 * Unmatched is therefore a result the caller must handle, not an error state.
 */
final class IndustryClassifier
{
    /**
     * pack code => the spellings that mean it.
     *
     * Matching is on a normalised form: lowercased, non-letters collapsed. A
     * term matches if it appears as a whole word or as the entire value, so
     * 'finance' matches 'Finance' and 'Financial Services' but 'refinance'
     * does not accidentally match either.
     *
     * @var array<string, array<int, string>>
     */
    private const SYNONYMS = [
        'healthcare' => ['healthcare', 'health care', 'health', 'hospital', 'clinic', 'medical', 'nursing', 'pharma', 'pharmaceutical', 'diagnostics', 'life sciences'],
        'bfsi'       => ['bfsi', 'finance', 'financial', 'financial services', 'bank', 'banking', 'insurance', 'fintech', 'lending', 'nbfc', 'capital markets', 'wealth', 'accounting'],
        'technology' => ['technology', 'tech', 'software', 'it', 'information technology', 'saas', 'engineering software', 'product engineering', 'data', 'ai'],
        'telecom'    => ['telecom', 'telecoms', 'telecommunication', 'telecommunications', 'network', 'networking', 'isp', 'broadband', 'fiber', 'fibre', 'mobile operator'],
        'manufacturing' => ['manufacturing', 'manufacture', 'factory', 'industrial', 'production', 'automotive', 'engineering', 'fmcg', 'chemicals', 'textiles'],
        'retail'     => ['retail', 'ecommerce', 'e commerce', 'commerce', 'store', 'stores', 'supermarket', 'grocery', 'consumer', 'hospitality'],
        'government' => ['government', 'public sector', 'public', 'municipal', 'municipality', 'civic', 'defence', 'defense', 'regulator', 'ministry'],
        'ngo'        => ['ngo', 'non profit', 'nonprofit', 'not for profit', 'charity', 'trust', 'foundation', 'social', 'development sector'],
        'k12_education'    => ['k12', 'k 12', 'school', 'schools', 'education', 'primary', 'secondary', 'cbse', 'icse'],
        'higher_education' => ['higher education', 'university', 'college', 'institute', 'academia', 'academic', 'campus'],
    ];

    /**
     * The pack code for a free-text industry, or null when nothing matches.
     *
     * Longer terms are tested first so 'higher education' cannot be claimed by
     * 'education', and 'financial services' cannot be claimed by a shorter
     * term in another pack.
     */
    public static function classify(?string $raw): ?string
    {
        $value = self::normalise($raw);

        if ($value === '') {
            return null;
        }

        $candidates = [];

        foreach (self::SYNONYMS as $code => $terms) {
            foreach ($terms as $term) {
                if (self::matches($value, $term)) {
                    $candidates[$term] = $code;
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Longest matching term wins. 'higher education' beats 'education';
        // 'information technology' beats 'it'.
        uksort($candidates, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return reset($candidates);
    }

    /**
     * Whole value, or whole word within it.
     *
     * Substring matching would be actively harmful here: 'it' appears inside
     * 'security', 'hospitality' and 'institute', and would classify a hotel
     * group and a university as technology companies.
     */
    private static function matches(string $value, string $term): bool
    {
        if ($value === $term) {
            return true;
        }

        return preg_match('/(?:^|\s)'.preg_quote($term, '/').'(?:\s|$)/', $value) === 1;
    }

    /** Lowercase, and reduce every run of non-letters to one space. */
    private static function normalise(?string $raw): string
    {
        return trim(preg_replace('/[^a-z]+/', ' ', strtolower((string) $raw)) ?? '');
    }

    /**
     * Every distinct raw value that did not classify, for reporting.
     *
     * @param  array<int, string|null>  $rawValues
     * @return array<int, string>
     */
    public static function unclassified(array $rawValues): array
    {
        $out = [];

        foreach ($rawValues as $raw) {
            if (self::classify($raw) === null) {
                $out[] = (string) $raw;
            }
        }

        return array_values(array_unique($out));
    }
}
