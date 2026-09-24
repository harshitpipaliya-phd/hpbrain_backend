<?php

declare(strict_types=1);

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use Throwable;

/**
 * The deterministic driver: local development, and every test in this suite.
 *
 * It makes no network call, so the test suite cannot accidentally spend money
 * or depend on a provider being up. Its output is fixed and stated by the
 * caller, which is what lets a test assert on a specific parse failure or a
 * specific fabricated citation rather than on whatever a real model happened to
 * say that morning.
 *
 * `model` is reported as 'null-provider' and never as a real model name. An
 * execution row is the audit record of a recommendation, and one that claims
 * 'claude-opus-5' when nothing was called would make the audit trail lie —
 * which is worse than having no row at all.
 *
 * SAFETY: AiGateway refuses to treat this driver as a configured provider
 * outside local and testing environments. Canned reasoning presented as real
 * reasoning is the exact failure the honesty principle exists to prevent, and
 * a typo in AI_PROVIDER on a production box must not be able to cause it.
 */
final class NullAiProvider implements AiProvider
{
    public const MODEL = 'null-provider';

    /**
     * @param  string|null  $content  the canned body; defaults to a minimal
     *                                well-formed envelope so a caller that has
     *                                not stated one still gets valid JSON
     * @param  Throwable|null  $throws  simulates a transport failure
     */
    public function __construct(
        private readonly ?string $content = null,
        private readonly ?Throwable $throws = null,
        private readonly int $inputTokens = 0,
        private readonly int $outputTokens = 0,
    ) {
    }

    public function complete(AiRequest $request): AiResponse
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new AiResponse(
            content: $this->content ?? json_encode(['claims' => []], JSON_THROW_ON_ERROR),
            model: self::MODEL,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
            latencyMs: 0,
            finishReason: 'stop',
        );
    }
}
