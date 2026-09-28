<?php

declare(strict_types=1);

namespace App\Domain\AiIntelligence\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Durable audit for the AI & Intelligence layer, written to hpbrain_ai_audit_logs.
 *
 * Ported from G2G's AiAuditLogger with the same contract: every write in this
 * layer records who did what, payloads are redacted before they are stored, and
 * a write here never throws — losing an audit line is bad, losing an
 * administrator's credential rotation because the audit table was locked is
 * worse. Failures fall back to the application log.
 */
class AiAuditLogger
{
    public const GOVERNANCE_REJECTED = 'governance.rejected';

    private const SENSITIVE = [
        'password', 'user_password', 'plain_password', 'token', 'api_key',
        'authorization', 'remember_token', 'otp', 'aadhar_no', 'pan_no',
        'account_no', 'ifsc_code', 'key_hash', 'secret', 'access_token', 'refresh_token',
    ];

    /**
     * Record an event. Returns the row id, or null if the write could not happen.
     *
     * @param  array<string, mixed>  $options
     */
    public function record(string $eventType, ?AiIntelligenceScope $scope = null, array $options = []): ?string
    {
        $now = Platform::now();

        $payload = [
            'id' => Platform::id(),
            'tenant_id' => $scope?->tenantId ?? (string) ($options['tenant_id'] ?? ''),
            'request_id' => $options['request_id'] ?? $this->requestId(),
            'event_type' => mb_substr($eventType, 0, 80),
            'actor_type' => $options['actor_type'] ?? ($scope ? 'user' : 'system'),
            'actor_id' => $this->idString($options['actor_id'] ?? $scope?->userId),
            'actor_label' => isset($options['actor_label']) ? mb_substr((string) $options['actor_label'], 0, 150) : null,
            'subject_entity_key' => $options['subject_entity_key'] ?? null,
            'subject_id' => $this->idString($options['subject_id'] ?? null),
            'related_type' => $options['related_type'] ?? null,
            'related_id' => $this->idString($options['related_id'] ?? null),
            'outcome' => $options['outcome'] ?? 'success',
            'message' => isset($options['message']) ? (string) $options['message'] : null,
            'payload' => isset($options['payload'])
                ? json_encode($this->redact($options['payload']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'created_date' => $now,
            'updated_date' => $now,
        ];

        if ($payload['tenant_id'] === '') {
            // An audit row with no tenant cannot be read back by anyone. Logged
            // rather than stored, so it is still somewhere.
            $this->fallback($eventType, $payload);

            return null;
        }

        try {
            if (! Schema::hasTable('hpbrain_ai_audit_logs')) {
                $this->fallback($eventType, $payload);

                return null;
            }

            DB::table('hpbrain_ai_audit_logs')->insert($payload);

            return $payload['id'];
        } catch (Throwable $exception) {
            $this->fallback($eventType, $payload + ['audit_error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Refusals are `outcome = rejected`, not failures: being refused is the system
     * working, not breaking.
     *
     * @param  array<string, mixed>  $options
     */
    public function recordRejection(string $reason, ?AiIntelligenceScope $scope = null, array $options = []): ?string
    {
        return $this->record(self::GOVERNANCE_REJECTED, $scope, $options + [
            'outcome' => 'rejected',
            'message' => $reason,
        ]);
    }

    private function redact(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE, true)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }

    private function idString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, 64);
    }

    private function requestId(): ?string
    {
        try {
            $value = request()?->header('X-Request-Id');
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 64) : null;
    }

    /** @param array<string, mixed> $payload */
    private function fallback(string $eventType, array $payload): void
    {
        Log::info('[ai.intelligence.audit] ' . $eventType, $payload);
    }
}
