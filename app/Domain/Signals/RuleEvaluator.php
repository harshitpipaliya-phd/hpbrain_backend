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
 * RE-DETECTION REFRESHES, IT DOES NOT DUPLICATE. EventPublisher derives its
 * idempotency key from the signal's own freshly-generated UUID, so the key is
 * new on every call and cannot deduplicate anything. SignalGenerator's docblock
 * claimed the key came "from the triggering entity, not from the signal UUID",
 * and that was simply not true of the code beneath it.
 *
 * That flaw was carried through the Phase 3 refactor on the grounds that its
 * gate was byte-identical signals. It stopped being survivable when detection
 * became scheduled: at hourly cadence one unfixed "43 people have no
 * department" would produce twenty-four identical signals a day, and an
 * attention queue ordered by severity and count would fill with a single
 * finding repeated until nothing else was visible.
 *
 * So an unresolved signal from the same rule is now UPDATED with the current
 * affected count rather than joined by a twin — see openSignalFor(). Resolved
 * and dismissed signals are not matched, because a problem that was closed and
 * came back is a real new observation.
 */
final class RuleEvaluator
{
    /** Outcomes of evaluating one rule. */
    private const CREATED = 'created';

    private const REFRESHED = 'refreshed';

    private const NOTHING = 'nothing';

    private const ACTOR = 'system';

    /** Rules shipped with the product live under this tenant id. */
    public const PLATFORM_TENANT = 'platform';

    public function __construct(
        private readonly EventPublisher $events,
        private readonly EntityResolver $resolver,
        private readonly SignalSubject $subject,
    ) {
    }

