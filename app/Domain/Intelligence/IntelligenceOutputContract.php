<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * ONE VOCABULARY FOR "WHAT KIND OF STATEMENT IS THIS", SHARED ACROSS EVERY
 * INTELLIGENCE SCREEN.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * THE GAP THIS CLOSES
 *
 * DepartmentVerdict, PersonIntelligenceService and OrganizationScorecard each
 * independently invented their own shape for "a thing worth telling the
 * reader" — DepartmentProfile's narrative lines carry a `kind` of
 * observation/trend/risk/opportunity; OrganizationScorecard's dimensions
 * carry `supported`/`band` but no kind at all; PersonIntelligenceService's
 * recommendation carries a bare `rootCause: DETERMINED|UNDETERMINED` string.
 * None of the three can be read the same way, and a fourth screen would have
 * invented a fourth shape.
 *
 * This class does not compute anything and does not replace any of those
 * composers — see IntelligenceSummaryComposer, which reads their existing,
 * already-computed payloads and PROJECTS them into the shape defined here.
 * The composers' own return values are untouched; every screen that reads
 * them today keeps working exactly as it does now.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHY A CLOSED LIST OF TYPES
 *
 * A finding that says "hypothesis" is a guess this model is naming as a guess;
 * one that says "observed_fact" is a number read off a table. Collapsing that
 * distinction — the exact failure GroundedClaims exists to stop happening in
 * the AI-authored path — is just as possible in server-computed intelligence
 * if nothing enforces the vocabulary. `validate()` is that enforcement: a
 * finding whose `type` is not in this list, or whose required fields are
 * missing, fails validation rather than reaching a screen unlabelled.
 */
final class IntelligenceOutputContract
{
    /**
     * @var array<int, string>
     */
    public const FINDING_TYPES = [
        // What is recorded, unmediated.
        'observed_fact',
        // A number derived from recorded data by a stated, checkable formula.
        'calculated_metric',
        // A directional change across two or more measurements.
        'trend',
        // A value outside the pattern the rest of the data establishes.
        'anomaly',
        // A proposed explanation not yet established by what is recorded.
        'hypothesis',
        // Two measured things move together; no claim about why.
        'correlation',
        // A cause established by what is recorded — never inferred from
        // correlation alone. Rare by design.
        'causal_conclusion',
        // A named exposure that has not yet occurred.
        'risk',
        // A named upside not yet realised.
        'opportunity',
        // A proposed action, distinct from a decision to take it.
        'recommendation',
        // A projection of a future value, stated with its basis.
        'forecast',
        // A result actually measured after an action was taken.
        'measured_outcome',
    ];

    public static function isValidFindingType(string $type): bool
    {
        return in_array($type, self::FINDING_TYPES, true);
    }

    /**
     * Every error found in a contract envelope, or an empty array when it is
     * valid. Deliberately collects every error rather than stopping at the
     * first — a caller assembling the envelope from several composer methods
     * wants to see every field that came back malformed in one pass.
     *
     * @param  array<string, mixed>  $contract
     * @return array<int, string>
     */
    public static function validate(array $contract): array
    {
        $errors = [];

        if (! self::nonEmptyString($contract['title'] ?? null)) {
            $errors[] = 'title is required and must be a non-empty string';
        }

        if (! self::nonEmptyString($contract['executiveSummary'] ?? null)) {
            $errors[] = 'executiveSummary is required and must be a non-empty string';
        }

        if (! array_key_exists('context', $contract) || ! is_array($contract['context'])) {
            $errors[] = 'context is required and must be an array';
        }

        $findings = $contract['findings'] ?? null;
        if (! is_array($findings)) {
            $errors[] = 'findings is required and must be an array';
        } else {
            foreach ($findings as $i => $finding) {
                foreach (self::validateFinding($finding) as $error) {
                    $errors[] = "findings[{$i}]: {$error}";
                }
            }
        }

        $recommendations = $contract['recommendations'] ?? null;
        if (! is_array($recommendations)) {
            $errors[] = 'recommendations is required and must be an array (may be empty)';
        } else {
            foreach ($recommendations as $i => $rec) {
                foreach (self::validateRecommendation($rec) as $error) {
                    $errors[] = "recommendations[{$i}]: {$error}";
                }
            }
        }

        if (! array_key_exists('limitations', $contract) || ! is_array($contract['limitations'])) {
            $errors[] = 'limitations is required and must be an array (may be empty)';
        }

        if (! array_key_exists('dataCoverage', $contract) || ! is_array($contract['dataCoverage'])) {
            $errors[] = 'dataCoverage is required and must be an array';
        }

        return $errors;
    }

    /**
     * @param  mixed  $finding
     * @return array<int, string>
     */
    private static function validateFinding(mixed $finding): array
    {
        if (! is_array($finding)) {
            return ['must be an array'];
        }

        $errors = [];

        $type = $finding['type'] ?? null;
        if (! is_string($type) || ! self::isValidFindingType($type)) {
            $errors[] = 'type must be one of: '.implode(', ', self::FINDING_TYPES);
        }

        if (! self::nonEmptyString($finding['statement'] ?? null)) {
            $errors[] = 'statement is required and must be a non-empty string';
        }

        if (! array_key_exists('evidence', $finding) || ! is_array($finding['evidence'])) {
            $errors[] = 'evidence is required and must be an array (may be empty when genuinely ungrounded)';
        }

        $confidence = $finding['confidence'] ?? null;
        if ($confidence !== null && (! is_numeric($confidence) || $confidence < 0 || $confidence > 1)) {
            $errors[] = 'confidence must be null or a number between 0 and 1';
        }

        return $errors;
    }

    /**
     * @param  mixed  $recommendation
     * @return array<int, string>
     */
    private static function validateRecommendation(mixed $recommendation): array
    {
        if (! is_array($recommendation)) {
            return ['must be an array'];
        }

        $errors = [];

        if (! self::nonEmptyString($recommendation['title'] ?? null)) {
            $errors[] = 'title is required and must be a non-empty string';
        }

        if (! self::nonEmptyString($recommendation['rationale'] ?? null)) {
            $errors[] = 'rationale is required and must be a non-empty string';
        }

        return $errors;
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
