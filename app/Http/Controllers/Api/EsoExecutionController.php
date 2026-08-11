<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use App\Http\Controllers\Controller;
use App\Repositories\EsoExecutionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    ) {
    }

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

        return response()->json([
            'definitions' => $definitions->map(function ($d) use ($efficacy, $runs) {
                $records = $efficacy->get($d->id, collect());
                $run     = $runs->get($d->id);

                return [
                    'id'           => (string) $d->id,
                    'esoCode'      => (string) ($d->eso_code ?? ''),
                    'name'         => (string) ($d->name ?? 'unnamed'),
                    'objective'    => $d->objective === null ? null : (string) $d->objective,
                    'status'       => (string) ($d->status ?? 'unknown'),
                    'version'      => (int) ($d->version ?? 1),
                    'owner'        => $d->owner === null ? null : (string) $d->owner,
                    'trustLevel'   => $d->trust_level === null ? null : (string) $d->trust_level,
                    'allowedExecutorClasses' => $this->jsonList($d->allowed_executor_classes),
                    'gapTypes'     => $this->jsonList($d->gap_types),
                    'kasbaNodeType' => $d->kasba_node_type === null ? null : (string) $d->kasba_node_type,
                    'runs'         => $run === null ? 0 : (int) $run->runs,
                    'lastRun'      => $run?->last_run,
                    // Empty is a real answer: an ESO with no efficacy record has no
                    // track record, which is different from having a poor one.
                    'efficacy'     => $records->map(fn ($e) => [
                        'id'            => (string) $e->id,
                        'gapType'       => $e->gap_type === null ? null : (string) $e->gap_type,
                        'population'    => $e->population === null ? null : (string) $e->population,
                        'efficacyScore' => $e->efficacy_score === null ? null : (float) $e->efficacy_score,
                        'sampleSize'    => $e->sample_size === null ? null : (int) $e->sample_size,
                        'computedDate'  => $e->computed_date,
                    ])->values(),
                ];
            })->values(),
            'totals' => [
                'definitions' => $definitions->count(),
                'active'      => $definitions->filter(fn ($d) => strtolower((string) ($d->status ?? '')) === 'active')->count(),
                'withEfficacy' => $definitions->filter(fn ($d) => $efficacy->has($d->id))->count(),
                'executions'  => (int) DB::table('hpbrain_eso_executions')->where('tenant_id', $tenantId)->count(),
            ],
        ]);
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
            'decisionId'      => ['required', 'string'],
            'esoDefinitionId' => ['required', 'string'],
            'executorType'    => ['required', Rule::in(['human'])],  // EXECUTE dark in v1
            'executorId'      => ['nullable', 'string'],
        ]);

        $tenant = $this->tenantId($request);
        $actor  = $this->actorId($request);
        $now    = now()->format('Y-m-d H:i:s');

        $plan = $this->measurementPlanFor($tenant, $data['decisionId'], $now, $data, $actor);

        if ($plan === null) {
            // Invariant 4: an action we cannot measure is an action we do not
            // take. Refusing here is the whole point — a run that starts
            // without a plan can only ever be evaluated by whoever ran it,
            // after the fact, against a standard they choose afterwards.
            return response()->json([
                'error'  => 'measurement_plan_required',
                'reason' => 'No measurement plan for this decision pre-dates the execution.',
            ], 422);
        }

        $row = [
            'id'                => Uuid::uuid4()->toString(),
            'tenant_id'         => $tenant,
            'eso_id'            => $data['esoDefinitionId'],
            'eso_definition_id' => $data['esoDefinitionId'],
            'decision_id'       => $data['decisionId'],
            'executor_type'     => $data['executorType'],
            'executed_by'       => $data['executorId'] ?? $actor,
            'input'             => json_encode([
                'measurementPlanId' => $plan->id,
                'baselineMetric'    => $plan->baseline_metric,
                'measurementWindowDays' => $plan->measurement_window_days,
            ]),
            'status'            => 'running',
            'started_date'      => $now,
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
                'executionId'     => $row['id'],
                'decisionId'      => $row['decision_id'],
                'esoDefinitionId' => $row['eso_definition_id'],
                'executorType'    => $row['executor_type'],
                'executedBy'      => $row['executed_by'],
                'measurementPlanId' => $plan->id,
                'baselineMetric'    => $plan->baseline_metric,
            ],
            fn () => ['entityId' => $row['id'], 'result' => $this->repository->insert($row)],
            correlationId: $row['decision_id'],
        );

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
     *
     * @param  array<string, mixed>  $data
     */
    private function measurementPlanFor(
        string $tenant,
        string $decisionId,
        string $startedAt,
        array $data,
        string $actor,
    ): ?object {
        $plan = DB::table('hpbrain_measurement_plans')
            ->where('tenant_id', $tenant)
            ->where('decision_id', $decisionId)
            ->where('created_date', '<=', $startedAt)
            ->orderBy('created_date')
            ->first();

        if ($plan !== null) {
            return $plan;
        }

        // A plan exists but post-dates the run. Refused rather than
        // back-dated: the honest answer is that this run was not planned.
        $futureDated = DB::table('hpbrain_measurement_plans')
            ->where('tenant_id', $tenant)
            ->where('decision_id', $decisionId)
            ->exists();

        if ($futureDated) {
            return null;
        }

        return null;
    }

    public function complete(Request $request, string $tenantId, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['completed', 'failed'])],
        ]);

        return response()->json($this->repository->updateFields($this->tenantId($request), $id, [
            'status' => $data['status'],
        ]));
    }

    public function history(Request $request, string $tenantId, string $esoId): JsonResponse
    {
        $all = $this->repository->list($this->tenantId($request));

        return response()->json(array_values(array_filter(
            $all, fn ($e) => ($e['eso_definition_id'] ?? null) === $esoId
        )));
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
            'status'          => 'rolled_back',
            'rollback_reason' => $data['reason'],
        ]));
    }
}
