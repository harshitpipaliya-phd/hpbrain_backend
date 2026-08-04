<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Evaluates signal rules held as rows rather than as code.
 *
 * The five rules used to be private methods. Adding a sixth meant a deploy;
 * giving a hospital a different sixth meant a conditional. A rule is now a row,
 * and adding one is an INSERT.
 *
 * WHICH RULES RUN. A tenant gets every active rule whose industry_code is '*'
 * or matches its own industry, from either the shared 'platform' tenant or
 * itself. A rule_key defined by both resolves to the tenant's own — that is how
 * an installation overrides a shipped rule without editing it.
 *
 * IDEMPOTENCY IS PRESERVED EXACTLY, INCLUDING ITS FLAW. EventPublisher derives
 * the key as md5("{type}:{tenant}:{entityType}:{entityId}") where entityId is
 * the signal's own freshly-generated UUID. Since that UUID is new on every call,
 * the key is new on every call, and re-evaluating an unchanged condition creates
 * a SECOND signal. SignalGenerator's docblock claimed the opposite — "the
 * idempotency key is derived from the triggering entity, not from the signal
 * UUID" — and that claim was simply not true of the code beneath it.
 *
 * It is carried forward unchanged because Phase 3's gate is that signals are
 * byte-identical before and after the refactor, and deduplicating them here
 * would make that comparison meaningless. Keying on the triggering entity would
 * be the real fix and is a behaviour change that deserves its own phase and its
 * own gate. Recorded in docs/UNIVERSAL-INTELLIGENCE-PROGRESS.md.
 */
final class RuleEvaluator
{
    private const ACTOR = 'system';

    /** Rules shipped with the product live under this tenant id. */
    public const PLATFORM_TENANT = 'platform';

    public function __construct(
        private readonly EventPublisher $events,
        private readonly EntityResolver $resolver,
    ) {
    }

