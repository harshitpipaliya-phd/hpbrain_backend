<?php

declare(strict_types=1);

namespace App\Domain\Verbs;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\GroundedClaims;
use App\Domain\Ai\PromptTemplates;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use App\Domain\Learning\MemoryGrounding;
use App\Domain\Undetermined\VerbResult;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * EVALUATE — judge the strength of the case for a signal, and write the
 * judgement down as a reasoning step.
 *
 * The row it writes is hpbrain_reasoning_steps with a confidence_score, which
 * puts a model-authored judgement in the same table as a human one. That is
 * intentional and it is why `created_by` records the actor who asked and the
 * step's description states that a model produced it: a reasoning ledger where
 * you cannot tell which steps a machine wrote is not an auditable ledger.
 *
 * confidence_score is DECIMAL(6,4) and is clamped to [0,1] before it is
 * written. A model returning 1.4 is not expressing great certainty, it is
 * failing to follow the schema, and storing it would corrupt every average
 * computed over that column.
 */
final class EvaluateVerb
{
    private const SERVICE = 'verb.evaluate';
    private const TEMPLATE = 'evaluate';

    /** @var array<int, string> */
    private array $failureGaps = [];

    public function __construct(
        private readonly VerbPipeline $pipeline,
        private readonly MemoryGrounding $memory,
        private readonly AiGateway $ai,
        private readonly PromptTemplates $prompts,
    ) {
    }

    /** See RecommendVerb::run() for why the gaps are replaced rather than left generic. */
    public function run(string $tenantId, string $signalId, string $actorId, string $role): VerbResult
    {
        $this->failureGaps = [];

        $signal = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)->where('id', $signalId)->first();

        $result = $this->pipeline->run(
            Verb::EVALUATE,
            fn () => $this->governance($role),
            fn () => $this->ground($tenantId, $signalId, $actorId, $signal),
            fn (array $grounding) => $this->reason($tenantId, $signalId, $actorId, $signal, $grounding),
        );

        if ($result->isUndetermined() && $this->failureGaps !== []) {
            return VerbResult::undetermined($this->failureGaps, $result->evidenceRefs);
        }

