<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Evaluation\EvaluationRunner;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\Platform;
use App\Domain\AiIntelligence\Templates\TemplateCatalog;
use App\Domain\AiIntelligence\Templates\TemplateModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AI Evaluation — test sets and scores for a template.
 *
 * Ported from G2G's EvaluationController onto hpbrain_ai_eval_runs /
 * hpbrain_ai_eval_cases (hpbrain_ai_evaluations already exists and belongs to the
 * older /ai/evaluations screens). Running is synchronous and bounded by
 * EvaluationRunner::MAX_CASES.
 */
final class AiIntelligenceEvaluationController extends AiIntelligenceController
{
    public function __construct(
        private readonly EvaluationRunner $runner,
        private readonly TemplateCatalog $templates,
        private readonly AiAuditLogger $audit,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            // One entry per template_key. The platform row, this organisation's
            // override and older versions can all be published under one key, and
            // an evaluation is created against the key: listing each would offer
            // several indistinguishable choices. The organisation's own copy wins,
            // then the highest version — the one a run would actually render.
            $byKey = [];
            foreach ($this->templates->forModule(null, $tenantId) as $template) {
                if ($template['status'] !== 'published') {
                    continue;
                }
                $key = $template['template_key'];
                $current = $byKey[$key] ?? null;
                $rank = fn (array $t) => [empty($t['is_platform']) ? 1 : 0, (int) $t['version']];
                if ($current === null || $rank($template) > $rank($current)) {
                    $byKey[$key] = $template;
                }
            }
            $templates = array_values($byKey);

            return $this->success('Evaluation options resolved.', [
                'templates' => array_map(fn ($template) => [
                    'template_key' => $template['template_key'],
                    'name' => $template['name'],
                    'version' => $template['version'],
                    'module_key' => $template['module_key'],
                    'module_label' => $template['module_label'],
                    'grounding_variables' => $template['grounding_variables'],
                ], $templates),
                'max_cases' => EvaluationRunner::MAX_CASES,
                'statuses' => ['draft', 'running', 'completed', 'failed'],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $rows = DB::table('hpbrain_ai_eval_runs')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('created_date')
                ->limit($this->limit($request, 50, 200))
                ->get();

            return $this->success('Evaluations resolved.', [
                'tenant_id' => $tenantId,
                'evaluations' => $rows->map(fn ($row) => $this->present($row))->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function show(Request $request, string $evaluation): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $row = $this->owned($evaluation, $tenantId);

            if ($row === null) {
                return $this->failure('That evaluation was not found.', 404);
            }

            $cases = DB::table('hpbrain_ai_eval_cases')
                ->where('evaluation_id', $evaluation)
                ->where('tenant_id', $tenantId)
                ->orderBy('sort_order')
                ->orderBy('created_date')
                ->get();

            return $this->success('Evaluation resolved.', [
                'evaluation' => $this->present($row),
                'cases' => $cases->map(fn ($case) => [
                    'id' => (string) $case->id,
                    'label' => (string) $case->label,
                    'variables' => Platform::decode($case->variables),
                    'expect_contains' => Platform::decode($case->expect_contains),
                    'expect_absent' => Platform::decode($case->expect_absent),
                    'output' => $case->output === null ? null : (string) $case->output,
                    'score' => $case->score === null ? null : (float) $case->score,
                    'passed' => $case->passed === null ? null : (bool) $case->passed,
                    'verdict' => $case->verdict === null ? null : (string) $case->verdict,
                    'input_tokens' => $case->input_tokens === null ? null : (int) $case->input_tokens,
                    'output_tokens' => $case->output_tokens === null ? null : (int) $case->output_tokens,
                    'latency_ms' => $case->latency_ms === null ? null : (int) $case->latency_ms,
                    'error' => $case->error === null ? null : (string) $case->error,
                ])->all(),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            $validated = $request->validate([
                'name' => 'required|string|max:200',
                'description' => 'nullable|string|max:2000',
                'template_key' => 'required|string|max:120',
                'template_version' => 'nullable|integer|min:1',
                'cases' => 'required|array|min:1|max:' . EvaluationRunner::MAX_CASES,
                'cases.*.label' => 'required|string|max:200',
                'cases.*.variables' => 'nullable|array',
                'cases.*.expect_contains' => 'nullable|array',
                'cases.*.expect_contains.*' => 'string|max:500',
                'cases.*.expect_absent' => 'nullable|array',
                'cases.*.expect_absent.*' => 'string|max:500',
            ]);

            $template = $this->visibleTemplate($validated['template_key'], $tenantId);

            if ($template === null) {
                return $this->failure('That template could not be found.', 404);
            }

            $now = Platform::now();
            $id = Platform::id();

            DB::transaction(function () use ($validated, $template, $scope, $tenantId, $now, $id) {
                DB::table('hpbrain_ai_eval_runs')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'name' => trim($validated['name']),
                    'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
                    'template_key' => $validated['template_key'],
                    'template_version' => $validated['template_version'] ?? null,
                    'module_key' => $template['module_key'] === TemplateModuleCatalog::SHARED ? null : $template['module_key'],
                    'status' => 'draft',
                    'case_count' => count($validated['cases']),
                    'passed_count' => 0,
                    'failed_count' => 0,
                    'total_input_tokens' => 0,
                    'total_output_tokens' => 0,
                    'created_by' => $scope->userId,
                    'created_date' => $now,
                    'updated_date' => $now,
                ]);

                foreach (array_values($validated['cases']) as $index => $case) {
                    DB::table('hpbrain_ai_eval_cases')->insert([
                        'id' => Platform::id(),
                        'tenant_id' => $tenantId,
                        'evaluation_id' => $id,
                        'label' => trim($case['label']),
                        'variables' => Platform::encode($case['variables'] ?? []),
                        'expect_contains' => Platform::encode($case['expect_contains'] ?? []),
                        'expect_absent' => Platform::encode($case['expect_absent'] ?? []),
                        'sort_order' => $index,
                        'created_date' => $now,
                        'updated_date' => $now,
                    ]);
                }
            });

            $this->audit->record('ai.evaluation.created', $scope, [
                'related_type' => 'hpbrain_ai_eval_runs',
                'related_id' => $id,
                'message' => sprintf(
                    'Evaluation "%s" created with %d cases against %s.',
                    trim($validated['name']),
                    count($validated['cases']),
                    $validated['template_key']
                ),
            ]);

            return $this->success('Evaluation saved.', [
                'evaluation' => $this->present($this->owned($id, $tenantId)),
            ], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function run(Request $request, string $evaluation): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            if ($this->owned($evaluation, $scope->tenantId) === null) {
                return $this->failure('That evaluation was not found.', 404);
            }

            $result = $this->runner->run($evaluation, $scope);

            return $this->success(
                ($result['status'] ?? '') === 'completed' ? 'Evaluation complete.' : 'Evaluation finished with failures.',
                ['evaluation' => $this->present((object) $result)]
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** A hard delete, as in G2G: an evaluation is a measurement nothing points at. */
    public function destroy(Request $request, string $evaluation): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenantId = $scope->tenantId;

            $row = $this->owned($evaluation, $tenantId);

            if ($row === null) {
                return $this->failure('That evaluation was not found.', 404);
            }

            DB::transaction(function () use ($evaluation, $tenantId) {
                DB::table('hpbrain_ai_eval_cases')->where('evaluation_id', $evaluation)->where('tenant_id', $tenantId)->delete();
                DB::table('hpbrain_ai_eval_runs')->where('id', $evaluation)->where('tenant_id', $tenantId)->delete();
            });

            $this->audit->record('ai.evaluation.deleted', $scope, [
                'related_type' => 'hpbrain_ai_eval_runs',
                'related_id' => $evaluation,
                'message' => sprintf('Evaluation "%s" deleted.', $row->name),
            ]);

            return $this->success('Evaluation deleted.', ['id' => $evaluation]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    private function owned(string $id, string $tenantId): ?object
    {
        return DB::table('hpbrain_ai_eval_runs')->where('id', $id)->where('tenant_id', $tenantId)->first();
    }

    /** @return array<string, mixed>|null */
    private function visibleTemplate(string $key, string $tenantId): ?array
    {
        foreach ($this->templates->forModule(null, $tenantId) as $template) {
            if ($template['template_key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function present(?object $row): array
    {
        if ($row === null || ! isset($row->id)) {
            return [];
        }

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'description' => $row->description === null ? null : (string) $row->description,
            'template_key' => $row->template_key === null ? null : (string) $row->template_key,
            'template_version' => $row->template_version === null ? null : (int) $row->template_version,
            'module_key' => $row->module_key === null ? null : (string) $row->module_key,
            'provider' => $row->provider === null ? null : (string) $row->provider,
            'model' => $row->model === null ? null : (string) $row->model,
            'status' => (string) $row->status,
            'case_count' => (int) $row->case_count,
            'passed_count' => (int) $row->passed_count,
            'failed_count' => (int) $row->failed_count,
            'score' => $row->score === null ? null : (float) $row->score,
            'total_input_tokens' => (int) $row->total_input_tokens,
            'total_output_tokens' => (int) $row->total_output_tokens,
            'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
            'error' => $row->error === null ? null : (string) $row->error,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
            'created_at' => $row->created_date === null ? null : (string) $row->created_date,
        ];
    }
}
