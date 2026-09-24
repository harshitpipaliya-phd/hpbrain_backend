<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ImportJobRepository;
use App\Repositories\ImportLogRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImportController extends Controller
{
    public function __construct(
        private readonly ImportJobRepository $jobRepository,
        private readonly ImportLogRepository $logRepository,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $orgId = $request->query('orgId');
        $status = $request->query('status');

        return response()->json($this->jobRepository->list($tenantId, $orgId, $status));
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->jobRepository->find($this->tenantId($request), $id);

        return $row ? response()->json($row) : response()->json(['error' => 'import_job_not_found'], 404);
    }

    public function validate(Request $request): JsonResponse
    {
        $request->validate([
            'file_path'  => ['required', 'string'],
            'entity_type'=> ['required', 'string'],
        ]);

        $result = app(\App\Services\ImportEngine::class)->validateFile(
            $request->input('file_path'),
            $request->input('entity_type')
        );

        return response()->json($result);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file_path'  => ['required', 'string'],
            'entity_type'=> ['required', 'string'],
        ]);

        $tenantId = $this->tenantId($request);
        $orgId = $request->input('org_id', '');

        $result = app(\App\Services\ImportEngine::class)->previewImport($tenantId, $orgId, $request->input('file_path'), $request->input('entity_type'));

        return response()->json($result);
    }

    public function detectDuplicates(Request $request): JsonResponse
    {
        $request->validate([
            'rows'       => ['required', 'array'],
            'entity_type'=> ['required', 'string'],
        ]);

        $tenantId = $this->tenantId($request);
        $duplicates = app(\App\Services\ImportEngine::class)->detectDuplicates($tenantId, $request->input('rows'), $request->input('entity_type'));

        return response()->json(['duplicates' => $duplicates]);
    }

    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'rows'        => ['required', 'array'],
            'entity_type' => ['required', 'string'],
            'org_id'      => ['nullable', 'string'],
            'import_type' => ['nullable', 'string'],
        ]);

        $tenantId = $this->tenantId($request);
        $job = app(\App\Services\ImportEngine::class)->startImport($tenantId, $request->input('org_id', ''), $request->input('rows'), $request->input('entity_type'), [
            'import_type' => $request->input('import_type', 'csv'),
            'started_by'  => $this->actorId($request),
        ]);

        return response()->json($job, 201);
    }

    public function process(Request $request, string $tenantId, string $id): JsonResponse
    {
        $results = app(\App\Services\ImportEngine::class)->processImport($id);

        return response()->json($results);
    }

    public function rollback(Request $request, string $tenantId, string $id): JsonResponse
    {
        // The tenant is passed through so the engine can scope its lookup.
        // Always $this->tenantId($request) — the value EnsureTenantScope
        // resolved — never the raw route segment, which a client controls.
        $ok = app(\App\Services\ImportEngine::class)->rollbackImport($id, $this->tenantId($request));

        return $ok ? response()->json(['ok' => true]) : response()->json(['error' => 'import_job_not_found'], 404);
    }

    public function logs(Request $request, string $tenantId, string $id): JsonResponse
    {
        $logs = app(\App\Services\ImportEngine::class)->getImportLogs($id);

        return response()->json($logs);
    }
}