    /**
     * Evaluate every applicable rule and create signals for the ones that fire.
     *
     * @return array{created: int, refreshed: int, skipped: int}
     */
    public function evaluate(string $tenantId): array
    {
        $created = 0;
        $refreshed = 0;
        $skipped = 0;

        foreach ($this->rulesFor($tenantId) as $rule) {
            try {
                match ($this->evaluateRule($tenantId, $rule)) {
                    self::CREATED   => $created++,
                    self::REFRESHED => $refreshed++,
                    default         => $skipped++,
                };
            } catch (\Throwable) {
                // One malformed rule must not stop the rest. The previous
                // implementation swallowed per-rule failures the same way.
                $skipped++;
            }
        }

        return ['created' => $created, 'refreshed' => $refreshed, 'skipped' => $skipped];
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

    /**
     * @return self::CREATED|self::REFRESHED|self::NOTHING
     */
    private function evaluateRule(string $tenantId, object $rule): string
    {
        $source = $this->resolver->resolve($tenantId, (string) $rule->universal_entity);
        $predicate = $this->decode($rule->predicate, 'predicate');
        $evidenceFields = $this->decode($rule->evidence_fields, 'evidence_fields');

        $joinSource = $rule->join_entity !== null && $rule->join_entity !== ''
            ? $this->resolver->resolve($tenantId, (string) $rule->join_entity)
            : null;

        $affected = $this->matchingRows($tenantId, $rule, $source, $joinSource, $predicate, $evidenceFields);

        if ($affected->isEmpty() || ! $this->thresholdMet($rule, $affected->count())) {
            return self::NOTHING;
        }

        $primaryKey = $joinSource === null ? $source->primaryKey : 'u_'.$source->primaryKey;

        $metadata = [
            'rule'          => (string) $rule->rule_key,
            'affectedCount' => $affected->count(),
            'sampleIds'     => $affected->take(5)->pluck($primaryKey)->all(),
        ];

        // Who this is about, from the rows that just matched. The FULL affected
        // set is passed, not the five-row sample: SignalSubject names a single
        // entity only when exactly one row is affected, and a sample of five
        // taken from fifty would make that test answer the wrong question.
        $subject = $this->subject->columnsFor(
            $tenantId,
            (string) $rule->universal_entity,
            $affected->pluck($primaryKey)->all(),
        );

        // A condition that is still true is not a new observation.
        $open = $this->openSignalFor($tenantId, (string) $rule->rule_key);

        if ($open !== null) {
            $this->refreshSignal($tenantId, $open, $metadata, $subject);

            return self::REFRESHED;
        }

        $evidenceRows = $this->buildEvidence($affected, $source, $joinSource, $evidenceFields, $rule);

        $this->createSignal($tenantId, [
            'source'         => 'erp.data_quality',
            'classification' => (string) $rule->classification,
            'priority'       => (string) $rule->priority,
            'severity'       => (string) $rule->severity,
            'confidence'     => (float) $rule->confidence,
            'ruleKey'        => (string) $rule->rule_key,
            'metadata'       => $metadata,
            'subject'        => $subject,
        ], $evidenceRows);

        return self::CREATED;
    }

    /**
     * The unresolved signal this rule already raised, if there is one.
     *
     * WHY THIS EXISTS. EventPublisher derives its idempotency key from the
     * signal's own freshly-generated UUID, so the key is new on every call and
     * re-evaluating an unchanged condition produced a SECOND identical signal.
     * That was tolerable while rules only ran when somebody pressed a button.
     * It stopped being tolerable when detection became scheduled: at hourly
     * cadence an unfixed "43 people have no department" would have produced
     * twenty-four identical signals a day, and the attention queue — which
     * orders by severity and count — would have filled with the same finding
     * repeated until nothing else was visible.
     *
     * Matching is on the rule that raised it. Two signals from the same rule for
     * the same tenant are the same ongoing problem, whatever the affected set
     * happens to be this hour.
     *
     * Resolved and dismissed signals are deliberately NOT matched: a problem
     * that was closed and has come back is a genuinely new observation, and
     * recurrence after resolution is one of the more valuable things this system
     * can tell an organization.
     */
    private function openSignalFor(string $tenantId, string $ruleKey): ?object
    {
        return DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            // A real column, not a JSON path. Reading the key out of metadata
            // needs JSON_UNQUOTE, which MySQL and MariaDB have and SQLite does
            // not — so on the test database the query threw, the per-rule catch
            // swallowed it, and every rule silently reported as skipped.
            ->where('rule_key', $ruleKey)
            ->orderByDesc('created_date')
            ->first();
    }

    /**
     * Bring an open signal up to date without raising a new one.
     *
     * The affected COUNT is real news even when the signal is not — "43 people
     * have no department" becoming 51 is a deterioration somebody should see,
     * and overwriting it keeps the queue honest without adding a row. The
     * original created_date is left alone, because how long a problem has been
     * open is the single most useful fact about it.
     *
     * The subject is re-derived on every refresh rather than written once at
     * creation. It is a fact about the CURRENT affected set, and that set moves:
     * a rule down to its last unmanaged department has acquired a single
     * subject, and one that has gained a second has lost it. Rewriting both
     * columns each pass — including back to null — is what keeps the answer
     * true rather than merely once-true. It is also what backfills the signals
     * raised before this existed.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array{org_id: string|null, related_entity_type: string|null, related_entity_id: string|null}  $subject
     */
    private function refreshSignal(string $tenantId, object $signal, array $metadata, array $subject): void
    {
        $previous = json_decode((string) $signal->metadata, true);
        $previous = is_array($previous) ? $previous : [];

        DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->where('id', $signal->id)
            ->update($subject + [
                'metadata'     => json_encode(array_merge($previous, $metadata, [
                    // What the count was when the signal was first raised, so a
                    // screen can say whether it is getting better or worse.
                    'firstCount'  => $previous['firstCount'] ?? ($previous['affectedCount'] ?? null),
                    'lastSeenAt'  => now()->format('Y-m-d H:i:s'),
                ])),
                'updated_date' => now()->format('Y-m-d H:i:s'),
            ]);
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
     * Evidence CONTENT for up to five affected records — built, not written.
     *
     * Insertion is deferred to createSignal so the signal row exists first.
     * hpbrain_evidence.signal_id carries a FOREIGN KEY to hpbrain_signals.id,
     * so evidence written before its signal is rejected outright by MySQL and
     * MariaDB. Ids are generated here because the signal's event payload has
     * to name them before either row is written.
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
    private function buildEvidence(
        \Illuminate\Support\Collection $affected,
        ResolvedSource $source,
        ?ResolvedSource $joinSource,
        array $evidenceFields,
        object $rule,
    ): array {
        $prefix = $joinSource === null ? '' : 'u_';
        $rows = [];

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

            $rows[] = ['id' => Uuid::uuid4()->toString(), 'content' => $content];
        }

        return $rows;
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
     * Write the signal and its evidence in one transaction, signal first.
     *
     * ORDER IS LOAD-BEARING. hpbrain_evidence.signal_id is a FOREIGN KEY to
     * hpbrain_signals.id, so evidence written first is rejected with SQLSTATE
     * 23000 on MySQL and MariaDB. The previous implementation sidestepped that
     * by storing the TENANT id in signal_id — which satisfied nothing, pointed
     * evidence at a signal that did not exist, and failed the moment foreign
     * keys were actually enforced.
     *
     * The test suite could not catch it: SQLite does not enforce foreign keys
     * unless PRAGMA foreign_keys is turned on, and it is not.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{id: string, content: array<string, mixed>}>  $evidenceRows
     */
    private function createSignal(string $tenantId, array $data, array $evidenceRows): string
    {
        $signalId = Uuid::uuid4()->toString();
        $evidenceIds = array_column($evidenceRows, 'id');

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
            function () use ($signalId, $tenantId, $data, $evidenceRows) {
                DB::table('hpbrain_signals')->insert(($data['subject'] ?? []) + [
                    'id'             => $signalId,
                    'tenant_id'      => $tenantId,
                    'source'         => $data['source'],
                    'classification' => $data['classification'],
                    // Which rule raised this, in a column the detector can index
                    // and query on any engine. Null for signals that do not come
                    // from a rule at all.
                    'rule_key'       => $data['ruleKey'] ?? null,
                    'priority'       => $data['priority'],
                    'severity'       => $data['severity'],
                    'confidence'     => $data['confidence'],
                    'metadata'       => json_encode($data['metadata']),
                    'status'         => 'new',
                    'created_by'     => self::ACTOR,
                    'created_date'   => now()->format('Y-m-d H:i:s'),
                ]);

                // Now that the signal exists, its evidence can point at it.
                foreach ($evidenceRows as $row) {
                    $this->createEvidence($tenantId, $signalId, $row['id'], $row['content']);
                }

                return ['entityId' => $signalId, 'result' => true];
            },
            correlationId: $signalId,
        );

        return $signalId;
    }

    /** @param array<string, mixed> $content */
    private function createEvidence(
        string $tenantId,
        string $signalId,
        string $evidenceId,
        array $content,
    ): string {
        $contentJson = json_encode($content);
        $provenanceJson = json_encode([
            'source'     => $content['source'],
            'ts'         => now()->format('Y-m-d\TH:i:s\Z'),
            'confidence' => 1.0,
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id'            => $evidenceId,
            'tenant_id'     => $tenantId,
            // The signal this evidence is FOR. It used to be the tenant id,
            // which the foreign key on this column rejects outright.
            'signal_id'     => $signalId,
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
