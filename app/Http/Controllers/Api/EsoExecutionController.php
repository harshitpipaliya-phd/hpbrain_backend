<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Eso\EsoEfficacy;
use App\Domain\Eso\EsoPreflight;
use App\Domain\Eso\EsoStatus;
use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Http\Controllers\Controller;
use App\Repositories\EsoExecutionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * ESO runtime. Invariant 4: no ESO runs without a measurement plan defined
 * BEFORE it starts — an action we cannot measure is an action we do not take.
 *
 * Invariant 3 / ADR-004: EXECUTE ships DARK in v1. The executor binding is
 * restricted to 'human' here; autonomous AI execution is built and governed but
 * flag-off, because EXECUTE is the only verb that changes the world outside the
 * Brain.
 */
final class EsoExecutionController extends Controller
{
    public function __construct(
        private readonly EsoExecutionRepository $repository,
        private readonly EventPublisher $events,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request), $request->query('status')));
    }

    /**
     * The ESO catalogue: what this organization can actually run, and how well it
     * has worked.
     *
     * ADDED BECAUSE THE SCREEN THAT NEEDS IT WAS SHIPPING FICTION. EsoLibraryScreen
     * held two hardcoded definitions and one hardcoded efficacy record, with a
     * comment saying the data layer would arrive later. Anybody opening the screen
     * saw a populated library for an organization that had none — the worst kind of
     * placeholder, because it is indistinguishable from real content.
     *
     * Read-only and deliberately so. Authoring ESOs is a governed write path with a
     * trust model and an approval route behind it, and nothing here is a substitute
     * for building that. This endpoint answers one question honestly: what is in the
     * catalogue right now.
     *
     * Efficacy is attached per definition rather than fetched separately, because a
     * definition's track record is the only thing that distinguishes a proven
     * intervention from a plausible one, and a screen that has to ask twice will
     * eventually show the first without the second.
     */
    public function definitions(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $definitions = DB::table('hpbrain_eso_definitions')
            ->where('tenant_id', $tenantId)
            ->orderBy('eso_code')
            ->get();

        $efficacy = DB::table('hpbrain_eso_efficacy_records')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('computed_date')
            ->get()
            ->groupBy('eso_definition_id');

        $runs = DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $tenantId)
            ->selectRaw('eso_id, COUNT(*) AS runs, MAX(created_date) AS last_run')
            ->groupBy('eso_id')
            ->get()
            ->keyBy('eso_id');

        $outcomeCounts = DB::table('hpbrain_eso_executions as x')
            ->leftJoin('hpbrain_outcomes as o', function ($join) use ($tenantId) {
                $join->on('o.decision_id', '=', 'x.decision_id')
                    ->where('o.tenant_id', '=', $tenantId);
            })
            ->where('x.tenant_id', $tenantId)
            ->selectRaw('x.eso_id, COUNT(o.id) AS outcomes')
            ->groupBy('x.eso_id')
            ->get()
            ->keyBy('eso_id');

        return response()->json([
            'definitions' => $definitions->map(function ($d) use ($tenantId, $efficacy, $runs, $outcomeCounts) {
                $records = $efficacy->get($d->id, collect());
                $run = $runs->get($d->id);
                $outcomes = (int) ($outcomeCounts->get($d->id)?->outcomes ?? 0);

                return $this->definitionPayload($tenantId, $d, [
                    'runs' => $run === null ? 0 : (int) $run->runs,
                    'lastRun' => $run?->last_run,
                    'outcomes' => $outcomes,
                    // Empty is a real answer: an ESO with no efficacy record has no
                    // track record, which is different from having a poor one.
                    'efficacy' => $records->map(fn ($e) => [
                        'id' => (string) $e->id,
                        'gapType' => $e->gap_type === null ? null : (string) $e->gap_type,
                        'population' => $e->population === null ? null : (string) $e->population,
                        'efficacyScore' => $e->efficacy_score === null ? null : (float) $e->efficacy_score,
                        'sampleSize' => $e->sample_size === null ? null : (int) $e->sample_size,
                        'computedDate' => $e->computed_date,
                    ])->values(),
                ]);
            })->values(),
            'totals' => [
                'definitions' => $definitions->count(),
                // NOT `status === 'active'`. Two writers already populate this
                // column with different words — the demo seeder writes
                // 'published', DeriveLionsIntelligence writes 'active' — so the
                // literal comparison reported a tenant with four published,
                // runnable ESOs as having zero active. EsoStatus holds the one
                // set of in-service states that the count, the readiness gate
                // and the execution gate all share.
                'active' => $definitions->filter(fn ($d) => EsoStatus::inService((string) $this->field($d, 'status', '')))->count(),
                'withEfficacy' => $definitions->filter(fn ($d) => $efficacy->has($d->id))->count(),
                'executions' => (int) DB::table('hpbrain_eso_executions')->where('tenant_id', $tenantId)->count(),
                'measurableOutcomes' => (int) DB::table('hpbrain_outcomes')->where('tenant_id', $tenantId)->count(),
            ],
        ]);
    }

    /**
     * The approved decisions that could authorise a run right now.
     *
     * WHY THIS ENDPOINT EXISTS. Starting an ESO requires an approved decision
     * id and a measurement plan that pre-dates the run. Both are correct
     * requirements — Invariant 4 is the reason this product can tell an action
     * from a guess — but the only way to satisfy them from the library screen
     * was to type a UUID into a text box. Nobody outside this repository knows
     * a decision UUID, so in practice the Run button could not be used at all,
     * and the requirement that makes execution trustworthy was also the
     * requirement that made it unreachable.
     *
     * This is the list a person can actually choose from: decisions this tenant
     * has approved, that no execution has consumed yet, with the recommendation
     * that produced them, whether a measurement plan already exists, and which
     * ESO — if any — the recommendation was bound to. Nothing here is invented;
     * every row is a decision somebody approved.
     *
     * Deliberately not the analytics execution-overview endpoint, which
     * computes a dozen unrelated aggregates over the whole loop to answer this
     * one question.
     */
    public function runnableDecisions(Request $request, string $tenantId): JsonResponse
    {
        $tenant = $this->tenantId($request);

        $consumed = DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $tenant)
            ->whereNotNull('decision_id')
            ->pluck('decision_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $decisions = DB::table('hpbrain_decisions as d')
            ->leftJoin('hpbrain_recommendations as r', function ($join) use ($tenant) {
                $join->on('r.id', '=', 'd.recommendation_id')
                    ->where('r.tenant_id', '=', $tenant);
            })
            ->where('d.tenant_id', $tenant)
            ->whereRaw('LOWER(d.status) = ?', ['approved'])
            ->when($consumed !== [], fn ($q) => $q->whereNotIn('d.id', $consumed))
            ->orderByDesc(DB::raw('COALESCE(d.approved_date, d.created_date)'))
            ->limit(50)
            ->get([
                'd.id', 'd.recommendation_id', 'd.approved_by', 'd.decided_by',
                'd.approved_date', 'd.created_date', 'd.rationale',
                'r.title as recommendation_title', 'r.category as recommendation_category',
                'r.priority as recommendation_priority', 'r.eso_id as recommendation_eso_id',
            ]);

        $plans = DB::table('hpbrain_measurement_plans')
            ->where('tenant_id', $tenant)
            ->whereIn('decision_id', $decisions->pluck('id')->map(fn ($id) => (string) $id)->all() ?: [''])
            ->orderBy('created_date')
            ->get()
            ->keyBy(fn ($p) => (string) $p->decision_id);

        return response()->json([
            'decisions' => $decisions->map(function ($d) use ($plans) {
                $plan = $plans->get((string) $d->id);

                return [
                    'id' => (string) $d->id,
                    'title' => $d->recommendation_title === null ? 'Approved decision' : (string) $d->recommendation_title,
                    'rationale' => $d->rationale === null ? null : (string) $d->rationale,
                    'category' => $d->recommendation_category === null ? null : (string) $d->recommendation_category,
                    'priority' => $d->recommendation_priority === null ? null : (string) $d->recommendation_priority,
                    'recommendationId' => $d->recommendation_id === null ? null : (string) $d->recommendation_id,
                    // The ESO the recommendation itself named. Where present,
                    // the screen can pre-select it instead of asking a person to
                    // pair a decision with a capability by hand.
                    'boundEsoId' => $d->recommendation_eso_id === null ? null : (string) $d->recommendation_eso_id,
                    'approvedBy' => $d->approved_by === null ? (string) ($d->decided_by ?? '') : (string) $d->approved_by,
                    'approvedDate' => $d->approved_date ?? $d->created_date,
                    'hasMeasurementPlan' => $plan !== null,
                    'measurementPlan' => $plan === null ? null : [
                        'id' => (string) $plan->id,
                        'baselineMetric' => (string) $plan->baseline_metric,
                        'baselineValue' => $plan->baseline_value === null ? null : (float) $plan->baseline_value,
                        'targetValue' => $plan->target_value === null ? null : (float) $plan->target_value,
                        'metricUnit' => $plan->metric_unit === null ? null : (string) $plan->metric_unit,
                        'measurementWindowDays' => $plan->measurement_window_days === null ? null : (int) $plan->measurement_window_days,
                    ],
                ];
            })->values(),
            'note' => 'An ESO runs against a decision this organization has already approved. Decisions already consumed by an execution are not listed.',
        ]);
    }

    public function definition(Request $request, string $tenantId, string $id): JsonResponse
    {
        $tenant = $this->tenantId($request);
        $row = DB::table('hpbrain_eso_definitions')
            ->where('tenant_id', $tenant)
            ->where('id', $id)
            ->first();

        if (! $row) {
            return response()->json(['error' => 'eso_definition_not_found'], 404);
        }

        $efficacy = DB::table('hpbrain_eso_efficacy_records')
            ->where('tenant_id', $tenant)
            ->where('eso_definition_id', $id)
            ->orderByDesc('computed_date')
            ->get()
            ->map(fn ($e) => [
                'id' => (string) $e->id,
                'gapType' => $e->gap_type === null ? null : (string) $e->gap_type,
                'population' => $e->population === null ? null : (string) $e->population,
                'efficacyScore' => $e->efficacy_score === null ? null : (float) $e->efficacy_score,
                'sampleSize' => $e->sample_size === null ? null : (int) $e->sample_size,
                'computedDate' => $e->computed_date,
            ]);

        $runs = (int) DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $tenant)
            ->where('eso_id', $id)
            ->count();

        $lastRun = DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $tenant)
            ->where('eso_id', $id)
            ->max('created_date');

        $outcomes = (int) DB::table('hpbrain_eso_executions as x')
            ->join('hpbrain_outcomes as o', function ($join) use ($tenant) {
                $join->on('o.decision_id', '=', 'x.decision_id')
                    ->where('o.tenant_id', '=', $tenant);
            })
            ->where('x.tenant_id', $tenant)
            ->where('x.eso_id', $id)
            ->count('o.id');

        return response()->json($this->definitionPayload($tenant, $row, [
            'runs' => $runs,
            'lastRun' => $lastRun,
            'outcomes' => $outcomes,
            'efficacy' => $efficacy,
            'detail' => true,
        ]));
    }

    /**
     * A JSON column read as a list of strings.
     *
     * Returns [] for null, malformed JSON, or a scalar. A catalogue screen rendering
     * `allowedExecutorClasses.join()` must not be handed a string, and throwing here
     * would blank a whole screen over one bad row written by an earlier build.
     *
     * @return array<int, string>
     */
    private function jsonList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v), $decoded));
    }

    private function jsonValue(mixed $value, mixed $fallback): mixed
    {
        if ($value === null) {
            return $fallback;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }

    private function field(object $row, string $field, mixed $fallback = null): mixed
    {
        return property_exists($row, $field) ? $row->{$field} : $fallback;
    }

    /** @param array<string, mixed> $extra */
    private function definitionPayload(string $tenantId, object $d, array $extra): array
    {
        $id = (string) $this->field($d, 'id', '');
        $runs = (int) ($extra['runs'] ?? 0);
        $outcomes = (int) ($extra['outcomes'] ?? 0);
        $efficacy = collect($extra['efficacy'] ?? [])->values();

        $payload = [
            'id' => $id,
            'esoCode' => (string) $this->field($d, 'eso_code', ''),
            'name' => (string) $this->field($d, 'name', 'unnamed'),
            'purpose' => $this->field($d, 'trigger_description') ?? $this->field($d, 'objective'),
            'objective' => $this->field($d, 'objective') === null ? null : (string) $this->field($d, 'objective'),
            'category' => $this->field($d, 'kasba_node_type') === null ? (string) $this->field($d, 'objective', 'uncategorized') : (string) $this->field($d, 'kasba_node_type'),
            'status' => (string) $this->field($d, 'status', 'unknown'),
            'version' => (int) $this->field($d, 'version', 1),
            'owner' => $this->field($d, 'owner') === null ? null : (string) $this->field($d, 'owner'),
            'permissions' => [
                'allowedExecutorClasses' => $this->jsonList($this->field($d, 'allowed_executor_classes')),
                'trustLevel' => $this->field($d, 'trust_level') === null ? null : (string) $this->field($d, 'trust_level'),
                'constraintsPolicies' => $this->jsonValue($this->field($d, 'constraints_policies'), []),
            ],
            'trustLevel' => $this->field($d, 'trust_level') === null ? null : (string) $this->field($d, 'trust_level'),
            'allowedExecutorClasses' => $this->jsonList($this->field($d, 'allowed_executor_classes')),
            'gapTypes' => $this->jsonList($this->field($d, 'gap_types')),
            'whenToUse' => $this->jsonValue($this->field($d, 'applicable_contexts'), []),
            'inputs' => $this->jsonValue($this->field($d, 'inputs'), []),
            'preconditions' => $this->jsonValue($this->field($d, 'preconditions'), []),
            'prerequisites' => $this->jsonValue($this->field($d, 'prerequisites'), []),
            'executionSteps' => $this->jsonValue($this->field($d, 'procedure_steps'), []),
            'expectedOutput' => $this->jsonValue($this->field($d, 'outputs'), []),
            'relatedKnowledge' => $this->relatedKnowledge($tenantId, $d),
            'relatedRecommendations' => $this->relatedRecommendations($tenantId, $id),
            'kasbaNodeType' => $this->field($d, 'kasba_node_type') === null ? null : (string) $this->field($d, 'kasba_node_type'),
            // "Can I run it, and what does it need?" answered by the same object
            // the execution endpoint gates on, so the button the screen offers
            // and the request the server accepts can never disagree.
            'readiness' => EsoPreflight::assess($d),
            'runs' => $runs,
            'lastRun' => $extra['lastRun'] ?? null,
            'outcomes' => $outcomes,
            'outcomeStatus' => $this->outcomeStatus($runs, $outcomes, $efficacy->count()),
            // The STORED track record: snapshots written by brain:compute-eso-efficacy.
            'efficacy' => $efficacy,
            /*
              The COMPUTED one, derived on read from this tenant's own plans and
              outcomes. Both are shipped because they answer different
              questions: `efficacy` is what was true when the snapshot was
              taken, `efficacyAnalysis` is what is true now, and it carries the
              workings — which executions counted, which did not, and why.

              Where nothing can be scored this is a status and a sentence, never
              a zero. A 0 would be indistinguishable on screen from a measured
              total failure, and the two are opposite findings.
            */
            'efficacyAnalysis' => EsoEfficacy::forDefinition($tenantId, $id),
            'efficacyMessage' => $runs > 0 && $efficacy->count() === 0
                ? EsoEfficacy::UNMEASURABLE_MESSAGE
                : null,
        ];

        if (! empty($extra['detail'])) {
            $payload['executionHistory'] = $this->executionHistory($tenantId, $id);
            $payload['outcomeHistory'] = $this->outcomeHistory($tenantId, $id);
            $payload['evidence'] = $this->linkedEvidence($tenantId, $id);
            $payload['intelligenceLoop'] = $this->intelligenceLoop($tenantId, $id);
        }

        return $payload;
    }

    private function outcomeStatus(int $runs, int $outcomes, int $efficacyRecords): string
    {
        if ($runs === 0) {
            return 'not executed';
        }

        if ($efficacyRecords === 0 || $outcomes === 0) {
            return EsoEfficacy::UNMEASURABLE_MESSAGE;
        }

        return 'measured';
    }

    private function relatedRecommendations(string $tenantId, string $esoId): array
    {
        return DB::table('hpbrain_recommendations')
            ->where('tenant_id', $tenantId)
            ->where('eso_id', $esoId)
            ->orderByDesc('created_date')
            ->limit(20)
            ->get(['id', 'title', 'category', 'priority', 'status', 'confidence', 'created_date'])
            ->map(fn ($r) => [
                'id' => (string) $r->id,
                'title' => (string) $r->title,
                'category' => (string) $r->category,
                'priority' => (string) $r->priority,
                'status' => (string) $r->status,
                'confidence' => $r->confidence === null ? null : (float) $r->confidence,
                'createdDate' => $r->created_date,
            ])
            ->values()
            ->all();
    }

    private function relatedKnowledge(string $tenantId, object $definition): array
    {
        $terms = array_values(array_filter(array_unique(array_merge(
            [(string) $this->field($definition, 'name', ''), (string) $this->field($definition, 'objective', '')],
            $this->jsonList($this->field($definition, 'gap_types'))
        ))));

        if ($terms === []) {
            return ['knowledgeAssets' => [], 'memory' => []];
        }

        $assets = Schema::hasTable('hpbrain_knowledge_assets')
            ? tap(DB::table('hpbrain_knowledge_assets')->where('tenant_id', $tenantId), fn ($query) => $this->applyTermSearch($query, ['title', 'category', 'tags'], $terms))
                ->orderByDesc('created_date')
                ->limit(5)
                ->get(['id', 'title', 'category', 'status', 'created_date'])
                ->map(fn ($k) => [
                    'id' => (string) $k->id,
                    'title' => (string) $k->title,
                    'category' => (string) $k->category,
                    'status' => (string) $k->status,
                    'createdDate' => $k->created_date,
                ])
                ->values()
                ->all()
            : [];

        $memory = Schema::hasTable('hpbrain_learnings')
            ? tap(DB::table('hpbrain_learnings')->where('tenant_id', $tenantId), fn ($query) => $this->applyTermSearch($query, ['pattern', 'description', 'domain'], $terms))
                ->orderByDesc('created_date')
                ->limit(5)
                ->get(['id', 'pattern', 'description', 'domain', 'created_date'])
                ->map(fn ($m) => [
                    'id' => (string) $m->id,
                    'pattern' => (string) $m->pattern,
                    'description' => $m->description === null ? null : (string) $m->description,
                    'domain' => $m->domain === null ? null : (string) $m->domain,
                    'createdDate' => $m->created_date,
                ])
                ->values()
                ->all()
            : [];

        return ['knowledgeAssets' => $assets, 'memory' => $memory];
    }

    /** @param array<int, string> $columns @param array<int, string> $terms */
    private function applyTermSearch($query, array $columns, array $terms): void
    {
        $query->where(function ($w) use ($terms, $columns) {
            foreach ($terms as $term) {
                $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                foreach ($columns as $column) {
                    $w->orWhere($column, 'like', $needle);
                }
            }
        });
    }

    /**
     * The evidence actually cited by this ESO's runs.
     *
     * Reached through hpbrain_eso_execution_evidence, which is written at
     * execution time from evidence rows this tenant already owns — so what the
     * detail screen shows under "evidence" is a record of what was cited, not a
     * search for anything that looks related. An ESO with runs and no rows here
     * has genuinely cited nothing, and the screen says so rather than filling
     * the space.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linkedEvidence(string $tenantId, string $esoId): array
    {
        if (! Schema::hasTable('hpbrain_eso_execution_evidence')) {
            return [];
        }

        return DB::table('hpbrain_eso_execution_evidence as link')
            ->join('hpbrain_eso_executions as x', function ($join) use ($tenantId) {
                $join->on('x.id', '=', 'link.execution_id')
                    ->where('x.tenant_id', '=', $tenantId);
            })
            ->join('hpbrain_evidence as e', function ($join) use ($tenantId) {
                $join->on('e.id', '=', 'link.evidence_id')
                    ->where('e.tenant_id', '=', $tenantId);
            })
            ->where('link.tenant_id', $tenantId)
            ->where(fn ($w) => $w->where('x.eso_id', $esoId)->orWhere('x.eso_definition_id', $esoId))
            ->orderByDesc('link.linked_date')
            ->limit(50)
            ->get(['e.id', 'e.evidence_type', 'e.source', 'e.confidence', 'e.status', 'e.created_date', 'link.execution_id', 'link.linked_date'])
            ->map(fn ($e) => [
                'id' => (string) $e->id,
                'executionId' => (string) $e->execution_id,
                'evidenceType' => $e->evidence_type === null ? null : (string) $e->evidence_type,
                'source' => $e->source === null ? null : (string) $e->source,
                'confidence' => $e->confidence === null ? null : (float) $e->confidence,
                'status' => $e->status === null ? null : (string) $e->status,
                'linkedDate' => $e->linked_date,
                'createdDate' => $e->created_date,
            ])
            ->values()
            ->all();
    }

    /**
     * The chain this ESO sits in: finding → recommendation → decision → this ESO
     * → execution → evidence → outcome → learning.
     *
     * ONE REAL WALK, NOT A DIAGRAM. Every node is populated from the most
     * recent execution that actually reached the furthest along the chain, and
     * a node whose row does not exist reports `present: false` with an id of
     * null. The screen renders a link only where there is an id to link to, so
     * a half-finished loop looks half-finished rather than looking whole.
     *
     * The join key throughout is the DECISION, which is also the correlation id
     * on every loop event — so this walk and the event stream agree by
     * construction rather than by coincidence.
     *
     * @return array<string, mixed>
     */
    private function intelligenceLoop(string $tenantId, string $esoId): array
    {
        $definition = DB::table('hpbrain_eso_definitions')
            ->where('tenant_id', $tenantId)->where('id', $esoId)
            ->first(['id', 'name', 'eso_code']);

        // The furthest-travelled run wins, so the chain shows what this ESO has
        // achieved at its best rather than whatever happened most recently.
        $execution = DB::table('hpbrain_eso_executions as x')
            ->leftJoin('hpbrain_outcomes as o', function ($join) use ($tenantId) {
                $join->on('o.decision_id', '=', 'x.decision_id')->where('o.tenant_id', '=', $tenantId);
            })
            ->where('x.tenant_id', $tenantId)
            ->where(fn ($w) => $w->where('x.eso_id', $esoId)->orWhere('x.eso_definition_id', $esoId))
            ->orderByDesc(DB::raw('CASE WHEN o.id IS NULL THEN 0 ELSE 1 END'))
            ->orderByDesc('x.created_date')
            ->first(['x.id', 'x.decision_id', 'x.status', 'x.created_date', 'o.id as outcome_id', 'o.result as outcome_result']);

        $decision = $execution?->decision_id === null ? null : DB::table('hpbrain_decisions')
            ->where('tenant_id', $tenantId)->where('id', $execution->decision_id)
            ->first(['id', 'recommendation_id', 'status']);

        $recommendation = $decision?->recommendation_id === null
            ? DB::table('hpbrain_recommendations')
                ->where('tenant_id', $tenantId)->where('eso_id', $esoId)
                ->orderByDesc('created_date')
                ->first(['id', 'title', 'reasoning_step_id', 'status'])
            : DB::table('hpbrain_recommendations')
                ->where('tenant_id', $tenantId)->where('id', $decision->recommendation_id)
                ->first(['id', 'title', 'reasoning_step_id', 'status']);

        $signal = $recommendation?->reasoning_step_id === null ? null : DB::table('hpbrain_reasoning_steps as r')
            ->join('hpbrain_signals as s', function ($join) use ($tenantId) {
                $join->on('s.id', '=', 'r.signal_id')->where('s.tenant_id', '=', $tenantId);
            })
            ->where('r.tenant_id', $tenantId)->where('r.id', $recommendation->reasoning_step_id)
            ->first(['s.id', 's.classification']);

        $evidenceCount = $execution === null || ! Schema::hasTable('hpbrain_eso_execution_evidence') ? 0
            : (int) DB::table('hpbrain_eso_execution_evidence')
                ->where('tenant_id', $tenantId)->where('execution_id', $execution->id)
                ->count();

        $learning = $execution?->outcome_id === null ? null : DB::table('hpbrain_learnings')
            ->where('tenant_id', $tenantId)->where('outcome_id', $execution->outcome_id)
            ->orderByDesc('created_date')
            ->first(['id', 'pattern', 'reusable']);

        $efficacy = EsoEfficacy::forDefinition($tenantId, $esoId);

        return [
            'nodes' => [
                $this->loopNode('signal', 'Signal / finding', $signal?->id, $signal === null ? null : (string) $signal->classification,
                    'No signal is traceable to this capability through a reasoning step.'),
                $this->loopNode('recommendation', 'Recommendation', $recommendation?->id, $recommendation === null ? null : (string) $recommendation->title,
                    'No recommendation names this ESO.'),
                $this->loopNode('eso', 'ESO', $definition?->id, $definition === null ? null : (string) $definition->name, 'This ESO.'),
                $this->loopNode('decision', 'Approved decision', $decision?->id, $decision === null ? null : 'status '.$decision->status,
                    'No decision has authorised a run of this ESO.'),
                $this->loopNode('execution', 'Execution', $execution?->id, $execution === null ? null : (string) $execution->status,
                    'This ESO has never been executed.'),
                $this->loopNode('evidence', 'Evidence', $evidenceCount > 0 ? (string) $execution?->id : null,
                    $evidenceCount > 0 ? $evidenceCount.' evidence row'.($evidenceCount === 1 ? '' : 's').' cited' : null,
                    'No evidence was cited by that execution.'),
                $this->loopNode('outcome', 'Outcome', $execution?->outcome_id, $execution?->outcome_result === null ? null : (string) $execution->outcome_result,
                    'Outcome not yet recorded.'),
                $this->loopNode('efficacy', 'Efficacy', $efficacy['status'] === EsoEfficacy::MEASURABLE ? $esoId : null,
                    $efficacy['status'] === EsoEfficacy::MEASURABLE ? $efficacy['verdict'] : null,
                    $efficacy['status'] === EsoEfficacy::NOT_MEASURABLE
                        ? 'Nothing has run, so there is nothing to measure.'
                        : EsoEfficacy::UNMEASURABLE_MESSAGE),
                $this->loopNode('learning', 'Learning / memory', $learning?->id, $learning === null ? null : (string) $learning->pattern,
                    'No learning has been written back from this ESO\'s outcomes.'),
            ],
            'complete' => $learning !== null,
            'note' => 'Each step is a row this organization actually holds. A step with no row is shown as absent rather than skipped, because a loop that stops halfway is the finding.',
        ];
    }

    /**
     * One node. `present` is what the screen keys its link off — never the
     * label, which is populated for absent nodes too so the chain still reads
     * as a chain.
     *
     * @return array<string, mixed>
     */
    private function loopNode(string $kind, string $label, mixed $id, ?string $detail, string $absentNote): array
    {
        $resolved = $id === null || $id === '' ? null : (string) $id;

        return [
            'kind' => $kind,
            'label' => $label,
            'id' => $resolved,
            'present' => $resolved !== null,
            'detail' => $resolved === null ? $absentNote : ($detail ?? $label),
        ];
    }

    private function executionHistory(string $tenantId, string $esoId): array
    {
        return DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $tenantId)
            ->where('eso_id', $esoId)
            ->orderByDesc('created_date')
            ->limit(25)
            ->get()
            ->map(fn ($x) => [
                'id' => (string) $x->id,
                'decisionId' => $x->decision_id === null ? null : (string) $x->decision_id,
                'status' => (string) $x->status,
                'executedBy' => $x->executed_by === null ? null : (string) $x->executed_by,
                'executorType' => $x->executor_type === null ? null : (string) $x->executor_type,
                'input' => $this->jsonValue($x->input ?? null, []),
                'output' => $this->jsonValue($x->output ?? null, null),
                'error' => $x->error === null ? null : (string) $x->error,
                'startedDate' => $x->started_date,
                'completedDate' => $x->completed_date,
                'createdDate' => $x->created_date,
            ])
            ->values()
            ->all();
    }

    private function outcomeHistory(string $tenantId, string $esoId): array
    {
        return DB::table('hpbrain_eso_executions as x')
            ->join('hpbrain_outcomes as o', function ($join) use ($tenantId) {
                $join->on('o.decision_id', '=', 'x.decision_id')
                    ->where('o.tenant_id', '=', $tenantId);
            })
            ->where('x.tenant_id', $tenantId)
            ->where('x.eso_id', $esoId)
            ->orderByDesc('o.created_date')
            ->limit(25)
            ->get(['x.id as execution_id', 'o.id', 'o.decision_id', 'o.result', 'o.metrics', 'o.kpis', 'o.evidence_ids', 'o.feedback', 'o.confidence', 'o.created_date'])
            ->map(fn ($o) => [
                'id' => (string) $o->id,
                'executionId' => (string) $o->execution_id,
                'decisionId' => $o->decision_id === null ? null : (string) $o->decision_id,
                'result' => (string) $o->result,
                'metrics' => $this->jsonValue($o->metrics ?? null, []),
                'kpis' => $this->jsonValue($o->kpis ?? null, []),
                'evidenceIds' => $this->jsonValue($o->evidence_ids ?? null, []),
                'feedback' => $o->feedback === null ? null : (string) $o->feedback,
                'confidence' => $o->confidence === null ? null : (float) $o->confidence,
                'createdDate' => $o->created_date,
            ])
            ->values()
            ->all();
    }

    /**
     * COLUMN MAPPING — read this before editing the insert below.
     *
     * This method previously wrote `executor_id`, `measurement_plan` and
     * `created_by`, none of which exist on hpbrain_eso_executions, and omitted
     * `eso_id` and `executed_by`, which are NOT NULL. Every ESO execution write
     * therefore failed — Invariant 4 was enforced in validation and impossible
     * to persist, and stage 9 of the loop could not be reached at all. That had
     * to be fixed here rather than in a migration (this module adds no schema).
     *
     * The mapping onto columns that do exist:
     *   executorId      -> executed_by (TEXT NOT NULL), defaulting to the
     *                      caller: whoever starts an execution owns it.
     *   measurementPlan -> input JSON, as {"measurementPlan": "..."}. Stored,
     *                      not dropped — Invariant 4 says no ESO runs without a
     *                      measurement plan defined BEFORE it starts, and a
     *                      plan we discard is a plan we did not define.
     *   esoDefinitionId -> both eso_id (NOT NULL, no default) and
     *                      eso_definition_id. It is now REQUIRED: eso_id has no
     *                      default, and an execution that names no ESO is not
     *                      an executable action (Invariant 3).
     *   created_by      -> dropped. There is no such column; executed_by is the
     *                      actor.
     *
     * The measurement plan is now a first-class row in hpbrain_measurement_plans
     * (2026_07_30_000100) rather than a string in the JSON blob, and this method
     * refuses to start a run without one — see measurementPlanFor().
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'decisionId' => ['required', 'string'],
            'esoDefinitionId' => ['required', 'string'],
            'executorType' => ['required', Rule::in(['human'])],  // EXECUTE dark in v1
            'executorId' => ['nullable', 'string'],
            'evidenceIds' => ['nullable', 'array'],
            'evidenceIds.*' => ['string'],
            'output' => ['nullable', 'array'],
            // The values for the inputs the ESO declares. Validated against the
            // definition's own `inputs` column below, not against a shape fixed
            // here — every ESO declares its own.
            'inputs' => ['nullable', 'array'],
            // An attestation, not a checkbox to be defaulted true. Preconditions
            // are prose about the world outside this system; the person starting
            // the run is the only thing that can confirm them, and their name is
            // stored with the confirmation.
            'preconditionsAcknowledged' => ['nullable', 'boolean'],
        ]);

        $tenant = $this->tenantId($request);
        $actor = $this->actorId($request);
        $now = now()->format('Y-m-d H:i:s');

        $definition = DB::table('hpbrain_eso_definitions')
            ->where('tenant_id', $tenant)
            ->where('id', $data['esoDefinitionId'])
            ->first();

        if (! $definition) {
            return response()->json(['error' => 'eso_not_found'], 422);
        }

        /*
          THE GATE THE UI ALSO READS. EsoPreflight is the single implementation
          of "may this run start": the library screen calls it through the
          readiness block on the definition payload to decide whether to offer a
          Run button, and it is called again here so that a client which skipped
          the screen, or held a stale one, is refused on the same terms.

          Enforced: the definition is in service and not superseded; the
          executor class is one the definition permits; every input the
          definition NAMES has a value. Attested rather than enforced: the
          preconditions, which no query over this database can confirm.
        */
        $blockers = EsoPreflight::blockersForRun(
            $definition,
            $data['executorType'],
            $data['inputs'] ?? [],
            (bool) ($data['preconditionsAcknowledged'] ?? false),
        );

        if ($blockers !== []) {
            return response()->json([
                'error' => 'eso_preconditions_unmet',
                'blockers' => $blockers,
            ], 422);
        }

        $decision = DB::table('hpbrain_decisions')
            ->where('tenant_id', $tenant)
            ->where('id', $data['decisionId'])
            ->first();

        if (! $decision) {
            return response()->json(['error' => 'decision_not_found'], 422);
        }

        if (strtolower((string) ($decision->status ?? '')) !== 'approved') {
            return response()->json(['error' => 'decision_not_approved'], 422);
        }

        $plan = $this->measurementPlanFor($tenant, $data['decisionId'], $now);

        if ($plan === null) {
            // Invariant 4: an action we cannot measure is an action we do not
            // take. Refusing here is the whole point — a run that starts
            // without a plan can only ever be evaluated by whoever ran it,
            // after the fact, against a standard they choose afterwards.
            return response()->json([
                'error' => 'measurement_plan_required',
                'reason' => 'No measurement plan for this decision pre-dates the execution.',
            ], 422);
        }

        $row = [
            'id' => Uuid::uuid4()->toString(),
            'tenant_id' => $tenant,
            'eso_id' => $data['esoDefinitionId'],
            'eso_definition_id' => $data['esoDefinitionId'],
            'decision_id' => $data['decisionId'],
            'executor_type' => $data['executorType'],
            'executed_by' => $data['executorId'] ?? $actor,
            'input' => json_encode([
                'measurementPlanId' => $plan->id,
                'baselineMetric' => $plan->baseline_metric,
                'measurementWindowDays' => $plan->measurement_window_days,
                'evidenceIds' => array_values($data['evidenceIds'] ?? []),
                // What the run was actually given, kept with the run. An
                // execution record that does not say what it was run with
                // cannot be reproduced or argued with afterwards.
                'inputs' => $data['inputs'] ?? [],
                'preconditionsAcknowledged' => (bool) ($data['preconditionsAcknowledged'] ?? false),
                'preconditionsAcknowledgedBy' => ($data['preconditionsAcknowledged'] ?? false) ? $actor : null,
            ]),
            'output' => array_key_exists('output', $data) ? json_encode($data['output']) : null,
            'status' => 'running',
            'started_date' => $now,
        ];

        // Golden path stage (9). correlation_id is the DECISION, inherited from
        // DecisionReached — this is the join that lets the audit trail follow
        // one case from signal through to outcome without guessing.
        $this->events->publishInTransaction(
            LoopEvent::EXECUTION_STARTED,
            $tenant,
            'EsoExecution',
            $actor,
            [
                'executionId' => $row['id'],
                'decisionId' => $row['decision_id'],
                'esoDefinitionId' => $row['eso_definition_id'],
                'executorType' => $row['executor_type'],
                'executedBy' => $row['executed_by'],
                'measurementPlanId' => $plan->id,
                'baselineMetric' => $plan->baseline_metric,
            ],
            fn () => ['entityId' => $row['id'], 'result' => $this->repository->insert($row)],
            correlationId: $row['decision_id'],
        );

        $this->linkExecutionEvidence($tenant, $row['id'], array_values($data['evidenceIds'] ?? []));

        return response()->json($this->repository->findById($tenant, $row['id']), 201);
    }

    /**
     * The measurement plan that authorises this run, or null.
     *
     * ORDERING, NOT MERE EXISTENCE. The query demands created_date <= the
     * execution's start. A plan that post-dates the run it supposedly governs
     * is a post-hoc justification — someone deciding after the fact what the
     * run was going to be judged against — and that is precisely what
     * Invariant 4 exists to prevent.
     *
     * The inline-creation compatibility path has been removed. A plan must now
     * be created via POST /measurement-plans before the execution is started.
     */
    private function measurementPlanFor(string $tenant, string $decisionId, string $startedAt): ?object
    {
        $plan = DB::table('hpbrain_measurement_plans')
            ->where('tenant_id', $tenant)
            ->where('decision_id', $decisionId)
            ->where('created_date', '<=', $startedAt)
            ->orderBy('created_date')
            ->first();

        if ($plan !== null) {
            return $plan;
        }

        // Nothing qualifies. Either no plan exists for this decision, or one
        // exists but post-dates the run — and a plan written after the run it
        // supposedly governs is a post-hoc justification, so both are refused
        // on the same terms rather than one being back-dated into validity.
        return null;
    }

    public function complete(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['completed', 'failed'])],
            'output' => ['nullable', 'array'],
            'error' => ['nullable', 'string'],
        ]);

        $tenant = $this->tenantId($request);
        $existing = $this->repository->findById($tenant, $id);

        // Previously this ran the UPDATE regardless. A wrong id, or an id from
        // another tenant, matched no row, and the caller was handed 200 with a
        // body of `null` — indistinguishable in the client from a transition
        // that worked, which is the worst possible answer about whether an
        // action in the world was recorded.
        if (! $existing) {
            return response()->json(['error' => 'eso_execution_not_found'], 404);
        }

        // A terminal state is the answer to "what did we actually do?". Letting
        // a completed run be re-transitioned to failed, or a rolled-back one
        // back to completed, makes the execution log editable, and an editable
        // log cannot answer that question at all. Rollback records a new
        // terminal state through its own route; it does not come through here.
        $current = strtolower((string) ($existing['status'] ?? ''));

        if (in_array($current, ['completed', 'failed', 'rolled_back'], true)) {
            return response()->json([
                'error' => 'execution_already_terminal',
                'reason' => 'This execution is already '.$current.'. A terminal execution is not re-transitioned.',
                'status' => $current,
            ], 422);
        }

        $fields = [
            'status' => $data['status'],
        ];

        if ($data['status'] === 'completed') {
            $fields['completed_date'] = now()->format('Y-m-d H:i:s');
        }

        if (array_key_exists('output', $data)) {
            $fields['output'] = json_encode($data['output']);
        }

        if (array_key_exists('error', $data)) {
            $fields['error'] = $data['error'];
        }

        return response()->json($this->repository->updateFields($tenant, $id, $fields));
    }

    /**
     * Every run of one ESO.
     *
     * MATCHES EITHER COLUMN. eso_definition_id was added later
     * (2026_01_01_003300) and is nullable; eso_id is the NOT NULL key every
     * writer sets. Filtering on eso_definition_id alone — which this did —
     * returned an empty history for any run recorded before that column
     * existed, reading on screen as "this ESO has never been run".
     */
    public function history(Request $request, string $tenantId, string $esoId): JsonResponse
    {
        $tenant = $this->tenantId($request);

        $rows = DB::table('hpbrain_eso_executions')
            ->where('tenant_id', $tenant)
            ->where(fn ($w) => $w->where('eso_id', $esoId)->orWhere('eso_definition_id', $esoId))
            ->orderByDesc('created_date')
            ->pluck('id');

        return response()->json(array_values(array_filter(array_map(
            fn ($id) => $this->repository->findById($tenant, (string) $id),
            $rows->all(),
        ))));
    }

    /**
     * Rollback records a NEW terminal state; it never rewrites the original
     * execution row. An execution log that can be edited cannot answer "what
     * did we actually do?", which is the only reason to keep one.
     */
    public function rollback(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:1']]);

        $tenant = $this->tenantId($request);
        $existing = $this->repository->findById($tenant, $id);

        if (! $existing) {
            return response()->json(['error' => 'eso_execution_not_found'], 404);
        }

        return response()->json($this->repository->updateFields($tenant, $id, [
            'status' => 'rolled_back',
            'rollback_reason' => $data['reason'],
        ]));
    }

    /** @param array<int, string> $evidenceIds */
    private function linkExecutionEvidence(string $tenant, string $executionId, array $evidenceIds): void
    {
        if ($evidenceIds === [] || ! Schema::hasTable('hpbrain_eso_execution_evidence')) {
            return;
        }

        $owned = DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenant)
            ->whereIn('id', $evidenceIds)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        foreach (array_unique($owned) as $evidenceId) {
            DB::table('hpbrain_eso_execution_evidence')->insertOrIgnore([
                'tenant_id' => $tenant,
                'execution_id' => $executionId,
                'evidence_id' => $evidenceId,
                'linked_date' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
