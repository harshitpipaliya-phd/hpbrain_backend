<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AiIntelligence;

use App\Domain\AiIntelligence\Conversation\AskPipeline;
use App\Domain\AiIntelligence\Conversation\ConversationStore;
use App\Domain\AiIntelligence\Conversation\OrganisationContext;
use App\Domain\AiIntelligence\Support\OrganisationProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Conversational AI — the assistant, its transcripts, and its grounding.
 *
 * Ported from G2G's AskController. `ask` is the one route here that spends money
 * and writes a transcript; a failed answer is a 200 carrying `answer: null`, the
 * reason and `configured`, because the question was accepted and recorded.
 * There is deliberately no streaming route.
 */
final class AiIntelligenceAskController extends AiIntelligenceController
{
    public function __construct(
        private readonly AskPipeline $pipeline,
        private readonly ConversationStore $conversations,
        private readonly OrganisationContext $context,
        private readonly OrganisationProfile $organisation,
    ) {
    }

    public function ask(Request $request): JsonResponse
    {
        try {
            $scope = $this->scope($request);

            $validated = $request->validate([
                'message' => 'required|string|max:8000',
                'session_key' => 'nullable|string|max:64',
                'module_key' => 'nullable|string|max:80',
            ]);

            $result = $this->pipeline->ask(
                $scope,
                trim((string) ($validated['session_key'] ?? '')) ?: (string) Str::uuid(),
                trim($validated['message']),
                $validated['module_key'] ?? null,
                $this->organisation->name($scope->tenantId)
            );

            return $this->success(
                $result['answer'] === null ? 'The assistant could not answer.' : 'Answered.',
                $result
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function conversations(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $rows = $this->conversations->recent($tenantId, $this->limit($request, 25, 100));

            return $this->success('Conversations resolved.', [
                'tenant_id' => $tenantId,
                'conversations' => array_map(fn ($row) => [
                    'id' => (string) $row->id,
                    'session_key' => (string) $row->session_key,
                    'title' => $row->title === null ? null : (string) $row->title,
                    'module_key' => $row->module_key === null ? null : (string) $row->module_key,
                    'turn_count' => (int) $row->turn_count,
                    'status' => (string) $row->status,
                    'last_turn_at' => $row->last_turn_at === null ? null : (string) $row->last_turn_at,
                    'created_at' => $row->created_date === null ? null : (string) $row->created_date,
                ], $rows),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function conversation(Request $request, string $conversation): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;

            $row = $this->conversations->find($conversation, $tenantId);

            if ($row === null) {
                return $this->failure('That conversation was not found.', 404);
            }

            $turns = $this->conversations->turns($conversation, $tenantId);

            return $this->success('Conversation resolved.', [
                'conversation' => [
                    'id' => (string) $row->id,
                    'session_key' => (string) $row->session_key,
                    'title' => $row->title === null ? null : (string) $row->title,
                    'module_key' => $row->module_key === null ? null : (string) $row->module_key,
                    'turn_count' => (int) $row->turn_count,
                    'status' => (string) $row->status,
                    'last_turn_at' => $row->last_turn_at === null ? null : (string) $row->last_turn_at,
                ],
                'turns' => array_map(fn ($turn) => [
                    'id' => (string) $turn->id,
                    'turn_index' => (int) $turn->turn_index,
                    'role' => (string) $turn->role,
                    'content' => (string) $turn->content,
                    'provider' => $turn->provider === null ? null : (string) $turn->provider,
                    'model' => $turn->model === null ? null : (string) $turn->model,
                    'input_tokens' => $turn->input_tokens === null ? null : (int) $turn->input_tokens,
                    'output_tokens' => $turn->output_tokens === null ? null : (int) $turn->output_tokens,
                    'latency_ms' => $turn->latency_ms === null ? null : (int) $turn->latency_ms,
                    'error' => $turn->error === null ? null : (string) $turn->error,
                    'created_at' => $turn->created_date === null ? null : (string) $turn->created_date,
                ], $turns),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** What the assistant is told about this tenant, so the grounding can be checked. */
    public function groundingContext(Request $request): JsonResponse
    {
        try {
            $tenantId = $this->scope($request)->tenantId;
            $briefing = $this->context->briefing($tenantId);

            return $this->success('Grounding context resolved.', [
                'tenant_id' => $tenantId,
                'grounded' => $briefing !== null,
                'facts' => $this->parseBriefing($briefing),
            ]);
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** @return array<int, array{label: string, value: string}> */
    private function parseBriefing(?string $briefing): array
    {
        if ($briefing === null) {
            return [];
        }

        $facts = [];

        foreach (explode("\n", $briefing) as $line) {
            if (preg_match('/^-\s*(.+?):\s*(.+)$/', trim($line), $matches)) {
                $facts[] = ['label' => trim($matches[1]), 'value' => trim($matches[2])];
            }
        }

        return $facts;
    }
}