        return $result;
    }

    /** @return array{allowed: bool, reason: string} */
    private function governance(string $role): array
    {
        $resolved = Role::tryFromName($role);

        if ($resolved === null) {
            return ['allowed' => false, 'reason' => 'unknown_role'];
        }

        // EVALUATE writes a reasoning step, so it is a create despite being
        // read-oriented in Verb::isReadOnly() — the verb's risk class and its
        // data footprint are different questions.
        if (! $resolved->grants(Permission::CREATE)) {
            return ['allowed' => false, 'reason' => 'create_permission_required'];
        }

        return ['allowed' => true, 'reason' => 'permitted'];
    }

    /** @return array<int, array<string, mixed>> */
    private function ground(string $tenantId, string $signalId, string $actorId, ?object $signal): array
    {
        $evidence = DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenantId)->where('signal_id', $signalId)
            ->orderByDesc('confidence')->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => 'evidence', 'row' => (array) $r])
            ->all();

        $learnings = array_map(
            fn (array $l) => ['id' => $l['id'], 'kind' => 'learning', 'row' => $l],
            $this->memory->groundAndRecord(
                $tenantId, 'Signal', $signalId, $actorId, $signal->classification ?? null, $signalId
            )
        );

        return array_merge($evidence, $learnings);
    }

    /**
     * @param  array<int, array<string, mixed>>  $grounding
     * @return array{frame: array<string, mixed>, value: array<string, mixed>|null, confidence: float|null}
     */
    private function reason(
        string $tenantId,
        string $signalId,
        string $actorId,
        ?object $signal,
        array $grounding,
    ): array {
        if (! $this->ai->isConfigured()) {
            return $this->undetermined('no_ai_provider_configured');
        }

        $template = $this->prompts->active($tenantId, self::TEMPLATE);
        $groundingIds = array_column($grounding, 'id');

        try {
            $response = $this->ai->complete(
                new AiRequest(
                    systemPrompt: $this->prompts->render($template['template'], []),
                    userPrompt: $this->userPrompt($signal, $grounding),
                    responseSchema: ['assessments' => [[
                        'assessment' => 'string', 'confidence' => 'number 0..1', 'evidenceRefs' => ['string'],
                    ]]],
                    temperature: 0.1,
                ),
                tenantId: $tenantId,
                actorId: $actorId,
                service: self::SERVICE,
                templateId: $template['id'],
                entityType: 'Signal',
                entityId: $signalId,
            );
        } catch (Throwable) {
            return $this->undetermined('ai_call_failed');
        }

        $claims = GroundedClaims::fromResponse(
            $response, $groundingIds, 'assessments', ['assessment', 'confidence']
        );

        if ($claims->isEmpty()) {
            return $this->undetermined(...($claims->gaps ?: ['ai_returned_no_claims']));
        }

        $steps = [];

        // Ordered after any existing steps for this signal, so the ledger reads
        // in the order judgements were actually made.
        $order = DB::table('hpbrain_reasoning_steps')
            ->where('tenant_id', $tenantId)->where('signal_id', $signalId)->count();

        foreach ($claims->claims as $claim) {
            $confidence = max(0.0, min(1.0, (float) $claim['confidence']));

            $row = [
                'id'               => Uuid::uuid4()->toString(),
                'tenant_id'        => $tenantId,
                'signal_id'        => $signalId,
                'case_id'          => null,
                'step_order'       => ++$order,
                // Marked as machine-authored in the text itself. A reasoning
                // ledger that cannot distinguish a model's judgement from a
                // person's is not auditable.
                'description'      => '[EVALUATE/ai] '.mb_substr((string) $claim['assessment'], 0, 2000),
                'confidence_score' => $confidence,
                'created_by'       => $actorId,
                'created_date'     => now()->format('Y-m-d H:i:s'),
            ];

            DB::table('hpbrain_reasoning_steps')->insert($row);

            $steps[] = [
                'id'           => $row['id'],
                'assessment'   => $claim['assessment'],
                'confidence'   => $confidence,
                'evidenceRefs' => $claim['evidenceRefs'],
            ];
        }

        return [
            'frame' => [
                'what_changed'              => $signal->classification ?? 'signal',
                'who_is_affected'           => $signal->related_entity_id ?? $signal->org_id ?? null,
                'when_did_it_start'         => $signal->created_date ?? null,
                'how_large_is_the_gap'      => ['assessments' => count($steps)],
                'what_evidence_supports_it' => $groundingIds,
                'what_would_falsify_it'     => ['dropped_claims' => $claims->gaps],
                'what_is_the_root_cause_family' => 'evaluated',
            ],
            'value' => [
                'signalId'       => $signalId,
                'reasoningSteps' => $steps,
                'droppedClaims'  => $claims->gaps,
                'promptVersion'  => $template['version'],
            ],
            'confidence' => $steps[0]['confidence'],
        ];
    }

    /** @param array<int, array<string, mixed>> $grounding */
    private function userPrompt(?object $signal, array $grounding): string
    {
        $lines = ['SIGNAL: '.json_encode([
            'classification' => $signal->classification ?? null,
            'severity'       => $signal->severity ?? null,
        ]), 'GROUNDING (cite only these ids):'];

        foreach ($grounding as $g) {
            $lines[] = sprintf('- id=%s kind=%s', $g['id'], $g['kind']);
        }

        return implode("\n", $lines);
    }

    /** @return array{frame: array<string, mixed>, value: null, confidence: null} */
    private function undetermined(string ...$gaps): array
    {
        $this->failureGaps = array_values(array_unique($gaps));

        return ['frame' => [], 'value' => null, 'confidence' => null];
    }
}
