<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Universal\CrossProductIdentityResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 7.5 — cross-product GraphRAG: "tell me about this person," drawing
 * on all three products. The capability the roadmap says justifies the
 * shared graph, built on what 7.4 actually established:
 *
 *   - G2G and EB SHARE ONE SCHEMA (hp_erp) and the SAME tbluser id space -
 *     confirmed before building this, not assumed. The G2G and EB sections
 *     below are a direct same-schema read, no crosswalk involved.
 *   - K-12 (vivek_erp) is a genuinely separate schema with its OWN id
 *     space - the same numeric id there is routinely a different person.
 *     The K-12 section can only be filled via a CONFIRMED row in
 *     cross_product_identity_links (Phase 7.4). Today that is zero rows -
 *     73 proposals exist, none yet reviewed - so every real call's K-12
 *     section will honestly report "not yet confirmed" until a human
 *     reviews CrossProductIdentityResolver::pendingReview(). That is
 *     accurate, not broken: this endpoint does not invent a identity link
 *     to have something to show.
 *
 * RETRIEVAL AND GENERATION ARE SEPARATE STEPS, same shape as
 * GraphExplanationController: profile() is pure aggregation, no model call.
 * narrative() is the only billed step.
 */
final class PersonProfileController extends Controller
{
    private const SERVICE = 'person_profile_narrative';

    public function profile(Request $request, string $g2gUserId): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $userId = (int) $g2gUserId;

        $g2g = $this->g2gSection($tenantId, $userId);

        if ($g2g === null) {
            return response()->json(['error' => 'person_not_found', 'g2g_user_id' => $userId], 404);
        }

        return response()->json([
            'g2g' => $g2g,
            'eb' => $this->ebSection($tenantId, $userId),
            'k12' => $this->k12Section($tenantId, $userId),
        ]);
    }

    public function narrative(Request $request, string $g2gUserId, CrossProductIdentityResolver $resolver, AiGateway $ai): JsonResponse
    {
        $profileResponse = $this->profile($request, $g2gUserId);

        if ($profileResponse->getStatusCode() !== 200) {
            return $profileResponse;
        }

        $profile = $profileResponse->getData(true);

        if (! $ai->isConfigured()) {
            return response()->json($profile + ['narrative' => null, 'narrativeStatus' => 'ai_not_configured']);
        }

        try {
            $response = $ai->complete(
                new AiRequest(
                    systemPrompt: $this->systemPrompt(),
                    userPrompt: $this->userPrompt($profile),
                    responseSchema: self::SCHEMA,
                    maxTokens: 700,
                    temperature: 0.2,
                ),
                tenantId: $this->tenantId($request),
                actorId: $this->actorId($request),
                service: self::SERVICE,
                entityType: 'person',
                entityId: $g2gUserId,
            );
        } catch (Throwable $e) {
            return response()->json($profile + ['narrative' => null, 'narrativeStatus' => 'ai_call_failed']);
        }

        $json = $response->json();

        if ($json === null || ! isset($json['narrative'])) {
            return response()->json($profile + ['narrative' => null, 'narrativeStatus' => 'ai_response_not_json']);
        }

        return response()->json($profile + ['narrative' => trim((string) $json['narrative']), 'narrativeStatus' => 'ok']);
    }

    // ── Sections ─────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function g2gSection(string $tenantId, int $userId): ?array
    {
        $user = DB::table('hp_erp.tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $tenantId)
            ->first(['id', 'first_name', 'last_name', 'email', 'employee_id', 'jobtitle_id', 'department_id', 'reporting_manager_id']);

        if ($user === null) {
            return null;
        }

        $ratings = DB::table('hp_erp.competency_kasba_rating')
            ->where('sub_institute_id', $tenantId)
            ->where('user_id', $userId)
            ->count();

        return [
            'found' => true,
            'name' => trim((string) $user->first_name . ' ' . (string) $user->last_name),
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'department_id' => $user->department_id,
            'reporting_manager_id' => $user->reporting_manager_id,
            'competency_ratings_on_file' => $ratings,
        ];
    }

    /** @return array<string,mixed> */
    private function ebSection(string $tenantId, int $userId): array
    {
        $capabilities = DB::table('hpbrain_capability_assignments as a')
            ->join('hpbrain_capabilities as c', 'c.id', '=', 'a.capability_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.target_type', 'Person')
            ->where('a.target_id', (string) $userId)
            ->get(['c.name', 'a.assigned_date']);

        if ($capabilities->isEmpty()) {
            return [
                'found' => false,
                'reason' => 'No hpbrain_capability_assignments row names this person as a target in this tenant.',
                'capabilities' => [],
            ];
        }

        return [
            'found' => true,
            'capabilities' => $capabilities->map(fn ($c) => ['name' => $c->name, 'assignedDate' => $c->assigned_date])->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function k12Section(string $tenantId, int $userId): array
    {
        $resolver = app(CrossProductIdentityResolver::class);
        $resolved = $resolver->resolve('g2g', (int) $tenantId, $userId);

        if ($resolved === null || $resolved['k12'] === null) {
            return [
                'found' => false,
                'reason' => 'No confirmed cross_product_identity_links row for this person. '
                    . 'A draft proposal may exist (CrossProductIdentityResolver::pendingReview()) but has not been reviewed.',
            ];
        }

        $k12 = $resolved['k12'];

        $student = DB::table('vivek_erp.tbluser')
            ->where('id', $k12['user_id'])
            ->where('sub_institute_id', $k12['sub_institute_id'])
            ->first(['id', 'first_name', 'last_name', 'email']);

        if ($student === null) {
            // The link exists but the K-12 row it points to is gone - say so,
            // never silently drop back to "not found" as if no link existed.
            return ['found' => false, 'reason' => 'Confirmed link exists but its K-12 row no longer resolves.'];
        }

        return [
            'found' => true,
            'k12_sub_institute_id' => $k12['sub_institute_id'],
            'k12_user_id' => $k12['user_id'],
            'name' => trim((string) $student->first_name . ' ' . (string) $student->last_name),
            'email' => $student->email,
        ];
    }

    // ── Narrative ────────────────────────────────────────────────────────

    private const SCHEMA = ['narrative' => 'string, 3-5 sentences of plain language, json'];

    private function systemPrompt(): string
    {
        return 'You are summarizing one person\'s record across three separate organizational systems '
            . '(an HR/competency platform, an intelligence layer, and a K-12 learning platform) for someone '
            . 'deciding what to do next. Use only the facts given in the message - never invent a name, '
            . 'figure, or relationship. When a section reports found=false, say plainly that nothing is on '
            . 'file there rather than guessing why.';
    }

    /** @param array<string,mixed> $profile */
    private function userPrompt(array $profile): string
    {
        return "Person profile, assembled from three systems:\n\n" . json_encode($profile, JSON_PRETTY_PRINT);
    }
}
