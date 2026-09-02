<?php

declare(strict_types=1);

namespace App\Domain\Knowledge;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizational Memory: what this organization has been through, what it did
 * about it, and what it took away.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE CHAIN THIS SCREEN EXISTS TO MAKE LEGIBLE
 *
 *   evidence → decision → execution → outcome → learning → reuse
 *
 * Each link is a real foreign key in this schema:
 *
 *   hpbrain_learnings.outcome_id      → hpbrain_outcomes.id
 *   hpbrain_outcomes.decision_id      → hpbrain_decisions.id
 *   hpbrain_outcomes.evidence_ids[]   → hpbrain_evidence.id
 *   hpbrain_eso_executions.decision_id→ hpbrain_decisions.id
 *
 * A link that is absent in the data is reported as absent. The screen shows
 * the break in the chain rather than drawing an arrow to nothing, because a
 * chain that always looks complete tells the reader nothing about the one time
 * it wasn't.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY SO MUCH OF THIS CLASS IS ABOUT REFUSING TO ANSWER
 *
 * Every outcome in this installation is recorded as result="improved". Seven
 * of the eight carry metrics of {baseline:0, observed:0, changePercent:0} and
 * none carries a single evidence id. Rendering those literally would put
 * "Improved" and a confidence percentage in front of a manager for a change
 * whose size was never measured. `KnowledgeGrading::outcomeMagnitude` grades
 * that as UNDETERMINED, and this service carries the grade through to the
 * counters — so "successful interventions" counts outcomes that were actually
 * measured, not outcomes that were merely labelled.
 */
final class OrganizationalMemoryService
{
    private const LEARNINGS = 'hpbrain_learnings';

    private const OUTCOMES = 'hpbrain_outcomes';

    private const DECISIONS = 'hpbrain_decisions';

    private const EVIDENCE = 'hpbrain_evidence';

    private const EXECUTIONS = 'hpbrain_eso_executions';

    /**
     * The memory feed, newest first, one page at a time.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items:array<int,array<string,mixed>>, page:int, pageSize:int, total:int, pages:int}
     */
    public function list(string $tenantId, array $filters = []): array
    {
        $pageSize = $this->pageSize($filters['pageSize'] ?? null);
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->filtered($tenantId, $filters);
        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderByDesc('created_date')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $context = $this->contextFor($tenantId, $rows->pluck('outcome_id')->filter()->unique()->all());

        return [
            'items' => $rows->map(fn ($row) => $this->card($row, $context))->values()->all(),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 1,
        ];
    }

    /**
     * The counters above the feed.
     *
     * "Successful" and "failed" are read from the OUTCOME, not from the
     * learning — a learning is written whether the intervention worked or not,
     * and counting learnings as successes would make every failure look like
     * one. An outcome whose magnitude is UNDETERMINED counts as neither.
     *
     * @return array<string, mixed>
     */
    public function summary(string $tenantId): array
    {
        $learnings = DB::table(self::LEARNINGS)->where('tenant_id', $tenantId)->get([
            'id', 'outcome_id', 'reusable', 'created_date', 'domain', 'pattern', 'created_by',
        ]);

        $context = $this->contextFor($tenantId, $learnings->pluck('outcome_id')->filter()->unique()->all());

        $succeeded = 0;
        $failed = 0;
        $unmeasured = 0;
        $seeded = 0;
        $seededActors = (array) config('knowledge.provenance.seeded_actors', []);

        foreach ($learnings as $l) {
            $outcome = $context['outcomes'][(string) $l->outcome_id] ?? null;

            if (in_array((string) ($l->created_by ?? ''), $seededActors, true)) {
                $seeded++;
            }

            if ($outcome === null) {
                $unmeasured++;

                continue;
            }

            $magnitude = $outcome['magnitude']['state'] ?? 'UNDETERMINED';

            if ($magnitude === 'UNDETERMINED') {
                $unmeasured++;

                continue;
            }

            $this->isFailure((string) ($outcome['result'] ?? '')) ? $failed++ : $succeeded++;
        }

        $recentCut = now()->subDays(30)->format('Y-m-d H:i:s');

        /*
            REUSE IS COUNTED FROM THE PATTERN, WHICH IS THE ONLY REAL SIGNAL.

            A learning does not carry a reuse counter. What the data does show
            is the same named pattern being written from several different
            outcomes — that IS the organization applying a learning again, and
            it is countable. Anything beyond that would be invented.
        */
        $patternCounts = DB::table(self::LEARNINGS)
            ->where('tenant_id', $tenantId)
            ->select('pattern', DB::raw('count(*) as c'))
            ->groupBy('pattern')
            ->get();

        $reusedPatterns = $patternCounts->filter(fn ($r) => (int) $r->c > 1);

        return [
            'total' => $learnings->count(),
            'successfulInterventions' => $succeeded,
            'failedInterventions' => $failed,
            'unmeasuredInterventions' => $unmeasured,
            'lessonsLearned' => $learnings->count(),
            'reusableLessons' => $learnings->filter(fn ($l) => (bool) $l->reusable)->count(),
            'reusedLearnings' => (int) $reusedPatterns->sum('c'),
            'distinctPatterns' => $patternCounts->count(),
            'recentLearning' => $learnings->filter(fn ($l) => (string) $l->created_date >= $recentCut)->count(),
            'seeded' => $seeded,
            'observed' => max(0, $learnings->count() - $seeded),
            'domains' => DB::table(self::LEARNINGS)
                ->where('tenant_id', $tenantId)
                ->select('domain', DB::raw('count(*) as c'))
                ->groupBy('domain')
                ->get()
                ->map(fn ($r) => ['value' => (string) ($r->domain ?? ''), 'count' => (int) $r->c])
                ->filter(fn ($r) => $r['value'] !== '')
                ->values()
                ->all(),
            'patterns' => $patternCounts
                ->sortByDesc('c')
                ->map(fn ($r) => ['value' => (string) $r->pattern, 'count' => (int) $r->c])
                ->values()
                ->all(),
        ];
    }

