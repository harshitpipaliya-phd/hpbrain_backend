<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Proactive reasoning surfaces. Each finding is derived from the loop tables at
 * request time and states its own basis, so a user can always ask "why is this
 * on my list?" and get an answer from data rather than a heuristic they cannot
 * inspect.
 *
 * All three endpoints answer with an envelope {count, findings}. Both callers —
 * the Executive Dashboard's data-quality tiles and the Command Center — read
 * `.count`, which a bare array does not carry: every alert tile rendered empty
 * and, being empty rather than zero, read as "no data collected" instead of
 * "nothing wrong". The findings themselves are unchanged and still carry their
 * own `basis`.
 */
final class ReasoningEngineController extends Controller
{
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
}
