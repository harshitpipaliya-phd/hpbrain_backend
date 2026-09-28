<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Evaluation;

use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\AiModelClient;
use App\Domain\AiIntelligence\Support\AiNotConfiguredException;
use App\Domain\AiIntelligence\Support\Platform;
use App\Domain\AiIntelligence\Templates\TemplateCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Runs an evaluation (hpbrain_ai_eval_runs + hpbrain_ai_eval_cases): a template,
 * a set of cases, and a score.
 *
 * Ported from G2G's EvaluationRunner. Scoring is deterministic substring
 * assertion — `expect_contains` / `expect_absent`, case-insensitive, all must
 * hold to pass — never a model grading a model. Each case is one call at
 * temperature 0, synchronously, bounded by MAX_CASES; a missing credential stops
 * the run rather than scoring 24 doomed calls. Billed to its own capability
 * (`evaluation_ai`) so measuring a template never spends the quota of the
 * capability under test.
 */
final class EvaluationRunner
{
    private const MODULE = 'evaluation_ai';

    public const MAX_CASES = 25;

    public function __construct(
        private readonly TemplateCatalog $templates,
        private readonly AiModelClient $models,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> The finished run row. */
    public function run(string $evaluationId, AiIntelligenceScope $scope): array
    {
        $tenantId = $scope->tenantId;

        $evaluation = DB::table('hpbrain_ai_eval_runs')
            ->where('id', $evaluationId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($evaluation === null) {
            throw new RuntimeException('That evaluation was not found.');
        }

        if ($evaluation->status === 'running') {
            throw new RuntimeException('This evaluation is already running.');
        }

        $template = $this->resolveTemplate($evaluation, $tenantId);

        $cases = DB::table('hpbrain_ai_eval_cases')
            ->where('evaluation_id', $evaluationId)
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('created_date')
            ->limit(self::MAX_CASES)
            ->get();

        if ($cases->isEmpty()) {
            throw new RuntimeException('This evaluation has no cases. Add at least one before running it.');
        }

        $this->markRunning($evaluationId, $tenantId);
        $started = microtime(true);

        $passed = 0;
        $scores = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $provider = null;
        $model = null;
        $fatal = null;

        foreach ($cases as $case) {
            $outcome = $this->runCase($case, $template, $scope, $evaluationId);

            DB::table('hpbrain_ai_eval_cases')
                ->where('id', $case->id)
                ->where('tenant_id', $tenantId)
                ->update([
                    'output' => $outcome['output'],
                    'score' => $outcome['score'],
                    'passed' => $outcome['passed'],
                    'verdict' => $outcome['verdict'],
                    'input_tokens' => $outcome['input_tokens'],
                    'output_tokens' => $outcome['output_tokens'],
                    'latency_ms' => $outcome['latency_ms'],
                    'error' => $outcome['error'],
                    'updated_date' => Platform::now(),
                ]);

            if ($outcome['passed']) {
                $passed++;
            }

            if ($outcome['score'] !== null) {
                $scores[] = $outcome['score'];
            }

            $inputTokens += (int) $outcome['input_tokens'];
            $outputTokens += (int) $outcome['output_tokens'];
            $provider ??= $outcome['provider'];
            $model ??= $outcome['model'];

            if ($outcome['not_configured']) {
                $fatal = $outcome['error'];
                break;
            }
        }

        $total = $cases->count();
        $now = Platform::now();

        $finished = [
            'status' => $fatal === null ? 'completed' : 'failed',
            'case_count' => $total,
            'passed_count' => $passed,
            'failed_count' => $total - $passed,
            'score' => $scores === [] ? null : round(array_sum($scores) / count($scores), 4),
            'total_input_tokens' => $inputTokens,
            'total_output_tokens' => $outputTokens,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'provider' => $provider,
            'model' => $model,
            'error' => $fatal,
            'finished_at' => $now,
            'updated_date' => $now,
        ];

        DB::table('hpbrain_ai_eval_runs')->where('id', $evaluationId)->where('tenant_id', $tenantId)->update($finished);

        $this->audit->record('ai.evaluation.run', $scope, [
            'related_type' => 'hpbrain_ai_eval_runs',
            'related_id' => $evaluationId,
            'outcome' => $fatal === null ? 'success' : 'failure',
            'message' => sprintf(
                'Evaluation "%s": %d of %d cases passed%s.',
                $evaluation->name,
                $passed,
                $total,
                $finished['score'] === null ? '' : sprintf(', score %.2f', $finished['score'])
            ),
            'payload' => [
                'provider' => $provider,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'duration_ms' => $finished['duration_ms'],
            ],
        ]);

        $row = DB::table('hpbrain_ai_eval_runs')->where('id', $evaluationId)->where('tenant_id', $tenantId)->first();

        return $row === null ? [] : (array) $row;
    }

    /** @return array<string, mixed> */
    private function runCase(object $case, array $template, AiIntelligenceScope $scope, string $evaluationId): array
    {
        $rendered = $this->templates->preview(
            (string) ($template['system_prompt'] ?? ''),
            (string) ($template['user_prompt'] ?? ''),
            Platform::decode($case->variables)
        );

        $messages = [];

        if ($rendered['system'] !== null && trim($rendered['system']) !== '') {
            $messages[] = ['role' => 'system', 'content' => $rendered['system']];
        }

        $messages[] = ['role' => 'user', 'content' => $rendered['user']];

        try {
            $completion = $this->models->complete(
                self::MODULE,
                $messages,
                [
                    // Zero: the same input must produce the same score.
                    'temperature' => 0.0,
                    'max_tokens' => (int) ($template['max_tokens'] ?? 1024) ?: 1024,
                    'related_type' => 'hpbrain_ai_eval_runs',
                    'related_id' => $evaluationId,
                    'user_id' => $scope->userId,
                ],
                $scope->tenantId
            );
        } catch (AiNotConfiguredException $exception) {
            return $this->caseFailure($exception->getMessage(), notConfigured: true);
        } catch (Throwable $exception) {
            return $this->caseFailure($exception->getMessage(), notConfigured: false);
        }

        $verdict = $this->score(
            $completion->text,
            Platform::decode($case->expect_contains),
            Platform::decode($case->expect_absent)
        );

        return [
            'output' => $completion->text,
            'score' => $verdict['score'],
            'passed' => $verdict['passed'],
            'verdict' => $verdict['verdict'],
            'input_tokens' => $completion->inputTokens,
            'output_tokens' => $completion->outputTokens,
            'latency_ms' => $completion->latencyMs,
            'error' => null,
            'provider' => $completion->provider,
            'model' => $completion->model,
            'not_configured' => false,
        ];
    }

    /**
     * Fraction of assertions met; passing needs all of them. A case with no
     * assertions scores 1.0 and the verdict says it measured nothing.
     *
     * @param  array<int, mixed>  $mustContain
     * @param  array<int, mixed>  $mustNotContain
     * @return array{score: float, passed: bool, verdict: string}
     */
    public function score(string $output, array $mustContain, array $mustNotContain): array
    {
        $haystack = mb_strtolower($output);
        $checks = 0;
        $met = 0;
        $failures = [];

        foreach ($mustContain as $needle) {
            $needle = trim((string) $needle);

            if ($needle === '') {
                continue;
            }

            $checks++;

            if (str_contains($haystack, mb_strtolower($needle))) {
                $met++;
            } else {
                $failures[] = "missing \"{$needle}\"";
            }
        }

        foreach ($mustNotContain as $needle) {
            $needle = trim((string) $needle);

            if ($needle === '') {
                continue;
            }

            $checks++;

            if (str_contains($haystack, mb_strtolower($needle))) {
                $failures[] = "contains \"{$needle}\", which it must not";
            } else {
                $met++;
            }
        }

        if ($checks === 0) {
            return [
                'score' => 1.0,
                'passed' => true,
                'verdict' => 'No assertions on this case, so nothing was checked. '
                    . 'Add expected or forbidden phrases to make it measure something.',
            ];
        }

        return [
            'score' => round($met / $checks, 4),
            'passed' => $met === $checks,
            'verdict' => $failures === []
                ? sprintf('All %d assertions met.', $checks)
                : sprintf('%d of %d met. Failed: %s.', $met, $checks, implode('; ', $failures)),
        ];
    }

    /** @return array<string, mixed> */
    private function caseFailure(string $message, bool $notConfigured): array
    {
        return [
            'output' => null,
            'score' => null,
            'passed' => false,
            'verdict' => 'Not scored — the call failed.',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'latency_ms' => 0,
            'error' => $message,
            'provider' => null,
            'model' => null,
            'not_configured' => $notConfigured,
        ];
    }

    /**
     * The template under test, pinned to the version the evaluation names — the
     * tenant's own copy beats the platform baseline, newest version otherwise.
     *
     * @return array<string, mixed>
     */
    private function resolveTemplate(object $evaluation, string $tenantId): array
    {
        $key = trim((string) ($evaluation->template_key ?? ''));

        if ($key === '') {
            throw new RuntimeException('This evaluation names no template to test.');
        }

        $row = Platform::visible(DB::table('hpbrain_ai_templates')->where('template_key', $key), $tenantId)
            ->when(
                $evaluation->template_version !== null,
                fn ($q) => $q->where('version', $evaluation->template_version)
            )
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
            ->orderByDesc('version')
            ->first();

        if ($row === null) {
            throw new RuntimeException(sprintf('Template "%s" could not be found.', $key));
        }

        return [
            'system_prompt' => $row->system_prompt,
            'user_prompt' => $row->user_prompt,
            'max_tokens' => $row->max_tokens,
            'version' => (int) $row->version,
        ];
    }

    private function markRunning(string $evaluationId, string $tenantId): void
    {
        $now = Platform::now();

        DB::table('hpbrain_ai_eval_runs')->where('id', $evaluationId)->where('tenant_id', $tenantId)->update([
            'status' => 'running',
            'started_at' => $now,
            'finished_at' => null,
            'error' => null,
            'updated_date' => $now,
        ]);
    }
}
