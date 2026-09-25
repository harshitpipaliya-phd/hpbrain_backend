<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Conversation;

use App\Domain\AiIntelligence\Support\Platform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and writes assistant transcripts (hpbrain_ai_conversations / _turns).
 *
 * Ported from G2G's ConversationStore. Every method takes the tenant and filters
 * by it — a conversation id alone never reaches a transcript — and the unique
 * index is (session_key, tenant_id), so a client-supplied session key cannot
 * reach another tenant's conversation.
 */
final class ConversationStore
{
    public const CONTEXT_TURNS = 20;

    private const CONVERSATIONS = 'hpbrain_ai_conversations';

    private const TURNS = 'hpbrain_ai_conversation_turns';

    public function resume(string $sessionKey, string $tenantId, ?string $userId, ?string $moduleKey = null): object
    {
        $existing = DB::table(self::CONVERSATIONS)
            ->where('session_key', $sessionKey)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $now = Platform::now();
        $id = Platform::id();

        DB::table(self::CONVERSATIONS)->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'session_key' => $sessionKey,
            'title' => null,
            'module_key' => $moduleKey,
            'turn_count' => 0,
            'status' => 'active',
            'last_turn_at' => null,
            'user_id' => $userId,
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        return DB::table(self::CONVERSATIONS)->where('id', $id)->where('tenant_id', $tenantId)->first();
    }

    public function find(string $id, string $tenantId): ?object
    {
        if (! Schema::hasTable(self::CONVERSATIONS)) {
            return null;
        }

        return DB::table(self::CONVERSATIONS)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /** @return array<int, object> The transcript, oldest first. */
    public function turns(string $conversationId, string $tenantId, ?int $limit = null): array
    {
        if ($this->find($conversationId, $tenantId) === null) {
            return [];
        }

        $query = DB::table(self::TURNS)
            ->where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->orderBy('turn_index');

        if ($limit !== null) {
            $ids = DB::table(self::TURNS)
                ->where('conversation_id', $conversationId)
                ->where('tenant_id', $tenantId)
                ->orderByDesc('turn_index')
                ->limit($limit)
                ->pluck('id');

            $query->whereIn('id', $ids);
        }

        return $query->get()->all();
    }

    /** @return array<int, array{role: string, content: string}> Failed turns excluded. */
    public function contextMessages(string $conversationId, string $tenantId): array
    {
        $messages = [];

        foreach ($this->turns($conversationId, $tenantId, self::CONTEXT_TURNS) as $turn) {
            if ($turn->error !== null || trim((string) $turn->content) === '') {
                continue;
            }

            $messages[] = [
                'role' => $turn->role === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $turn->content,
            ];
        }

        return $messages;
    }

    /** @param array<string, mixed> $meta */
    public function addTurn(string $conversationId, string $tenantId, string $role, string $content, array $meta = []): string
    {
        $nextIndex = (int) DB::table(self::TURNS)
            ->where('conversation_id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->max('turn_index');

        $now = Platform::now();
        $id = Platform::id();

        DB::table(self::TURNS)->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'turn_index' => $nextIndex + 1,
            'role' => $role,
            'content' => $content,
            'provider' => $meta['provider'] ?? null,
            'model' => $meta['model'] ?? null,
            'input_tokens' => $meta['input_tokens'] ?? null,
            'output_tokens' => $meta['output_tokens'] ?? null,
            'latency_ms' => $meta['latency_ms'] ?? null,
            'finish_reason' => isset($meta['finish_reason']) ? mb_substr((string) $meta['finish_reason'], 0, 40) : null,
            'error' => $meta['error'] ?? null,
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        DB::table(self::CONVERSATIONS)
            ->where('id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->update([
                'turn_count' => DB::raw('turn_count + 1'),
                'last_turn_at' => $now,
                'updated_date' => $now,
            ]);

        return $id;
    }

    public function titleFromFirstMessage(string $conversationId, string $tenantId, string $message): void
    {
        $conversation = $this->find($conversationId, $tenantId);

        if ($conversation === null || trim((string) $conversation->title) !== '') {
            return;
        }

        $title = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        DB::table(self::CONVERSATIONS)
            ->where('id', $conversationId)
            ->where('tenant_id', $tenantId)
            ->update(['title' => mb_substr($title, 0, 180), 'updated_date' => Platform::now()]);
    }

    /** @return array<int, object> Most recent first; never-used conversations last. */
    public function recent(string $tenantId, int $limit = 25): array
    {
        if (! Schema::hasTable(self::CONVERSATIONS)) {
            return [];
        }

        return DB::table(self::CONVERSATIONS)
            ->where('tenant_id', $tenantId)
            ->orderByRaw('CASE WHEN last_turn_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_turn_at')
            ->orderByDesc('created_date')
            ->limit($limit)
            ->get()
            ->all();
    }
}
