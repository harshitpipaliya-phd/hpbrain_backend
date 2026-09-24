<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Learning\MemoryGrounding;
use App\Domain\Undetermined\VerbResult;
use App\Domain\Verbs\AssessVerb;
use App\Domain\Verbs\EvaluateVerb;
use App\Domain\Verbs\ExplainVerb;
use App\Domain\Verbs\RecommendVerb;
use App\Domain\Verbs\Verb;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Proactive reasoning surfaces. Each finding is derived from the loop tables at
 * request time and states its own basis, so a user can always ask "why is this
 * on my list?" and get an answer from data rather than a heuristic they cannot
 * inspect.
 *
 * The three finding endpoints answer with an envelope {count, findings}. Both
 * callers — the Executive Dashboard's data-quality tiles and the Command
 * Center — read `.count`, which a bare array does not carry: every alert tile
 * rendered empty and, being empty rather than zero, read as "no data collected"
 * instead of "nothing wrong". The findings themselves are unchanged and still
 * carry their own `basis`.
 *
 * explain() and assess() are new and additive; no existing key changes shape.
 * They are the HTTP edge of the verb pipeline — the reasoning lives in
 * App\Domain\Verbs, which is testable without a request.
 */
final class ReasoningEngineController extends Controller
{
    public function __construct(
        private readonly ExplainVerb $explain,
        private readonly AssessVerb $assess,
        private readonly RecommendVerb $recommend,
        private readonly EvaluateVerb $evaluate,
        private readonly MemoryGrounding $memory,
    ) {
    }
    /** @param array<int, array<string, mixed>>|\Illuminate\Support\Collection $findings */
    private function envelope(string $finding, $findings): JsonResponse
    {
        $list = is_array($findings) ? $findings : $findings->all();

        return response()->json([
            'finding'  => $finding,
            'count'    => count($list),
            'findings' => array_values($list),
        ]);
    }

    /** Signals that have produced no evidence — the Brain is guessing. */
    public function missingEvidence(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);

        $rows = DB::table('hpbrain_signals as s')
            ->leftJoin('hpbrain_evidence as e', function ($j) use ($tenant) {
                $j->on('e.signal_id', '=', 's.id')->where('e.tenant_id', '=', $tenant);
            })
            ->where('s.tenant_id', $tenant)
            ->whereNull('e.id')
            ->select('s.id', 's.classification', 's.priority', 's.created_date')
            ->orderByDesc('s.created_date')->limit(100)->get();

