<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Task registry and sequence runner.
 *
 * The registry is declared here rather than stored, because a task is code: a
 * database row claiming a task exists when no handler backs it is worse than no
 * registry at all.
 */
final class TaskController extends Controller
{
    private const REGISTRY = [
        [
            'name'        => 'recompute-signal-confidence',
            'description' => 'Recomputes reasoning confidence for signals whose evidence changed.',
            'mutates'     => true,
        ],
        [
            'name'        => 'detect-recurrence',
            'description' => 'Flags signal classifications recurring after a resolved case.',
            'mutates'     => false,
        ],
        [
            'name'        => 'expire-stale-evidence',
            'description' => 'Marks evidence past its freshness horizon as stale.',
            'mutates'     => true,
        ],
    ];

    public function registry(): JsonResponse
    {
        return response()->json(self::REGISTRY);
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tasks'   => ['required', 'array', 'min:1'],
            'tasks.*' => ['required', 'string'],
        ]);

        $known = array_column(self::REGISTRY, 'name');
        $unknown = array_values(array_diff($data['tasks'], $known));

        // Fail before running anything. A partially-executed sequence where
        // step 3 was a typo is far harder to reason about than a clean refusal.
        if ($unknown !== []) {
            return response()->json(['error' => 'unknown_tasks', 'tasks' => $unknown], 422);
        }

        $tenant = $this->tenantId($request);
        $results = [];

        foreach ($data['tasks'] as $task) {
            $results[] = ['task' => $task, 'result' => $this->execute($task, $tenant)];
        }

        return response()->json(['ran' => count($results), 'results' => $results]);
    }

    private function execute(string $task, string $tenant): array
    {
        return match ($task) {
            'detect-recurrence' => [
                'recurringClassifications' => DB::table('hpbrain_signals')->where('tenant_id', $tenant)
                    ->select('classification', DB::raw('COUNT(*) as c'))
                    ->groupBy('classification')->having('c', '>', 1)->count(),
            ],
            'expire-stale-evidence' => [
                'marked' => DB::table('hpbrain_evidence')->where('tenant_id', $tenant)
                    ->where('status', 'active')
                    ->where('observed_date', '<', now()->subDays(365)->format('Y-m-d H:i:s'))
                    ->update(['status' => 'stale']),
            ],
            'recompute-signal-confidence' => [
                // Reported, not silently skipped: the recompute needs the verb
                // pipeline wired to grounding, which is not done yet.
                'status' => 'not_implemented',
                'reason' => 'requires VerbPipeline grounding to be wired (see docs/KNOWN_LIMITATIONS.md)',
            ],
            default => ['status' => 'no_handler'],
        };
    }
}
