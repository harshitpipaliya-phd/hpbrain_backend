<?php

declare(strict_types=1);

namespace App\Domain\Eso;

/**
 * Can this ESO be run, by this executor, with these inputs — and if not, why.
 *
 * ONE IMPLEMENTATION, TWO CALLERS. The library screen asks this to decide
 * whether to offer a Run button and what to say when it cannot; the execution
 * endpoint asks the same object to decide whether to accept the request. A
 * readiness check that lives only in the UI is decoration — the server would
 * still accept whatever the UI failed to prevent — and one that lives only in
 * the server hands the reader a 422 in place of an explanation. Both callers
 * share this file so the answer cannot differ between them.
 *
 * WHAT IS ENFORCED AND WHAT IS ONLY ASKED. The distinction is the whole design:
 *
 *   - Lifecycle status and executor class are DECLARED IN COLUMNS. They are
 *     machine-checkable, so they are enforced and a violation is a hard refusal.
 *
 *   - Inputs are checkable ONLY where the declaration names them. The seeded
 *     shape is [{"name": "departmentId", "type": "string"}], but the column is
 *     free JSON and other rows hold prose. A named input is required and
 *     enforced; prose is reported as declared-but-unverifiable and never
 *     silently treated as satisfied.
 *
 *   - Preconditions are prose about the world outside the Brain ("the roster is
 *     current", "the unit lead has been briefed"). Nothing in this database can
 *     confirm them. Inventing a check would be worse than having none, so they
 *     are put to the person starting the run as an explicit acknowledgement and
 *     the acknowledgement is stored on the execution with their identity. That
 *     is a real control — an attestation with a name on it — rather than a
 *     simulated one.
 *
 * TRUST LEVEL IS NOT AN EXECUTION GATE HERE, deliberately. trust_level
 * (observe | assist | approve) governs how much autonomy a NON-HUMAN executor
 * has. ADR-004 keeps autonomous execution dark in v1 and the endpoint accepts
 * executorType 'human' only, so refusing a human run because the AI autonomy
 * level is 'observe' would be a restriction the model does not actually
 * express. It is surfaced as context, and becomes a gate on the day a non-human
 * executor class is permitted.
 */
final class EsoPreflight
{
    /**
     * Everything a reader needs to answer "can I run this, and what does it need?".
     *
     * @param  object  $definition  a row from hpbrain_eso_definitions
     * @return array<string, mixed>
     */
    public static function assess(object $definition, string $executorClass = 'human'): array
    {
        $status = self::string($definition, 'status');
        $blockers = [];

        if (! EsoStatus::inService($status)) {
            $blockers[] = [
                'code' => 'eso_not_in_service',
                'message' => EsoStatus::explain($status),
            ];
        }

        $supersededBy = self::string($definition, 'superseded_by');

        if ($supersededBy !== null && $supersededBy !== '') {
            $blockers[] = [
                'code' => 'eso_superseded',
                'message' => 'This ESO has been superseded by a newer definition. Run the replacement instead.',
            ];
        }

        $executorClasses = self::stringList(self::raw($definition, 'allowed_executor_classes'));

        // An empty list is "no restriction declared", not "nothing may run it".
        // The column defaults to [] and most authored rows never set it; reading
        // that default as a prohibition would make almost every ESO unrunnable
        // for a reason its author never wrote down.
        if ($executorClasses !== [] && ! in_array(strtolower($executorClass), array_map('strtolower', $executorClasses), true)) {
            $blockers[] = [
                'code' => 'executor_class_not_permitted',
                'message' => 'This ESO may be executed by '.implode(' or ', $executorClasses).', not by '.$executorClass.'.',
            ];
        }

        $inputs = self::inputSpecs(self::raw($definition, 'inputs'));
        $preconditions = self::proseList(self::raw($definition, 'preconditions'));

        return [
            'runnable' => $blockers === [],
            'blockers' => $blockers,
            'executorClasses' => $executorClasses,
            'executorClassRestricted' => $executorClasses !== [],
            'trustLevel' => self::string($definition, 'trust_level'),
            'trustLevelNote' => 'Trust level governs how much autonomy a non-human executor would have. Execution is restricted to a named person in this version, so it does not gate this run.',
            'requiredInputs' => array_values(array_filter($inputs['named'], static fn (array $i): bool => $i['required'])),
            'optionalInputs' => array_values(array_filter($inputs['named'], static fn (array $i): bool => ! $i['required'])),
            'unverifiableInputs' => $inputs['prose'],
            'preconditions' => $preconditions,
            'preconditionsRequireAcknowledgement' => $preconditions !== [],
            'preconditionNote' => $preconditions === []
                ? 'This ESO declares no preconditions.'
                : 'These preconditions describe the world outside this system and cannot be verified from its records. Whoever starts the run confirms them, and the confirmation is stored against the execution with their name.',
        ];
    }