        return $this->envelope('signal_without_evidence', $rows->map(fn ($r) => (array) $r + [
            'finding' => 'signal_without_evidence',
            'basis'   => 'No evidence row references this signal.',
        ]));
    }

    /** Same classification and source recurring — likely one problem, not many. */
    public function duplicateSignals(Request $request): JsonResponse
    {
        $rows = DB::table('hpbrain_signals')
            ->where('tenant_id', $this->tenantId($request))
            ->select('classification', 'source', DB::raw('COUNT(*) as occurrences'),
                     DB::raw('MIN(created_date) as first_seen'), DB::raw('MAX(created_date) as last_seen'))
            ->groupBy('classification', 'source')
            ->having('occurrences', '>', 1)
            ->orderByDesc('occurrences')->limit(50)->get();

        return $this->envelope('recurring_signal', $rows->map(fn ($r) => (array) $r + [
            'finding' => 'recurring_signal',
            'basis'   => 'Same classification and source recorded more than once.',
        ]));
    }

    /**
     * Recurrence AFTER a successful intervention — the fix did not hold.
     * This is the finding that most often matters: it means the root cause was
     * misdiagnosed, not that the execution failed.
     */
    public function earlyWarnings(Request $request): JsonResponse
    {
        $tenant = $this->tenantId($request);

        $resolved = DB::table('hpbrain_cases')
            ->where('tenant_id', $tenant)->whereIn('status', ['resolved', 'closed'])
            ->whereNotNull('signal_id')
            ->select('id', 'signal_id', 'title', 'updated_date', 'created_date')->get();

        $warnings = [];

        foreach ($resolved as $case) {
            $signal = DB::table('hpbrain_signals')
                ->where('tenant_id', $tenant)->where('id', $case->signal_id)->first();

            if (! $signal) {
                continue;
            }

            $since = $case->updated_date ?? $case->created_date;

            $recurrence = DB::table('hpbrain_signals')
                ->where('tenant_id', $tenant)
                ->where('classification', $signal->classification)
                ->where('id', '!=', $signal->id)
                ->where('created_date', '>', $since)
                ->count();

            if ($recurrence > 0) {
                $warnings[] = [
                    'finding'        => 'recurrence_after_resolution',
                    'caseId'         => $case->id,
                    'caseTitle'      => $case->title,
                    'classification' => $signal->classification,
                    'recurrences'    => $recurrence,
                    'basis'          => 'Signals of this classification reappeared after the case was resolved.',
                ];
            }
        }

        usort($warnings, fn ($a, $b) => $b['recurrences'] <=> $a['recurrences']);

        return $this->envelope('recurrence_after_resolution', $warnings);
    }

    // ---- The verb pipeline (ADR-004) ---------------------------------------

    /**
     * EXPLAIN a signal: why is this happening, and on what basis?
     *
     * POST because the question arrives as a body, and — unlike policy
     * evaluation, the other read-gated POST in this API — it does write: one
     * LearningGrounded event per learning it consults. That is traceability,
     * not domain state, and gating it on `update` would stop a Viewer from ever
     * asking why, which is the one question this product exists to answer. The
     * writes it produces are bounded: EventPublisher's idempotency key means
     * repeatedly asking about the same signal appends nothing new.
     */
    public function explain(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate(['signalId' => ['required', 'string', 'size:36']]);

        return $this->runVerb(Verb::EXPLAIN, fn () => $this->explain->run(
            $this->tenantId($request),
            $data['signalId'],
            $this->actorId($request),
            (string) $request->attributes->get('auth.role'),
        ));
    }

    /** ASSESS a capability assignment against the capability's stated targets. */
    public function assess(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate([
            'assignmentId' => ['required', 'string', 'size:36'],
            'capabilityId' => ['required', 'string', 'size:36'],
        ]);

        return $this->runVerb(Verb::ASSESS, fn () => $this->assess->run(
            $this->tenantId($request),
            $data['assignmentId'],
            $data['capabilityId'],
            $this->actorId($request),
            (string) $request->attributes->get('auth.role'),
        ));
    }

    /**
     * RECOMMEND a course of action for a signal. Writes hpbrain_recommendations.
     *
     * Unlike explain/assess this is gated on `permission:create` at the route,
     * because it creates rows and spends money — and the verb re-checks the
     * same permission in its governance callable, so calling the service
     * directly cannot bypass it.
     */
    public function recommend(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate(['signalId' => ['required', 'string', 'size:36']]);

        return $this->runVerb(Verb::RECOMMEND, fn () => $this->recommend->run(
            $this->tenantId($request),
            $data['signalId'],
            $this->actorId($request),
            (string) $request->attributes->get('auth.role'),
        ));
    }

    /** EVALUATE the strength of the case for a signal. Writes a reasoning step. */
    public function evaluate(Request $request, string $tenantId): JsonResponse
    {
        $data = $request->validate(['signalId' => ['required', 'string', 'size:36']]);

        return $this->runVerb(Verb::EVALUATE, fn () => $this->evaluate->run(
            $this->tenantId($request),
            $data['signalId'],
            $this->actorId($request),
            (string) $request->attributes->get('auth.role'),
        ));
    }

    /** Is organizational memory compounding? (EB-DP Ch.15 KPI, ADR-005.) */
    public function memoryStats(Request $request, string $tenantId): JsonResponse
    {
        return response()->json($this->memory->compoundingStats($this->tenantId($request)));
    }

    /**
     * VerbResult serialises itself with `state`, `evidenceRefs` and — when
     * UNDETERMINED — `gaps`, so a caller can always answer "what was this
     * grounded on?" and "what is missing?" (Invariant 2, Principle 1). Only the
     * verb name is added here.
     *
     * UNDETERMINED returns HTTP 200: it is a successful response carrying an
     * honest result. Returning 4xx would train the SPA to treat honesty as
     * failure. Governance denial is the genuine 403.
     *
     * @param  callable():VerbResult  $run
     */
    private function runVerb(Verb $verb, callable $run): JsonResponse
    {
        try {
            $result = $run();
        } catch (RuntimeException $e) {
            // VerbPipeline throws for a denied governance pre-check and for the
            // dark EXECUTE verb. Both are refusals, not faults.
            return response()->json(['error' => 'verb_refused', 'reason' => $e->getMessage()], 403);
        }

        return response()->json(['verb' => $verb->value] + $result->jsonSerialize());
    }
}
