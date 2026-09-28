<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Reports\ModuleDataSourceCatalog;
use App\Domain\AiIntelligence\Reports\OrganisationBranding;
use App\Domain\AiIntelligence\Reports\ReportLayoutRenderer;
use App\Domain\AiIntelligence\Stack\AiStackModules;
use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Build, read, edit and refresh a report from an HP Brain area's AI Stack, and run one
 * read-only data source — ported from G2G's AiReportController with the same response
 * keys.
 *
 * The one input difference: G2G resolved the module from a screen `route` through its
 * menu table; HP Brain has no menu table, so `POST workspace/report` names the `module`
 * (an hpbrain_ai_modules key) directly, with optional `arguments` and `template_id`.
 * Without `template_id` the module's report layout is chosen as: kind = report,
 * published, the tenant's own over the platform's, highest version, newest; with none,
 * the plain table over the module's default data source.
 *
 * NOTHING HERE IS GENERATED. A report is a layout an administrator published, filled
 * by ReportLayoutRenderer with rows a read-only source returned — escaped substitution,
 * no model.
 */
final class AiStackReportController extends AiIntelligenceController
{
    private const TABLE = 'hpbrain_ai_generated_reports';

    public function __construct(
        private readonly ModuleDataSourceCatalog $sources,
        private readonly ReportLayoutRenderer $renderer,
        private readonly OrganisationBranding $branding,
        private readonly AiStackModules $modules,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /** Build a report for one module. */
    public function build(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;

            $data = $request->validate([
                'module' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_\-]+$/'],
                'arguments' => 'nullable|array',
                'arguments.limit' => 'nullable|integer|min:1|max:' . ModuleDataSourceCatalog::MAX_ROWS,
                'template_id' => 'nullable|string|uuid',
                // Save the document even when the source returned no rows (it then reads
                // "No records matched."). Off by default: an empty result is an answer.
                'keep_empty' => 'nullable|boolean',
            ]);

            $module = (string) $data['module'];

            if (! $this->modules->exists($module, $tenant)) {
                return $this->failure('This is not a module a report can be built from.', 422);
            }

            if (! Schema::hasTable(self::TABLE)) {
                return $this->failure('Reports are not available on this deployment yet.', 503);
            }

            if (($data['template_id'] ?? null) !== null) {
                $layout = $this->chosenLayout((string) $data['template_id'], $module, $tenant);

                if ($layout === null) {
                    return $this->failure('That report layout is not one of this module\'s.', 422);
                }
            } else {
                $layout = $this->activeLayout($module, $tenant);
            }

            $moduleSources = array_column($this->sources->forModule($module), 'name');
            $sourceName = ($layout !== null && trim((string) $layout->data_source) !== '') ? (string) $layout->data_source : ($moduleSources[0] ?? null);

            // A layout bound to another module's source is refused rather than run: a
            // report built from this module reads this module's data only.
            if ($sourceName === null || ! in_array($sourceName, $moduleSources, true)) {
                return $this->failure('This module has no read-only data source a report can be built from.', 422);
            }

            $arguments = array_merge(
                $this->declaredOnly($sourceName, Platform::decode($layout->data_arguments ?? null)),
                $this->declaredOnly($sourceName, $this->arguments($request))
            );

            $result = $this->sources->run($sourceName, $scope, $arguments);

            if (! $result['available']) {
                return $this->failure((string) $result['reason'], 422);
            }

            $source = $this->sources->describe($sourceName);
            $moduleLabel = $this->modules->label($module, $tenant);
            $title = $layout !== null
                ? (string) preg_replace('/\s*\(example\)\s*$/i', '', (string) $layout->name)
                : (string) ($source['label'] ?? $moduleLabel);

            // An empty result is an answer, not a document.
            if ($result['total'] === 0 && ! $request->boolean('keep_empty')) {
                return $this->success('No records matched.', [
                    'module' => $module,
                    'template_id' => null,
                    'title' => $title,
                    'row_count' => 0,
                    'columns' => [],
                    'source_tool' => $sourceName,
                    'layout_template_id' => $layout === null ? null : (string) $layout->id,
                    'layout_name' => $layout?->name,
                    'template_link' => null,
                ]);
            }

            $columns = $result['rows'] === [] ? array_column((array) ($source['columns'] ?? []), 'key') : array_keys($result['rows'][0]);
            $html = $this->render($layout?->html_layout, $result['rows'], $columns, $title, $moduleLabel, $tenant, $result['total']);

            $id = Platform::id();
            $now = Platform::now();

            DB::table(self::TABLE)->insert([
                'id' => $id,
                'tenant_id' => $tenant,
                'module_key' => $module,
                'layout_template_id' => $layout === null ? null : (string) $layout->id,
                'title' => mb_substr($title, 0, 250),
                'html_content' => $html,
                'question' => null,
                'source_tool' => $sourceName,
                'arguments' => json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'row_count' => $result['total'],
                'status' => 1,
                'created_by' => $scope->userId,
                'created_date' => $now,
                'updated_date' => $now,
            ]);

            $this->audit->record('ai.report.generated', $scope, [
                'related_type' => self::TABLE,
                'related_id' => $id,
                'message' => sprintf('Report "%s" built from %s (%d rows).', $title, $sourceName, $result['total']),
            ]);

            return $this->success('Report built.', [
                'module' => $module,
                'template_id' => $id,
                'title' => $title,
                'row_count' => $result['total'],
                'columns' => $columns,
                'source_tool' => $sourceName,
                'layout_template_id' => $layout === null ? null : (string) $layout->id,
                'layout_name' => $layout?->name,
                'template_link' => '/ai/reports/' . $id,
            ], 201);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $row = $this->owned($id, $this->scope($request)->tenantId);

            if ($row === null) {
                return $this->failure('That report was not found.', 404);
            }

            return $this->success('Report resolved.', ['report' => $this->present($row)]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Save an edited title and document. */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $row = $this->owned($id, $scope->tenantId);

            if ($row === null) {
                return $this->failure('That report was not found.', 404);
            }

            $data = $request->validate([
                'title' => 'required|string|max:250',
                'html' => 'required|string|max:2000000',
            ]);

            DB::table(self::TABLE)->where('id', $id)->where('tenant_id', $scope->tenantId)->update([
                'title' => trim($data['title']),
                'html_content' => $data['html'],
                'updated_date' => Platform::now(),
            ]);

            $this->audit->record('ai.report.edited', $scope, [
                'related_type' => self::TABLE,
                'related_id' => $id,
                'message' => sprintf('Report "%s" edited.', trim($data['title'])),
            ]);

            return $this->success('Report saved.', ['report' => $this->present($this->owned($id, $scope->tenantId))]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Re-read the figures against live records, with the same source, arguments and
     * layout. Refused rather than blanking a document when the source cannot be run.
     */
    public function regenerate(Request $request, string $id): JsonResponse
    {
        try {
            $scope = $this->scope($request);
            $tenant = $scope->tenantId;
            $row = $this->owned($id, $tenant);

            if ($row === null) {
                return $this->failure('That report was not found.', 404);
            }

            if (! $row->source_tool || ! $this->sources->exists((string) $row->source_tool)) {
                return $this->failure('This report\'s data source is no longer available, so its figures cannot be refreshed.', 422);
            }

            $arguments = $this->declaredOnly((string) $row->source_tool, Platform::decode($row->arguments));
            $result = $this->sources->run((string) $row->source_tool, $scope, $arguments);

            if (! $result['available']) {
                return $this->failure('This report\'s data source is no longer available, so its figures cannot be refreshed.', 422);
            }

            $layout = $row->layout_template_id
                ? Platform::visible(DB::table('hpbrain_ai_templates')->where('id', (string) $row->layout_template_id), $tenant)->value('html_layout')
                : null;
            $moduleLabel = $this->modules->label((string) $row->module_key, $tenant);
            $columns = $result['rows'] === [] ? [] : array_keys($result['rows'][0]);
            $html = $this->render($layout, $result['rows'], $columns, (string) $row->title, $moduleLabel, $tenant, $result['total']);

            DB::table(self::TABLE)->where('id', $id)->where('tenant_id', $tenant)->update([
                'html_content' => $html,
                'row_count' => $result['total'],
                'updated_date' => Platform::now(),
            ]);

            return $this->success('Figures refreshed.', [
                'html' => $html,
                'row_count' => $result['total'],
                'module' => (string) $row->module_key,
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * Run one read-only source and return its rows — the Knowledge Base tab's "Check".
     * Read-only by construction: the catalogue holds nothing that writes.
     */
    public function runSource(Request $request, string $name): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            if (! $this->sources->exists($name)) {
                return $this->failure("There is no data source called {$name}.", 404);
            }

            $data = $request->validate([
                'arguments' => 'nullable|array',
                'arguments.limit' => 'nullable|integer|min:1|max:' . ModuleDataSourceCatalog::MAX_ROWS,
                // The AI Stack module the check is run from. When given, the source must
                // be one of THAT module's — a module's Knowledge Base cannot read another's.
                'module' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_\-]+$/'],
            ]);

            $module = trim((string) ($data['module'] ?? ''));

            if ($module !== '') {
                if (! $this->modules->exists($module, $scope->tenantId)) {
                    return $this->failure('That module is not one this organisation has.', 422);
                }

                if (($this->sources->describe($name)['module'] ?? null) !== $module) {
                    return $this->failure("{$name} is not a data source of the {$module} module.", 422);
                }
            }

            $result = $this->sources->run($name, $scope, $this->declaredOnly($name, $this->arguments($request)));

            return $this->success($result['available'] ? 'Data source read.' : 'Data source unavailable.', [
                'source' => $this->sources->describe($name),
                'data' => [
                    'rows' => $result['rows'],
                    'total' => $result['total'],
                    'returned' => $result['returned'] ?? count($result['rows']),
                    'truncated' => $result['truncated'],
                    'available' => $result['available'],
                    'reason' => $result['reason'],
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    // ------------------------------------------------------------------ internals

    /** This module's published report layout: the tenant's own first, then the platform's. */
    private function activeLayout(string $module, string $tenant): ?object
    {
        if (! Schema::hasTable('hpbrain_ai_templates')) {
            return null;
        }

        return Platform::visible(
            DB::table('hpbrain_ai_templates')
                ->where('module_key', $module)
                ->where('kind', 'report')
                ->where('status', 'published'),
            $tenant
        )
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenant])
            ->orderByDesc('version')
            ->orderByDesc('updated_date')
            ->first();
    }

    /** A layout the caller named — a report layout of this module that this tenant can see. */
    private function chosenLayout(string $templateId, string $module, string $tenant): ?object
    {
        if (! Schema::hasTable('hpbrain_ai_templates')) {
            return null;
        }

        return Platform::visible(
            DB::table('hpbrain_ai_templates')
                ->where('id', $templateId)
                ->where('module_key', $module)
                ->where('kind', 'report'),
            $tenant
        )->first();
    }

    /** @param array<int, array<string, mixed>> $rows @param array<int, string> $columns */
    private function render(?string $layout, array $rows, array $columns, string $title, string $moduleLabel, string $tenant, ?int $total = null): string
    {
        $layout = trim((string) $layout) !== ''
            ? (string) $layout
            // No published layout: the plain table G2G builds in the same case.
            : '<h2><<report_title>></h2><p><small><<row_count>> record(s) · generated <<generated_at>></small></p><<rows_table>>';

        $branding = $this->branding->for($tenant);

        return $this->renderer->render($layout, $rows, $columns, [
            'report_title' => $title,
            'module' => $moduleLabel,
            'institute_name' => $branding['institute_name'] ?? '',
            // The source's real size, not the rows this document holds (see ReportLayoutRenderer).
            'row_count' => $total ?? count($rows),
        ])['html'];
    }

    /**
     * The request's `arguments` object, read AFTER validation passed. Not taken from
     * validate()'s return: with an `arguments.limit` rule present that returns only the
     * validated nested key and would silently drop every other declared argument.
     *
     * @return array<string, mixed>
     */
    private function arguments(Request $request): array
    {
        $given = $request->input('arguments', []);

        return is_array($given) ? $given : [];
    }

    /** @param array<mixed> $given Only the arguments the source declares; anything else is dropped. */
    private function declaredOnly(string $source, array $given): array
    {
        return array_intersect_key($given, array_flip($this->sources->argumentKeys($source)));
    }

    private function owned(string $id, string $tenant): ?object
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        return DB::table(self::TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenant)
            ->where('status', 1)
            ->first();
    }

    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'created_on' => $row->created_date === null ? null : (string) $row->created_date,
            'html' => (string) $row->html_content,
            'figures' => $row->source_tool === null ? null : [
                'module' => (string) $row->module_key,
                'source' => (string) $row->source_tool,
                'generated_at' => (string) ($row->updated_date ?? $row->created_date),
            ],
        ];
    }
}
