<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Thrown by AiGateway::complete() before any provider call is made, when the
 * tenant's configured quota for this feature is already at or over its
 * limit. Distinct from a provider failure — this is a refusal to spend, not
 * a spend that failed — so a caller that wants to tell an operator "you are
 * throttled" rather than "the AI is down" can catch this type specifically
 * before falling through to the generic Throwable handler every AI-calling
 * verb already has.
 */
final class AiQuotaExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $feature,
        public readonly QuotaResult $quota,
    ) {
        parent::__construct(sprintf(
            'AI quota exceeded for tenant %s, feature "%s": %d/%d used this %s period.',
            $tenantId,
            $feature,
            $quota->used,
            $quota->limit,
            $quota->resetPeriod,
        ));
    }
}