    /**
     * Evaluate every applicable rule and create signals for the ones that fire.
     *
     * @return array{created: int, skipped: int}
     */
    public function evaluate(string $tenantId): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($this->rulesFor($tenantId) as $rule) {
            try {
                $this->evaluateRule($tenantId, $rule) ? $created++ : $skipped++;
            } catch (\Throwable) {
                // One malformed rule must not stop the rest. The previous
                // implementation swallowed per-rule failures the same way.
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * The rules that apply to a tenant, tenant-specific overriding shared.
     *
     * @return array<int, object>
     */
    public function rulesFor(string $tenantId): array
    {
        $industry = $this->industryOf($tenantId);

        $rows = DB::table('hpbrain_signal_rules')
            ->where('is_active', 1)
            ->whereIn('tenant_id', [self::PLATFORM_TENANT, $tenantId])
            ->when(
                $industry !== null,
                fn ($q) => $q->whereIn('industry_code', ['*', $industry]),
                fn ($q) => $q->where('industry_code', '*'),
            )
            ->orderBy('rule_key')
            ->get();

        // Tenant-specific wins over platform for the same rule_key. Ordering by
        // rule_key first keeps evaluation order stable and independent of the
        // order rows happen to have been inserted in.
        $byKey = [];

        foreach ($rows as $row) {
            $key = (string) $row->rule_key;

            if (! isset($byKey[$key]) || $row->tenant_id === $tenantId) {
                $byKey[$key] = $row;
            }
        }

        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * A tenant's industry code, or null when it cannot be read.
     *
     * Null means "shared rules only" rather than "no rules": an organization row
     * that is missing or has no industry set is a configuration gap, and the
     * safe reading of a gap is the smaller rule set, not a guessed industry.
     */
    private function industryOf(string $tenantId): ?string
    {
        try {
            $org = $this->resolver->resolve($tenantId, 'Organization');

            if (! $org->has('industry')) {
                return null;
            }

            $value = DB::table($org->table)
                ->where($org->tenantKey, $tenantId)
                ->whereNull('deleted_at')
                ->value($org->field('industry'));

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return bool whether a signal was created */
    private function evaluateRule(string $tenantId, object $rule): bool
    {
        $source = $this->resolver->resolve($tenantId, (string) $rule->universal_entity);
        $predicate = $this->decode($rule->predicate, 'predicate');
        $evidenceFields = $this->decode($rule->evidence_fields, 'evidence_fields');

        $joinSource = $rule->join_entity !== null && $rule->join_entity !== ''
            ? $this->resolver->resolve($tenantId, (string) $rule->join_entity)
            : null;

        $affected = $this->matchingRows($tenantId, $rule, $source, $joinSource, $predicate, $evidenceFields);

        if ($affected->isEmpty() || ! $this->thresholdMet($rule, $affected->count())) {
            return false;
        }

        $evidenceIds = $this->writeEvidence($tenantId, $affected, $source, $joinSource, $evidenceFields, $rule);

        $primaryKey = $joinSource === null ? $source->primaryKey : 'u_'.$source->primaryKey;

        $this->createSignal($tenantId, [
            'source'         => 'erp.data_quality',
            'classification' => (string) $rule->classification,
            'priority'       => (string) $rule->priority,
            'severity'       => (string) $rule->severity,
            'confidence'     => (float) $rule->confidence,
            'metadata'       => [
                'rule'          => (string) $rule->rule_key,
                'affectedCount' => $affected->count(),
                'sampleIds'     => $affected->take(5)->pluck($primaryKey)->all(),
            ],
        ], $evidenceIds);

        return true;
    }

    /**
     * Rows matching the rule, capped at 50 as the hand-written rules were.
     *
     * @param  array<string, mixed>  $predicate
     * @param  array<int, string>  $evidenceFields
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function matchingRows(
        string $tenantId,
        object $rule,
        ResolvedSource $source,
        ?ResolvedSource $joinSource,
        array $predicate,
        array $evidenceFields,
    ): \Illuminate\Support\Collection {
        if ($joinSource === null) {
            // Soft-delete is NOT assumed. deletedAt is a mapped universal
            // field, and a rule states its own position on deleted rows — four
            // of the five shipped rules want them excluded and the fifth
            // requires them. Hardcoding whereNull here would have made that
            // fifth rule inexpressible.
            $query = DB::table($source->table)
                ->where($source->tenantKey, $tenantId);

            Predicate::apply($query, $predicate, $source);

            $columns = $source->columns(array_merge(['id'], $this->fieldsIn($evidenceFields)));

            return $query->select(array_values(array_unique($columns)))->limit(50)->get();
        }

        // Joined rules alias both sides and prefix the selected columns, because
        // two source tables routinely share column names — `id` and `status` in
        // particular — and an unprefixed select silently keeps only one of them.
        $query = DB::table($source->table.' as u')
            ->join($joinSource->table.' as d', function ($j) use ($tenantId, $source, $joinSource, $rule) {
                $j->on('d.'.$joinSource->primaryKey, '=', 'u.'.$source->field('unit'))
                    ->where('d.'.$joinSource->tenantKey, '=', $tenantId);

                if ($joinSource->has('deletedAt')) {
                    $j->whereNull('d.'.$joinSource->field('deletedAt'));
                }

                $joinPredicate = $this->decodeNullable($rule->join_predicate);

                if ($joinPredicate !== null) {
                    foreach ($this->flatten($joinPredicate) as $clause) {
                        $j->where('d.'.$joinSource->field($clause['field']), '=', $clause['value']);
                    }
                }
            })
            ->where('u.'.$source->tenantKey, $tenantId);

        Predicate::apply($query, $predicate, $source, 'u');

        $select = [];

        foreach ($source->columns(array_merge(['id'], $this->fieldsIn($evidenceFields))) as $column) {
            $select[] = 'u.'.$column.' as u_'.$column;
        }

        if ($joinSource->has('name')) {
            $select[] = 'd.'.$joinSource->field('name').' as d_'.$joinSource->field('name');
        }

        return $query->select($select)->limit(50)->get();
    }

    /**
     * Join predicates are restricted to equality on the joined entity's own
     * fields — enough for "the department is active", which is all the shipped
     * rules need, and small enough that it cannot express a correlated
     * subquery by accident.
     *
     * @param  array<string, mixed>  $predicate
     * @return array<int, array{field: string, value: mixed}>
     */
    private function flatten(array $predicate): array
    {
        $clauses = $predicate['all'] ?? [$predicate];
        $out = [];

        foreach ($clauses as $clause) {
            if (($clause['op'] ?? 'eq') !== 'eq' || ! isset($clause['field'])) {
                throw new \InvalidArgumentException(
                    'join_predicate supports only "eq" clauses on the joined entity.'
                );
            }

            $out[] = ['field' => (string) $clause['field'], 'value' => $clause['value'] ?? null];
        }

        return $out;
    }

    private function thresholdMet(object $rule, int $count): bool
    {
        if ($rule->threshold_op === null || $rule->threshold_value === null) {
            // No threshold means "any match fires", which is what all five
            // shipped rules do.
            return true;
        }

        $value = (float) $rule->threshold_value;

        return match ((string) $rule->threshold_op) {
            'gt'  => $count > $value,
            'gte' => $count >= $value,
            'lt'  => $count < $value,
            'lte' => $count <= $value,
            'eq'  => (float) $count === $value,
            default => throw new \InvalidArgumentException(
                "Unsupported threshold_op '{$rule->threshold_op}'."
            ),
        };
    }

    /**
     * Evidence rows for up to five affected records.
     *
     * evidence_fields maps OUTPUT KEY => source, so a rule controls both what
     * appears in the evidence and what it is called:
     *
     *   {"employeeNo": "externalRef"}                      one universal field
     *   {"name": {"concat": ["firstName", "lastName"]}}    a composite
     *   {"department": {"join": "name"}}                   from the joined entity
     *
     * The indirection is what lets the shipped rules reproduce the previous
     * payloads exactly — `employeeNo` rather than `externalRef`, and a `name`
     * built from two columns — without the evaluator knowing anything about
     * either. An output key whose source is unmapped is omitted rather than
     * emitted as null: the ERP has no such column, and a key that is always null
     * is noise in every downstream reader.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $affected
     * @param  array<string, mixed>  $evidenceFields
     * @return array<int, string>
     */
    private function writeEvidence(
        string $tenantId,
        \Illuminate\Support\Collection $affected,
        ResolvedSource $source,
        ?ResolvedSource $joinSource,
        array $evidenceFields,
        object $rule,
    ): array {
        $prefix = $joinSource === null ? '' : 'u_';
        $ids = [];

        foreach ($affected->take(5) as $row) {
            $content = [
                'source'   => 'erp.'.$source->table,
                'recordId' => (string) $row->{$prefix.$source->primaryKey},
            ];

            foreach ($evidenceFields as $outputKey => $spec) {
                $value = $this->evidenceValue($row, $spec, $source, $joinSource, $prefix);

                if ($value !== self::UNMAPPED) {
                    $content[$outputKey] = $value;
                }
            }

            $content['issue'] = (string) $rule->recommended_action;

            $ids[] = $this->createEvidence($tenantId, $content);
        }

        return $ids;
    }

    /** Distinguishes "the source has no such column" from a genuine null. */
    private const UNMAPPED = "\0unmapped\0";

    /**
     * The universal fields an evidence_fields map reads, so the query selects
     * them and nothing else.
     *
     * @param  array<string, mixed>  $evidenceFields
     * @return array<int, string>
     */
    private function fieldsIn(array $evidenceFields): array
    {
        $fields = [];

        foreach ($evidenceFields as $spec) {
            if (is_string($spec)) {
                $fields[] = $spec;
            } elseif (is_array($spec) && isset($spec['concat'])) {
                foreach ($spec['concat'] as $field) {
                    $fields[] = $field;
                }
            }
            // {"join": ...} reads the joined entity, selected separately.
        }

        return array_values(array_unique($fields));
    }

    private function evidenceValue(
        object $row,
        mixed $spec,
        ResolvedSource $source,
        ?ResolvedSource $joinSource,
        string $prefix,
    ): mixed {
        if (is_string($spec)) {
            return $source->has($spec)
                ? (string) ($row->{$prefix.$source->field($spec)} ?? '')
                : self::UNMAPPED;
        }

        if (! is_array($spec)) {
            throw new \InvalidArgumentException('evidence_fields values must be a field name or a spec object.');
        }

        if (isset($spec['concat'])) {
            $parts = [];

            foreach ($spec['concat'] as $field) {
                if (! $source->has($field)) {
                    continue;
                }

                $parts[] = $row->{$prefix.$source->field($field)} ?? '';
            }

            if ($parts === []) {
                return self::UNMAPPED;
            }

            return trim(implode($spec['separator'] ?? ' ', $parts));
        }

        if (isset($spec['join'])) {
            if ($joinSource === null || ! $joinSource->has($spec['join'])) {
                return self::UNMAPPED;
            }

            return (string) ($row->{'d_'.$joinSource->field($spec['join'])} ?? '');
        }

        throw new \InvalidArgumentException(
            'Unsupported evidence_fields spec. Use a field name, {"concat": [...]}, or {"join": "field"}.'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $evidenceIds
     */
    private function createSignal(string $tenantId, array $data, array $evidenceIds): string
    {
        $signalId = Uuid::uuid4()->toString();

        $this->events->publishInTransaction(
            LoopEvent::OBSERVATION_MADE,
            $tenantId,
            'Signal',
            self::ACTOR,
            [
                'signalId'       => $signalId,
                'source'         => $data['source'],
                'classification' => $data['classification'],
                'priority'       => $data['priority'],
                'severity'       => $data['severity'],
                'evidenceIds'    => $evidenceIds,
            ],
            function () use ($signalId, $tenantId, $data) {
                DB::table('hpbrain_signals')->insert([
                    'id'             => $signalId,
                    'tenant_id'      => $tenantId,
                    'source'         => $data['source'],
                    'classification' => $data['classification'],
                    'priority'       => $data['priority'],
                    'severity'       => $data['severity'],
                    'confidence'     => $data['confidence'],
                    'metadata'       => json_encode($data['metadata']),
                    'status'         => 'new',
                    'created_by'     => self::ACTOR,
                    'created_date'   => now()->format('Y-m-d H:i:s'),
                ]);

                return ['entityId' => $signalId, 'result' => true];
            },
            correlationId: $signalId,
        );

        return $signalId;
    }

    /** @param array<string, mixed> $content */
    private function createEvidence(string $tenantId, array $content): string
    {
        $evidenceId = Uuid::uuid4()->toString();
        $contentJson = json_encode($content);
        $provenanceJson = json_encode([
            'source'     => $content['source'],
            'ts'         => now()->format('Y-m-d\TH:i:s\Z'),
            'confidence' => 1.0,
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id'            => $evidenceId,
            'tenant_id'     => $tenantId,
            // Preserved from SignalGenerator, including this: signal_id is set
            // to the TENANT id, not to a signal. It is wrong, it predates this
            // refactor, and correcting it here would change stored rows inside a
            // commit whose gate is that stored rows do not change.
            'signal_id'     => $tenantId,
            'source'        => $content['source'],
            'evidence_type' => 'observation',
            'content'       => $contentJson,
            'provenance'    => $provenanceJson,
            'confidence'    => 1.0,
            'hash'          => hash('sha256', $contentJson.'|'.$provenanceJson),
            'status'        => 'active',
            'created_by'    => self::ACTOR,
            'created_date'  => now()->format('Y-m-d H:i:s'),
        ]);

        return $evidenceId;
    }

    /** @return array<string, mixed> */
    private function decode(mixed $raw, string $what): array
    {
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException("Rule {$what} is not valid JSON.");
        }

        return $decoded;
    }

    /** @return array<string, mixed>|null */
    private function decodeNullable(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->decode($raw, 'join_predicate');
    }
}
