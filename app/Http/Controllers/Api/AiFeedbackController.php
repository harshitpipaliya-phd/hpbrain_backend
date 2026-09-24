<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiFeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiFeedbackController extends Controller
{
    public function __construct(private readonly AiFeedbackService $feedbackService)
    {
    }

    public function index(Request $request, string $tenantId): JsonResponse
    {
        $executionId = $request->query('execution_id');

        $rows = $this->feedbackService->getFeedback($this->tenantId($request), (string) $executionId);

        return response()->json($rows);
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = \Illuminate\Support\Facades\DB::table('hpbrain_ai_feedback')
            ->where('tenant_id', $this->tenantId($request))
            ->where('id', $id)
            ->first();

        return $row ? response()->json((array) $row) : response()->json(['error' => 'feedback_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'execution_id'  => ['required', 'string'],
            'rating'        => ['required', 'string', 'max:50'],
            'feedback_text' => ['nullable', 'string'],
            'feedback_type' => ['nullable', 'string', 'max:255'],
        ]);

        $this->feedbackService->record(
            $this->tenantId($request),
            $data['execution_id'],
            $this->actorId($request),
            $data['rating'],
            $data['feedback_text'] ?? null,
            $data['feedback_type'] ?? null,
        );

        return response()->json(['ok' => true], 201);
    }
}
