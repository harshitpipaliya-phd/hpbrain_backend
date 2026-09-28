<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Conversation;

use App\Domain\AiIntelligence\Support\AiAuditLogger;
use App\Domain\AiIntelligence\Support\AiIntelligenceScope;
use App\Domain\AiIntelligence\Support\AiModelClient;
use App\Domain\AiIntelligence\Support\AiNotConfiguredException;
use Throwable;

/**
 * One question in, one answer out, with everything in between recorded.
 *
 * Ported from G2G's AskPipeline — four steps:
 *
 *   1. Resume or open the conversation, scoped to the caller's tenant.
 *   2. Build a grounded system prompt from that tenant's real figures.
 *   3. Call the centrally-configured provider (capability `conversational_ai`),
 *      replaying the recent transcript.
 *   4. Persist both turns and audit the exchange.
 *
 * A failed answer is still a turn, and every exchange is audited — refusals as
 * `rejected`, provider faults as `failure` — with counts and model, never the
 * message text.
 */
final class AskPipeline
{
    private const MODULE = 'conversational_ai';

    public function __construct(
        private readonly ConversationStore $conversations,
        private readonly OrganisationContext $context,
        private readonly AiModelClient $models,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function ask(
        AiIntelligenceScope $scope,
        string $sessionKey,
        string $message,
        ?string $moduleKey = null,
        ?string $organisationName = null
    ): array {
        $tenantId = $scope->tenantId;

        $conversation = $this->conversations->resume($sessionKey, $tenantId, $scope->userId, $moduleKey);
        $conversationId = (string) $conversation->id;

        // The transcript BEFORE this question, or the model is handed it twice.
        $history = $this->conversations->contextMessages($conversationId, $tenantId);

        $this->conversations->addTurn($conversationId, $tenantId, 'user', $message);
        $this->conversations->titleFromFirstMessage($conversationId, $tenantId, $message);

        $briefing = $this->context->briefing($tenantId);

        $messages = array_merge(
            [[
                'role' => 'system',
                'content' => $this->context->systemPrompt($briefing, $organisationName ?: 'this organisation'),
            ]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        try {
            $completion = $this->models->complete(
                self::MODULE,
                $messages,
                [
                    // Low but not zero: factual, without repeating itself verbatim.
                    'temperature' => 0.2,
                    'related_type' => 'hpbrain_ai_conversations',
                    'related_id' => $conversationId,
                    'user_id' => $scope->userId,
                ],
                $tenantId
            );
        } catch (AiNotConfiguredException $exception) {
            return $this->recordFailure($scope, $conversation, $exception, configured: false);
        } catch (Throwable $exception) {
            return $this->recordFailure($scope, $conversation, $exception, configured: true);
        }

        $answer = $completion->text !== ''
            ? $completion->text
            : 'The model returned an empty answer. Try rephrasing the question.';

        $this->conversations->addTurn($conversationId, $tenantId, 'assistant', $answer, $completion->toArray());

        $this->audit->record('ai.conversation.answered', $scope, [
            'related_type' => 'hpbrain_ai_conversations',
            'related_id' => $conversationId,
            'message' => sprintf(
                'Answered in conversation %s using %s/%s.',
                $conversationId,
                $completion->provider,
                $completion->model ?? 'provider default'
            ),
            'payload' => $completion->toArray(),
        ]);

        return [
            'conversation_id' => $conversationId,
            'session_key' => $sessionKey,
            'answer' => $answer,
            'grounded' => $briefing !== null,
            'truncated' => $completion->wasTruncated(),
            'usage' => $completion->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function recordFailure(AiIntelligenceScope $scope, object $conversation, Throwable $exception, bool $configured): array
    {
        $tenantId = $scope->tenantId;
        $conversationId = (string) $conversation->id;

        $this->conversations->addTurn($conversationId, $tenantId, 'assistant', '', ['error' => $exception->getMessage()]);

        $configured
            ? $this->audit->record('ai.conversation.failed', $scope, [
                'related_type' => 'hpbrain_ai_conversations',
                'related_id' => $conversationId,
                'outcome' => 'failure',
                'message' => $exception->getMessage(),
            ])
            : $this->audit->recordRejection($exception->getMessage(), $scope, [
                'related_type' => 'hpbrain_ai_conversations',
                'related_id' => $conversationId,
            ]);

        return [
            'conversation_id' => $conversationId,
            'session_key' => (string) $conversation->session_key,
            'answer' => null,
            'error' => $exception->getMessage(),
            'configured' => $configured,
        ];
    }
}