    /**
     * The blockers that apply to one concrete run attempt.
     *
     * Returns the definition-level blockers plus anything wrong with the
     * submitted inputs and acknowledgement. An empty array means the run may
     * start.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<int, array{code: string, message: string}>
     */
    public static function blockersForRun(
        object $definition,
        string $executorClass,
        array $inputs,
        bool $preconditionsAcknowledged,
    ): array {
        $readiness = self::assess($definition, $executorClass);
        $blockers = $readiness['blockers'];

        $missing = [];

        foreach ($readiness['requiredInputs'] as $spec) {
            $value = $inputs[$spec['name']] ?? null;

            if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
                $missing[] = $spec['name'];
            }
        }

        if ($missing !== []) {
            $blockers[] = [
                'code' => 'required_inputs_missing',
                'message' => 'This ESO declares required input'.(count($missing) === 1 ? '' : 's').' that were not supplied: '.implode(', ', $missing).'.',
            ];
        }

        if ($readiness['preconditionsRequireAcknowledgement'] && ! $preconditionsAcknowledged) {
            $blockers[] = [
                'code' => 'preconditions_not_acknowledged',
                'message' => 'This ESO declares '.count($readiness['preconditions']).' precondition'.(count($readiness['preconditions']) === 1 ? '' : 's').' that cannot be checked from records. They must be confirmed by whoever starts the run.',
            ];
        }

        return $blockers;
    }

    /**
     * Declared inputs, split into what can be enforced and what cannot.
     *
     * @return array{named: array<int, array{name: string, type: string|null, required: bool, description: string|null}>, prose: array<int, string>}
     */
    private static function inputSpecs(mixed $value): array
    {
        $named = [];
        $prose = [];

        foreach (self::decodeList($value) as $entry) {
            if (is_array($entry) && isset($entry['name']) && is_scalar($entry['name']) && (string) $entry['name'] !== '') {
                $named[] = [
                    'name' => (string) $entry['name'],
                    'type' => isset($entry['type']) && is_scalar($entry['type']) ? (string) $entry['type'] : null,
                    // Required unless the declaration says otherwise. An input an
                    // author bothered to name is needed by default; silence is
                    // not permission to omit it.
                    'required' => ! (($entry['required'] ?? null) === false || ($entry['optional'] ?? null) === true),
                    'description' => isset($entry['description']) && is_scalar($entry['description']) ? (string) $entry['description'] : null,
                ];

                continue;
            }

            $text = self::text($entry);

            if ($text !== '') {
                $prose[] = $text;
            }
        }

        return ['named' => $named, 'prose' => $prose];
    }

    /** @return array<int, string> */
    private static function proseList(mixed $value): array
    {
        $out = [];

        foreach (self::decodeList($value) as $entry) {
            $text = self::text($entry);

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /** @return array<int, mixed> */
    private static function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if ($value === null) {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return array<int, string> */
    private static function stringList(mixed $value): array
    {
        $out = [];

        foreach (self::decodeList($value) as $entry) {
            if (is_scalar($entry) && (string) $entry !== '') {
                $out[] = (string) $entry;
            }
        }

        return $out;
    }

    /** A list entry rendered as one readable line, whatever shape it was written in. */
    private static function text(mixed $entry): string
    {
        if (is_scalar($entry)) {
            return trim((string) $entry);
        }

        if (! is_array($entry)) {
            return '';
        }

        foreach (['description', 'text', 'statement', 'condition', 'method', 'label', 'name'] as $key) {
            if (isset($entry[$key]) && is_scalar($entry[$key]) && trim((string) $entry[$key]) !== '') {
                return trim((string) $entry[$key]);
            }
        }

        return trim((string) json_encode($entry));
    }

    private static function raw(object $row, string $field): mixed
    {
        return property_exists($row, $field) ? $row->{$field} : null;
    }

    private static function string(object $row, string $field): ?string
    {
        $value = self::raw($row, $field);

        return $value === null ? null : (string) $value;
    }
}
