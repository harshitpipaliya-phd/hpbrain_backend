<?php

declare(strict_types=1);

namespace App\Domain\Reasoning;

use App\Domain\Ai\AiRequest;
use App\Domain\Recommendation\RecommendationService;
use App\Repositories\RecommendationRepository;
use App\Repositories\SignalRepository;
use App\Services\AiProviderRegistry;
use Illuminate\Support\Str;

/**
 * The wire that was missing. SignalRuleRegistry produces real signals
 * (import.complaint · high · new) from real ingested data — that part
 * already works. RecommendationService classifies a confidence score
 * into priority/urgency — that part already works too. NOTHING between
 * them actually reasons about what a signal means. This class is that
 * middle step.
 *
 * Deliberately conservative: if the model doesn't return valid JSON
 * matching the schema (AiResponse::json() returns null — see that
 * class's own reasoning), this writes NOTHING. A missing recommendation
 * is honest. A fabricated one, because "the pipeline should produce
 * something," is exactly what Invariant 7 and the whole
 * never-fabricate-confidence principle exist to prevent.
 */
final class SignalReasoner
{
    public function __construct(
        private readonly SignalRepository $signals,
        private readonly RecommendationRepository $recommendations,
        private readonly RecommendationService $classifier,
    ) {
    }

    /**
     * Reason over one real signal. Returns the new recommendation's id,
     * or null if the model could not produce a valid, schema-conforming
     * answer — in which case the signal is left exactly as it was
     * (status stays whatever SignalRuleRegistry set it to), not silently
     * marked as handled.
     */
    public function reasonOver(string $tenantId, string $signalId): ?string
    {
        $signal = $this->signals->findById($tenantId, $signalId);
        if ($signal === null) {
            return null;
        }

        $providerConfig = AiProviderRegistry::getActive();
        if ($providerConfig === null) {
            // Honest failure, not a fabricated recommendation: no active
            // provider means UNDETERMINED, same as NullAiProvider's own
            // reasoning elsewhere in this codebase.
            return null;
        }

        /** @var \App\Domain\Ai\AiProvider $provider */
        $provider = app($providerConfig['class']);

        $request = new AiRequest(
            systemPrompt: <<<PROMPT
                You are reasoning over one operational signal for a real
                business. You will be given the signal's real type, its
                real severity, and the real underlying data that produced
                it. Return ONLY a JSON object matching the given schema.
                If the underlying data is insufficient to say anything
                specific and useful, set "confidence" low rather than
                inventing a plausible-sounding recommendation.
                PROMPT,
            userPrompt: json_encode([
                'signal_type' => $signal['signal_type'] ?? null,
                'severity' => $signal['severity'] ?? null,
                'metadata' => $signal['metadata'] ?? null,
            ], JSON_PRETTY_PRINT),
            responseSchema: [
                'recommendation_text' => 'string',
                'confidence' => 'float, 0.0 to 1.0',
                'category' => 'string: risk | compliance | opportunity | watch',
            ],
        );

        $response = $provider->complete($request);
        $parsed = $response->json();

        if ($parsed === null
            || !isset($parsed['recommendation_text'], $parsed['confidence'], $parsed['category'])
        ) {
            return null; // honest — see class docblock
        }

        $confidence = (float) $parsed['confidence'];
        $category = $this->classifier->resolveCategory((string) $parsed['category'], $confidence);
        $priority = $this->classifier->derivePriority($confidence);
        $urgency = $this->classifier->deriveUrgency($category, $confidence);

        $id = (string) Str::uuid();
        $this->recommendations->create($tenantId, [
            'id' => $id,
            'signal_id' => $signalId,
            'recommendation_text' => $parsed['recommendation_text'],
            'confidence' => $confidence,
            'category' => $category,
            'priority' => $priority,
            'urgency' => $urgency,
            'status' => 'pending_approval', // matches the real "Approve / Reject" UI already built
            'model' => $response->model,
            'created_date' => now(),
        ]);

        return $id;
    }
}
