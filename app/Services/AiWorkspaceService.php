<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Conversation sessions and messages for the AI Workspace.
 *
 * IDS ARE GENERATED IN PHP, not by MySQL's UUID(). Three reasons, worst first:
 * the id must be known to the caller, and insertGetId() on a VARCHAR(36) key
 * returns the auto-increment value — which is 0 here, so every "created"
 * response named session 0 and every follow-up write missed; UUID() does not
 * exist on SQLite, so no test could reach this code at all; and a server-side
 * default hides the id from the audit trail the write belongs to.
 *
 * EVERY METHOD TAKES A TENANT AND EVERY QUERY FILTERS ON IT. A session id is a
 * UUID, not a capability — knowing one must not be enough to read the
 * conversation it names, or a leaked id becomes a cross-tenant transcript read.
 */
final class AiWorkspaceService
{
    /** @return array<string, mixed> */
    public function createSession(string $tenantId, string $userId, string $title): array
    {
        $sessionId = Uuid::uuid4()->toString();
        $now       = now()->format('Y-m-d H:i:s');

        DB::table('hpbrain_conversation_sessions')->insert([
            'id'           => $sessionId,
            'tenant_id'    => $tenantId,
            'user_id'      => $userId,
            'created_by'   => $userId,
            'title'        => $title,
            'is_pinned'    => false,
            'created_date' => $now,
            'updated_date' => $now,
        ]);

        return ['id' => $sessionId, 'title' => $title];
    }

    /**
     * Append a message to a session the tenant actually owns.
     *
     * Returns null when the session does not belong to $tenantId — the same
     * answer it gives for a session that does not exist, so a caller cannot
     * probe which ids are live in other tenants.
     *
     * @return array<string, mixed>|null
     */
    public function sendMessage(string $tenantId, string $sessionId, string $message, string $role = 'user'): ?array
    {
        if (! $this->sessionBelongsToTenant($tenantId, $sessionId)) {
            return null;
        }

        $messageId = Uuid::uuid4()->toString();
        $now       = now()->format('Y-m-d H:i:s');

        DB::table('hpbrain_conversation_messages')->insert([
            'id'           => $messageId,
            'tenant_id'    => $tenantId,
            'session_id'   => $sessionId,
            'role'         => $role,
            'content'      => $message,
            'citations'    => json_encode([]),
            'created_date' => $now,
        ]);

        DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $sessionId)
            ->update(['updated_date' => $now]);

        return ['id' => $messageId, 'role' => $role, 'content' => $message];
    }

    /** @return array<int, array<string, mixed>> */
    public function getConversationHistory(string $tenantId, string $sessionId): array
    {
        if (! $this->sessionBelongsToTenant($tenantId, $sessionId)) {
            return [];
        }

        return DB::table('hpbrain_conversation_messages')
            ->where('tenant_id', $tenantId)
            ->where('session_id', $sessionId)
            ->orderBy('created_date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function listSessions(string $tenantId, string $userId): array
    {
        return DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('updated_date')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function regenerate(string $tenantId, string $sessionId, string $messageId): ?array
    {
        if (! $this->messageBelongsToTenant($tenantId, $sessionId, $messageId)) {
            return null;
        }

        // Regeneration re-runs the model over the same assembled context. That
        // pipeline is not wired to this surface yet (PART-3-3 limitation 4), so
        // report the request as unperformed rather than returning a fabricated
        // new answer that no model produced.
        return ['id' => $messageId, 'regenerated' => false, 'reason' => 'not_implemented'];
    }

    /** @return array<string, mixed>|null */
    public function explain(string $tenantId, string $sessionId, string $messageId): ?array
    {
        if (! $this->messageBelongsToTenant($tenantId, $sessionId, $messageId)) {
            return null;
        }

        $row = DB::table('hpbrain_conversation_messages')
            ->where('tenant_id', $tenantId)
            ->where('id', $messageId)
            ->first();

        $citations = json_decode((string) ($row->citations ?? '[]'), true) ?: [];

        return [
            'id'        => $messageId,
            'citations' => $citations,
            // An explanation with no citations is not an explanation. Say that,
            // rather than emitting prose that merely sounds like grounding.
            'status'    => $citations === [] ? 'no_citations_recorded' : 'ok',
        ];
    }

    /** @return array<int, string> */
    public function getFollowUpQuestions(string $tenantId, string $sessionId, string $messageId): array
    {
        if (! $this->messageBelongsToTenant($tenantId, $sessionId, $messageId)) {
            return [];
        }

        // Real follow-ups have to come from the model that saw the context.
        // Canned questions ("Can you elaborate?") are indistinguishable from
        // generated ones to the UI, so returning none is the honest answer
        // until generation is wired up.
        return [];
    }

    private function sessionBelongsToTenant(string $tenantId, string $sessionId): bool
    {
        return DB::table('hpbrain_conversation_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $sessionId)
            ->exists();
    }

    private function messageBelongsToTenant(string $tenantId, string $sessionId, string $messageId): bool
    {
        return DB::table('hpbrain_conversation_messages')
            ->where('tenant_id', $tenantId)
            ->where('session_id', $sessionId)
            ->where('id', $messageId)
            ->exists();
    }
}
