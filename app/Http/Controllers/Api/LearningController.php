<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Learning\LearningService;
use App\Http\Controllers\Controller;
use App\Repositories\LearningRepository;
use App\Repositories\OutcomeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LearningController extends Controller
{
    public function __construct(
        private readonly LearningRepository $repository,
        private readonly OutcomeRepository $outcomes,
        private readonly LearningService $learning,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->repository->list($this->tenantId($request)));
    }

    public function reusable(Request $request): JsonResponse
    {
        $all = $this->repository->list($this->tenantId($request));

        return response()->json(array_values(array_filter($all, fn ($l) => (bool) ($l['reusable'] ?? false))));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outcomeId'   => ['required', 'string'],
            'pattern'     => ['required', 'string', 'min:1'],
            'description' => ['nullable', 'string'],
        ]);

        $tenant  = $this->tenantId($request);
        $outcome = $this->outcomes->findById($tenant, $data['outcomeId']);

        if (! $outcome) {
            return response()->json(['error' => 'outcome_not_found'], 404);
        }

        // A failed or low-confidence outcome is still recorded — the loop must
        // learn from failure — but is not marked reusable, so it is never
        // surfaced as a pattern to repeat.
        $reusable = $this->learning->isReusable(
            (string) ($outcome['result'] ?? ''),
            (float) ($outcome['confidence'] ?? 0)
        );

        return response()->json($this->repository->insert([
            'tenant_id'   => $tenant,
            'outcome_id'  => $data['outcomeId'],
            'pattern'     => $data['pattern'],
            'description' => $data['description'] ?? null,
            'confidence'  => (float) ($outcome['confidence'] ?? 0),
            'reusable'    => $reusable,
            'created_by'  => $this->actorId($request),
        ]), 201);
    }
}
