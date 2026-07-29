<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Task registry and sequence runner.
 *
 * The registry is declared here rather than stored, because a task is code: a
 * database row claiming a task exists when no handler backs it is worse than no
 * registry at all.
 *
 * The runner accepts the request the Task Orchestrator screen actually sends —
 * {steps: [{taskName, input?, maxRetries?}], stopOnFailure} — and answers with
 * {steps: [{taskName, status, output, error, attempts}], allSucceeded}. It
 * previously required a flat `tasks: string[]`, so every run from the UI came
 * back 422 "The tasks field is required" and the screen could not run a single
 * task. The flat form is still accepted; both are normalised below.
 */
final class TaskController extends Controller
{
    /** Retries a step gets before it is reported failed, when the caller names none. */
    private const DEFAULT_MAX_RETRIES = 0;

    private const REGISTRY = [
        [
            'name'        => 'recompute-signal-confidence',
            'description' => 'Recomputes reasoning confidence for signals whose evidence changed.',
            'category'    => 'reasoning',
            'mutates'     => true,
        ],
        [
            'name'        => 'detect-recurrence',
            'description' => 'Flags signal classifications recurring after a resolved case.',
            'category'    => 'detection',
            'mutates'     => false,
        ],
        [
            'name'        => 'expire-stale-evidence',
            'description' => 'Marks evidence past its freshness horizon as stale.',
            'category'    => 'maintenance',
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
            'steps'             => ['sometimes', 'array', 'min:1'],
            'steps.*.taskName'  => ['required_with:steps', 'string'],
            'steps.*.input'     => ['sometimes', 'array'],
            'steps.*.maxRetries' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'tasks'             => ['sometimes', 'array', 'min:1'],
            'tasks.*'           => ['required_with:tasks', 'string'],
            'stopOnFailure'     => ['sometimes', 'boolean'],
        ]);

        // Normalise both accepted request forms to one list of steps.
        $steps = $data['steps'] ?? array_map(fn (string $name) => ['taskName' => $name], $data['tasks'] ?? []);

        if ($steps === []) {
            return response()->json(['error' => 'no_steps', 'message' => 'Provide steps[] or tasks[].'], 422);
        }

        $known = array_column(self::REGISTRY, 'name');
        $unknown = array_values(array_diff(array_column($steps, 'taskName'), $known));

        // Fail before running anything. A partially-executed sequence where
        // step 3 was a typo is far harder to reason about than a clean refusal.
        if ($unknown !== []) {
            return response()->json(['error' => 'unknown_tasks', 'tasks' => $unknown], 422);
        }

        $tenant        = $this->tenantId($request);
        $stopOnFailure = (bool) ($data['stopOnFailure'] ?? true);
        $results       = [];

        foreach ($steps as $step) {
            $name       = (string) $step['taskName'];
            $maxRetries = (int) ($step['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);
            $attempts   = 0;
            $output     = null;
            $error      = null;

            // Attempts are counted and reported rather than hidden: a task that
            // succeeded on the third try is a different fact from one that
            // succeeded outright, and the screen shows both.
            while ($attempts <= $maxRetries) {
                $attempts++;

                try {
                    $output = $this->execute($name, $tenant);
                    $error  = null;
                    break;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }

            $results[] = [
                'taskName' => $name,
                'status'   => $error === null ? 'completed' : 'failed',
                'output'   => $output,
                'error'    => $error,
                'attempts' => $attempts,
            ];

            if ($error !== null && $stopOnFailure) {
                break;
            }
        }

        return response()->json([
            'steps'         => $results,
            'allSucceeded'  => ! in_array('failed', array_column($results, 'status'), true),
            'ran'           => count($results),
            // The flat shape this endpoint published before.
            'results'       => array_map(fn (array $r) => ['task' => $r['taskName'], 'result' => $r['output']], $results),
        ]);
    }

    /** @return array<string, mixed> */
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