    /**
     * One memory, as the full chain from what happened to how it is reused.
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $tenantId, string $id): ?array
    {
        $row = DB::table(self::LEARNINGS)
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if (! $row) {
            return null;
        }

        $context = $this->contextFor($tenantId, array_filter([$row->outcome_id ?? null]));
        $card = $this->card($row, $context);

        $outcome = $context['outcomes'][(string) $row->outcome_id] ?? null;
        $decisionId = $outcome['decisionId'] ?? null;

        return $card + [
            'evidence' => $this->evidenceFor($tenantId, $outcome),
            'executions' => $decisionId === null ? [] : $this->executionsFor($tenantId, (string) $decisionId),
            /*
                SIMILAR MEMORIES ARE THE SAME NAMED PATTERN, NOT A GUESS.

                `pattern` is a slug the loop writes deliberately, so two rows
                sharing one are the same learning arrived at twice. That is a
                real relation. Matching on description similarity would not be.
            */
            'similarMemories' => $this->similar($tenantId, $row),
            'influenced' => $this->influenced($tenantId, $row),
        ];
    }

    /* ===================================================================== */
    /*  INTERNALS */
    /* ===================================================================== */

    /**
     * One card in the feed: the chain, collapsed to what fits, with every
     * missing link named.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function card(object $row, array $context): array
    {
        $outcomeId = (string) ($row->outcome_id ?? '');
        $outcome = $context['outcomes'][$outcomeId] ?? null;
        $decision = $outcome !== null && $outcome['decisionId'] !== null
            ? ($context['decisions'][$outcome['decisionId']] ?? null)
            : null;

        $evidenceCount = $outcome['evidenceCount'] ?? 0;

        return [
            'id' => (string) $row->id,
            'pattern' => (string) ($row->pattern ?? ''),
            'title' => $this->title((string) ($row->pattern ?? ''), $row->domain ?? null),
            'lesson' => (string) ($row->description ?? ''),
            'domain' => ($row->domain ?? null) !== null ? (string) $row->domain : null,
            'reusable' => (bool) ($row->reusable ?? false),
            'createdDate' => $row->created_date ?? null,
            'confidence' => KnowledgeGrading::confidence($row->confidence ?? null, $evidenceCount),
            'provenance' => KnowledgeGrading::provenance($row->created_by ?? null),
            'patternReuseCount' => $context['patternCounts'][(string) ($row->pattern ?? '')] ?? 1,

            // ---- the chain -------------------------------------------------
            'outcome' => $outcome === null ? [
                'present' => false,
                'reason' => 'This learning names an outcome that is not on file, so what actually happened cannot be shown.',
            ] : [
                'present' => true,
                'id' => $outcome['id'],
                'result' => $outcome['result'],
                'feedback' => $outcome['feedback'],
                'magnitude' => $outcome['magnitude'],
                'confidence' => $outcome['confidence'],
                'evidenceCount' => $outcome['evidenceCount'],
            ],
            'decision' => $decision === null ? [
                'present' => false,
                'reason' => $outcome === null
                    ? 'Without the outcome, the decision it followed cannot be traced.'
                    : 'The outcome records no decision, so what was done about it is not on file.',
            ] : [
                'present' => true,
                'id' => $decision['id'],
                'status' => $decision['status'],
                'rationale' => $decision['rationale'],
                'explanation' => $decision['explanation'],
                'decidedBy' => $decision['decidedBy'],
                'confidence' => $decision['confidence'],
            ],
        ];
    }

    /**
     * A readable heading. The stored `pattern` is a slug —
     * "workload-redistribution-improves-load" — which is an identifier, not a
     * sentence, and putting it in a card heading unedited is the interface
     * showing the reader its own plumbing.
     */
    private function title(string $pattern, ?string $domain): string
    {
        $words = trim(str_replace(['-', '_'], ' ', $pattern));

        if ($words === '') {
            return $domain !== null ? ucfirst($domain).' learning' : 'Learning';
        }

        return ucfirst($words);
    }

    /**
     * Loads outcomes, their decisions and their evidence counts in a fixed
     * number of queries, whatever the page size.
     *
     * ONE QUERY PER TABLE, NOT ONE PER ROW. A card needs an outcome, a
     * decision and an evidence count; done per card that is three queries a
     * card and a feed that slows down linearly as memory grows.
     *
     * @param  array<int, mixed>  $outcomeIds
     * @return array{outcomes:array<string,array<string,mixed>>, decisions:array<string,array<string,mixed>>, patternCounts:array<string,int>}
     */
    private function contextFor(string $tenantId, array $outcomeIds): array
    {
        $patternCounts = DB::table(self::LEARNINGS)
            ->where('tenant_id', $tenantId)
            ->select('pattern', DB::raw('count(*) as c'))
            ->groupBy('pattern')
            ->pluck('c', 'pattern')
            ->mapWithKeys(fn ($c, $p) => [(string) $p => (int) $c])
            ->all();

        $ids = array_values(array_filter(array_map('strval', $outcomeIds)));

        if ($ids === []) {
            return ['outcomes' => [], 'decisions' => [], 'patternCounts' => $patternCounts];
        }

        $outcomeRows = DB::table(self::OUTCOMES)
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get();

        $outcomes = [];
        $decisionIds = [];

        foreach ($outcomeRows as $o) {
            $evidenceIds = KnowledgeGrading::jsonList($o->evidence_ids ?? null);
            $metrics = KnowledgeGrading::jsonList($o->metrics ?? null);
            $count = count($evidenceIds);

            $outcomes[(string) $o->id] = [
                'id' => (string) $o->id,
                'result' => (string) ($o->result ?? ''),
                'feedback' => ($o->feedback ?? null) !== null ? (string) $o->feedback : null,
                'metrics' => $metrics,
                'kpis' => KnowledgeGrading::jsonList($o->kpis ?? null),
                'evidenceIds' => $evidenceIds,
                'evidenceCount' => $count,
                'decisionId' => ($o->decision_id ?? null) !== null ? (string) $o->decision_id : null,
                'confidence' => KnowledgeGrading::confidence($o->confidence ?? null, $count),
                'magnitude' => KnowledgeGrading::outcomeMagnitude(
                    $o->result !== null ? (string) $o->result : null,
                    $metrics,
                    $count
                ),
                'createdDate' => $o->created_date ?? null,
            ];

            if (($o->decision_id ?? null) !== null) {
                $decisionIds[] = (string) $o->decision_id;
            }
        }

        $decisions = [];

        if ($decisionIds !== []) {
            foreach (
                DB::table(self::DECISIONS)
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', array_unique($decisionIds))
                    ->get() as $d
            ) {
                /*
                    READ EVERY OPTIONAL COLUMN THROUGH `??`.

                    hpbrain_decisions is not the same shape in every
                    deployment — it carries no created_by here, and older
                    installations lack explanation and decided_by. Touching a
                    property that is not on the row raises, and one absent
                    column would take the whole memory feed down with a 500
                    rather than degrading the single field that is missing.
                */
                $decisions[(string) $d->id] = [
                    'id' => (string) $d->id,
                    'status' => (string) ($d->status ?? ''),
                    'rationale' => ($d->rationale ?? null) !== null ? (string) $d->rationale : null,
                    'explanation' => ($d->explanation ?? null) !== null ? (string) $d->explanation : null,
                    'decidedBy' => ($d->decided_by ?? null) !== null ? (string) $d->decided_by : null,
                    'confidence' => KnowledgeGrading::confidence($d->confidence ?? null, 0),
                    'createdDate' => $d->created_date ?? null,
                ];
            }
        }

        return ['outcomes' => $outcomes, 'decisions' => $decisions, 'patternCounts' => $patternCounts];
    }

    /**
     * The evidence behind an outcome.
     *
     * When `evidence_ids` is empty the answer is not an empty list dressed as
     * "no evidence exists" — it is that the outcome was recorded without
     * attaching any, which is a fact about the record-keeping. The caller is
     * told which of the two it is.
     *
     * @param  array<string, mixed>|null  $outcome
     * @return array{supported:bool, reason:string|null, items:array<int,array<string,mixed>>}
     */
    private function evidenceFor(string $tenantId, ?array $outcome): array
    {
        if ($outcome === null) {
            return [
                'supported' => false,
                'reason' => 'There is no outcome on file, so no evidence can be traced through it.',
                'items' => [],
            ];
        }

        $ids = array_values(array_filter(array_map('strval', $outcome['evidenceIds'] ?? [])));

        if ($ids === []) {
            return [
                'supported' => false,
                'reason' => 'This outcome was recorded without attaching any evidence rows, so the result it claims cannot be checked against the records it came from.',
                'items' => [],
            ];
        }

        if (! Schema::hasTable(self::EVIDENCE)) {
            return ['supported' => false, 'reason' => 'The evidence ledger is not present in this installation.', 'items' => []];
        }

        $items = DB::table(self::EVIDENCE)
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->get()
            ->map(function ($e) {
                $provenance = KnowledgeGrading::jsonList($e->provenance ?? null);
                $content = KnowledgeGrading::jsonList($e->content ?? null);

                return [
                    'id' => (string) $e->id,
                    'source' => (string) ($e->source ?? ''),
                    'type' => (string) ($e->evidence_type ?? ''),
                    'statement' => $content['statement'] ?? (is_string($e->content) ? (string) $e->content : null),
                    'status' => (string) ($e->status ?? ''),
                    'observedDate' => $e->observed_date ?? null,
                    'confidence' => KnowledgeGrading::confidence($e->confidence ?? null, 1),
                    'provenance' => KnowledgeGrading::provenance($e->created_by ?? null, $provenance),
                    'derivedFrom' => $provenance['derivedFrom'] ?? null,
                    'method' => $provenance['method'] ?? null,
                ];
            })
            ->values()
            ->all();

        return ['supported' => true, 'reason' => null, 'items' => $items];
    }

    /**
     * What was actually carried out under the decision — the EXECUTION half of
     * the loop, which is what turns a decision into something that happened.
     *
     * @return array<int, array<string, mixed>>
     */
    private function executionsFor(string $tenantId, string $decisionId): array
    {
        if (! Schema::hasTable(self::EXECUTIONS)) {
            return [];
        }

        $rows = DB::table(self::EXECUTIONS)
            ->where('tenant_id', $tenantId)
            ->where('decision_id', $decisionId)
            ->get();

        $definitionIds = $rows->pluck('eso_definition_id')->filter()->unique()->all();
        $names = [];

        if ($definitionIds !== [] && Schema::hasTable('hpbrain_eso_definitions')) {
            $names = DB::table('hpbrain_eso_definitions')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $definitionIds)
                ->pluck('name', 'id')
                ->mapWithKeys(fn ($n, $i) => [(string) $i => (string) $n])
                ->all();
        }

        return $rows->map(function ($x) use ($names) {
            $output = KnowledgeGrading::jsonList($x->output ?? null);

            return [
                'id' => (string) $x->id,
                'esoId' => ($x->eso_definition_id ?? null) !== null ? (string) $x->eso_definition_id : null,
                'esoName' => $names[(string) $x->eso_definition_id] ?? null,
                'status' => (string) ($x->status ?? ''),
                'executedBy' => ($x->executed_by ?? null) !== null ? (string) $x->executed_by : null,
                'executorType' => ($x->executor_type ?? null) !== null ? (string) $x->executor_type : null,
                'note' => $output['note'] ?? null,
                'result' => $output['result'] ?? null,
                'error' => ($x->error ?? null) !== null ? (string) $x->error : null,
                'completedDate' => $x->completed_date ?? null,
            ];
        })->values()->all();
    }

    /**
     * Other times this organization reached the same named conclusion.
     *
     * @return array<int, array<string, mixed>>
     */
    private function similar(string $tenantId, object $row): array
    {
        $pattern = (string) ($row->pattern ?? '');

        if ($pattern === '') {
            return [];
        }

        return DB::table(self::LEARNINGS)
            ->where('tenant_id', $tenantId)
            ->where('pattern', $pattern)
            ->where('id', '!=', (string) $row->id)
            ->orderByDesc('created_date')
            ->limit(10)
            ->get(['id', 'pattern', 'description', 'domain', 'created_date', 'confidence'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'title' => $this->title((string) $r->pattern, $r->domain ?? null),
                'lesson' => (string) ($r->description ?? ''),
                'createdDate' => $r->created_date ?? null,
                'relation' => 'Same pattern, reached independently',
            ])
            ->values()
            ->all();
    }

    /**
     * WHAT THIS LEARNING WENT ON TO CHANGE.
     *
     * The honest answer is "nothing records that". No column ties a decision
     * back to the learning that informed it, so any list here would be
     * assembled from timing and called influence. The field names the missing
     * link and what would close it instead — the reuse that IS visible is the
     * same pattern being reached again, which the card already carries.
     *
     * @return array{supported:bool, reason:string, unlock:string, items:array<int,mixed>}
     */
    private function influenced(string $tenantId, object $row): array
    {
        $pattern = (string) ($row->pattern ?? '');
        $later = $pattern === '' ? 0 : (int) DB::table(self::LEARNINGS)
            ->where('tenant_id', $tenantId)
            ->where('pattern', $pattern)
            ->where('created_date', '>', (string) ($row->created_date ?? ''))
            ->count();

        return [
            'supported' => false,
            'reason' => 'No column links a later decision back to the learning that informed it, so decisions influenced by this memory cannot be listed without guessing.',
            'unlock' => 'Record a learning_id on decisions (or an influenced_by list on learnings) to make this chain traceable.',
            'observedReuse' => $later,
            'observedReuseDetail' => $later > 0
                ? 'This same pattern was reached '.$later.' further time(s) after this memory was written, which is reuse the data can actually show.'
                : 'This pattern has not been reached again since this memory was written.',
            'items' => [],
        ];
    }

    private function isFailure(string $result): bool
    {
        return in_array(strtolower(trim($result)), ['failed', 'failure', 'regressed', 'worse', 'rejected', 'abandoned'], true);
    }

    /** @param array<string, mixed> $filters */
    private function filtered(string $tenantId, array $filters): Builder
    {
        $query = DB::table(self::LEARNINGS)->where('tenant_id', $tenantId);

        if (! empty($filters['domain'])) {
            $query->where('domain', (string) $filters['domain']);
        }

        if (! empty($filters['pattern'])) {
            $query->where('pattern', (string) $filters['pattern']);
        }

        if (array_key_exists('reusable', $filters) && $filters['reusable'] !== null && $filters['reusable'] !== '') {
            $query->where('reusable', filter_var($filters['reusable'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        }

        if (! empty($filters['provenance'])) {
            $seeded = (array) config('knowledge.provenance.seeded_actors', []);
            if ($seeded !== []) {
                (string) $filters['provenance'] === 'SEEDED'
                    ? $query->whereIn('created_by', $seeded)
                    : $query->where(fn ($w) => $w->whereNotIn('created_by', $seeded)->orWhereNull('created_by'));
            }
        }

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            if ($term !== '') {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $query->where(fn ($w) => $w
                    ->where('pattern', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('domain', 'like', $like));
            }
        }

        return $query;
    }

    private function pageSize(mixed $requested): int
    {
        $default = (int) config('knowledge.pagination.page_size', 24);
        $max = (int) config('knowledge.pagination.max_page_size', 100);
        $size = is_numeric($requested) ? (int) $requested : $default;

        return max(1, min($max, $size));
    }
}
