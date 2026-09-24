<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Recommendation\EsoBindingRule;
use App\Http\Controllers\Controller;
use App\Repositories\RecommendationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * Response shapes must match the Express originals exactly — web/src/api/*.ts
 * consumes them literally (ADR-007).
 *
 * Golden-path step 5. Invariant 3 is the one that bites here: every action is
 * executable. A recommendation whose category is `intervene` or `escalate` is
 * telling a human to act, and telling someone to act without naming the ESO
 * that defines the act is advice, not an action.
 */
final class RecommendationController extends Controller
{
    public function __construct(private readonly RecommendationRepository $repository)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->repository->list($this->tenantId($request), $request->query('status'))
        );
    }

    public function show(Request $request, string $tenantId, string $id): JsonResponse
    {
        $row = $this->repository->findById($this->tenantId($request), $id);

        return $row
            ? response()->json($row)
            : response()->json(['error' => 'recommendation_not_found'], 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenantId'        => ['required', 'string', 'min:1'],
            'reasoningStepId' => ['required', 'string', 'size:36'],
            'category'        => ['required', Rule::in(['watch', 'investigate', 'intervene', 'escalate'])],
            'title'           => ['required', 'string', 'min:5', 'max:300'],
            'description'     => ['nullable', 'string', 'max:5000'],
            'priority'        => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'confidence'      => ['required', 'numeric', 'between:0,1'],
            'impact'          => ['nullable', 'string', 'max:1000'],
            'cost'            => ['nullable', 'string', 'max:1000'],
            'risk'            => ['nullable', 'string', 'max:1000'],
            'dependencies'    => ['nullable', 'array'],
            // Deliberately NOT `required_if`. The presence rule is enforced
            // below so the caller gets `eso_binding_required` — a named
            // invariant violation — rather than a generic field error that
            // reads like a typo.
            'esoId'           => ['nullable', 'string', 'size:36'],
        ]);

        $tenant = $this->tenantId($request);

        // The foreign key proves the reasoning step exists; it says nothing
        // about who owns it. A recommendation anchored to another tenant's
        // reasoning would cite reasoning this tenant never did.
        $ownsStep = DB::table('hpbrain_reasoning_steps')
            ->where('tenant_id', $tenant)
            ->where('id', $data['reasoningStepId'])
            ->exists();

        if (! $ownsStep) {
            return response()->json(['error' => 'reasoning_step_not_found'], 422);
        }

        $esoId = $data['esoId'] ?? null;

        // The rule lives in EsoBindingRule because the RECOMMEND verb writes to
        // this table too, and one invariant with two implementations drifts.
        if (! EsoBindingRule::isSatisfied($data['category'], $esoId)) {
            return response()->json([
                'error'    => 'eso_binding_required',
                'category' => $data['category'],
            ], 422);
        }

        if ($esoId !== null) {
            $ownsEso = DB::table('hpbrain_eso_definitions')
                ->where('tenant_id', $tenant)
                ->where('id', $esoId)
                ->exists();

            if (! $ownsEso) {
                return response()->json(['error' => 'eso_not_found'], 422);
            }
        }

        $row = [
            'id'                => Uuid::uuid4()->toString(),
            'tenant_id'         => $tenant,
            'reasoning_step_id' => $data['reasoningStepId'],
            'category'          => $data['category'],
            'title'             => $data['title'],
            'description'       => $data['description'] ?? null,
            'priority'          => $data['priority'],
            'confidence'        => $data['confidence'],
            'impact'            => $data['impact'] ?? null,
            'cost'              => $data['cost'] ?? null,
            'risk'              => $data['risk'] ?? null,
            'dependencies'      => json_encode($data['dependencies'] ?? []),
            // Invariant 3 is now a property of the DATA, not just of this
            // request. Nullable on purpose: `watch` and `investigate` name no
            // ESO, and forcing one would make every observation invent an
            // action. The column carries a real foreign key to
            // hpbrain_eso_definitions (2026_07_30_000100).
            'eso_id'            => $esoId,
            'status'            => 'pending',
            // tenantId always comes from the token, never the body — a client
            // must not be able to write into another tenant by changing a
            // payload field.
            'created_by'        => $this->actorId($request),
        ];

        $this->repository->insert($row);

        // Re-read so `dependencies` comes back as an array, the way every read
        // surface returns it.
        return response()->json($this->repository->findById($tenant, $row['id']), 201);
    }
}
