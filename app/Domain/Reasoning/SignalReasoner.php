<?php

declare(strict_types=1);

namespace App\Domain\Reasoning;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Recommendation\RecommendationService;
use App\Repositories\ReasoningStepRepository;
use App\Repositories\RecommendationRepository;
use App\Repositories\SignalRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns ONE operational signal into at most one recommendation.
 *
 * "At most one" is the whole contract. Every path that cannot produce a
 * recommendation it can stand behind returns null — a missing signal, a
 * provider that threw, a reply that was not the JSON that was asked for, a
 * reply missing a required field. Null means UNDETERMINED, and the caller is
 * expected to treat it as an absence of intelligence rather than as a neutral
 * result. Writing a row anyway, with invented text or a defaulted confidence,
 * would be the one failure this layer exists to prevent.
 *
 * THE CHAIN IS Signal → ReasoningStep → Recommendation, and it is written in
 * that order inside one transaction. hpbrain_recommendations has no signal_id:
 * it reaches its signal through reasoning_step_id, which is what makes a
 * recommendation auditable back to the evidence that produced it. Writing the
 * recommendation alone would leave a claim with no recorded reasoning — the
 * orphan the ReferentialIntegrity analyzer exists to complain about — and
 * writing the step alone would leave reasoning nobody acts on. Neither half is
 * meaningful without the other, so neither is committed without the other.
 *
 * The provider is resolved through the container (ADR-004), so this file names
 * no vendor and needs no change when the configured provider does.
 */
final class SignalReasoner
{
    /** Stamped on every row this class writes, so AI-authored rows are findable. */
    private const AUTHOR = 'signal-reasoner';

    public function __construct(
        private readonly SignalRepository $signals,
        private readonly ReasoningStepRepository $steps,
        private readonly RecommendationRepository $recommendations,
        private readonly RecommendationService $classifier,
    ) {
    }

    public function reasonOver(string $tenantId, string $signalId): ?string
    {
        $signal = $this->signals->findById($tenantId, $signalId);
        if ($signal === null) {
            return null;
        }

        /** @var AiProvider $provider */
        $provider = app(AiProvider::class);

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
                'title' => 'string, under 120 characters',
                'recommendation_text' => 'string',
                'confidence' => 'float, 0.0 to 1.0',
                'category' => 'string: risk | compliance | opportunity | watch',
            ],
        );

        try {
            $response = $provider->complete($request);
        } catch (\Throwable) {
            // A transport or protocol failure is not a low-confidence answer.
            // It is no answer, and it is reported as one.
            return null;
        }

        $parsed = $response->json();

        if ($parsed === null) {
            return null;
        }

        // Presence is checked before any field is read: a reply missing
        // `confidence` is not a reply with confidence 0, and defaulting it
        // would manufacture a number the model never produced.
        //
        // `title` is NOT required. The recommendations table needs one (NOT
        // NULL) but a title is a restatement of text the model already gave
        // us, so a missing one is truncated from that text rather than
        // failing an otherwise-good answer.
        if (! isset($parsed['recommendation_text'], $parsed['confidence'], $parsed['category'])) {
            return null;
        }

        $text = trim((string) $parsed['recommendation_text']);

        if ($text === '') {
            return null;
        }

        $confidence = (float) $parsed['confidence'];

        // Category, priority and urgency are DERIVED, never taken from the
        // model: below the low-confidence floor RecommendationService forces
        // 'watch' regardless of what the reply asked for.
        $category = $this->classifier->resolveCategory((string) $parsed['category'], $confidence);
        $priority = $this->classifier->derivePriority($confidence);
        $urgency = $this->classifier->deriveUrgency($category, $confidence);

        $title = trim((string) ($parsed['title'] ?? ''));
        $title = $title !== '' ? Str::limit($title, 250, '') : Str::limit($text, 120);

        $stepId = (string) Str::uuid();
        $recommendationId = (string) Str::uuid();

        // One UTC timestamp for every row in the chain. The repositories default
        // created_date to UTC but updated_date has no such default, so it would
        // fall through to the column's current_timestamp() — the DATABASE
        // server's local zone, which is not UTC here. That produced a row whose
        // updated_date was three hours AFTER its created_date at the moment it
        // was inserted, and "last touched" ordering built on that is wrong.
        $now = gmdate('Y-m-d H:i:s');

        DB::transaction(function () use (
            $tenantId, $signalId, $stepId, $recommendationId,
            $title, $text, $confidence, $category, $priority, $urgency, $response, $now
        ) {
            $this->steps->insert([
                'id' => $stepId,
                'tenant_id' => $tenantId,
                'signal_id' => $signalId,
                'step_order' => 1,
                // The step's description is where the model's identity is
                // recorded: hpbrain_recommendations has no `model` column, and
                // "which model said this" is the first question asked of any
                // recommendation that turns out to be wrong.
                'description' => sprintf(
                    'Reasoned over signal %s using %s.',
                    $signalId,
                    $response->model
                ),
                'confidence_score' => $confidence,
                'created_by' => self::AUTHOR,
                'created_date' => $now,
            ]);

            $this->recommendations->insert([
                'id' => $recommendationId,
                'tenant_id' => $tenantId,
                'reasoning_step_id' => $stepId,
                'category' => $category,
                'title' => $title,
                'description' => $text,
                'priority' => $priority,
                'confidence' => $confidence,
                'urgency' => $urgency,
                // 'pending', not 'pending_approval': SnapshotMetrics counts
                // pending work as status IN ('pending','proposed'), so any
                // other spelling produces a recommendation that exists but is
                // absent from every dashboard that reports the backlog.
                'status' => 'pending',
                'dependencies' => '[]',
                'created_by' => self::AUTHOR,
                'created_date' => $now,
                'updated_date' => $now,
            ]);
        });

        return $recommendationId;
    }
}
