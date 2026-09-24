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
use App\Domain\Recommendation\EsoBindingRule;
use App\Domain\Undetermined\VerbResult;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * RECOMMEND — the first verb in this build that actually calls a model.
 *
 * It is layered on a pipeline Module 6 already proved with no LLM at all, so a
 * failure here is a reasoning failure, not a plumbing one. The grounding step
 * is unchanged from EXPLAIN: the signal's evidence plus organizational memory.
 *
 * WHAT THE MODEL IS AND IS NOT TRUSTED WITH. It is trusted to propose wording,
 * category and priority. It is not trusted to decide what counts as evidence
 * (GroundedClaims drops any claim citing something we did not supply), not
 * trusted to bypass Invariant 3 (an actionable category still needs an ESO
 * binding), and not trusted to execute anything — EXECUTE stays dark and no
 * path from here can start an ESO run.
 */
final class RecommendVerb
{
    private const SERVICE = 'verb.recommend';
    private const TEMPLATE = 'recommend';

    /** @var array<int, string> */
    private const CATEGORIES = ['watch', 'investigate', 'intervene', 'escalate'];

    /** @var array<int, string> */
    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    /**
     * Why the reasoning step could not produce a usable answer, when it could
     * not. See run() — this is how the real reason survives the pipeline.
     *
     * @var array<int, string>
     */
    private array $failureGaps = [];

    public function __construct(
        private readonly VerbPipeline $pipeline,
        private readonly MemoryGrounding $memory,
        private readonly AiGateway $ai,
        private readonly PromptTemplates $prompts,
    ) {
    }

    /**
     * REPLACING THE GAPS, AND WHY THAT IS NOT WEAKENING THE GATE.
     *
     * VerbPipeline hands the frame to SufficiencyCheck, which returns
     * UNDETERMINED naming the seven UODM questions it found unanswered. That is
     * exactly right when a frame is genuinely incomplete — and useless when the
     * truth is "the model returned prose instead of JSON", because the caller
     * is then told seven things are missing rather than the one thing that
     * actually happened.
     *
     * So the pipeline still decides DECIDED vs UNDETERMINED — governance,
     * grounding and sufficiency all run untouched — and only the gap TEXT of an
     * already-undetermined result is replaced with the specific cause. The
     * answer never becomes more confident, only more diagnosable.
     */
    public function run(string $tenantId, string $signalId, string $actorId, string $role): VerbResult
    {
        $this->failureGaps = [];

        $signal = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)->where('id', $signalId)->first();

        $result = $this->pipeline->run(
            Verb::RECOMMEND,
            fn () => $this->governance($role),
            fn () => $this->ground($tenantId, $signalId, $actorId, $signal),
            fn (array $grounding) => $this->reason($tenantId, $signalId, $actorId, $signal, $grounding),
        );

        if ($result->isUndetermined() && $this->failureGaps !== []) {
            return VerbResult::undetermined($this->failureGaps, $result->evidenceRefs);
        }

        return $result;
    }

    /**
     * RECOMMEND proposes a course of action and writes a row, so it needs
     * CREATE — read alone is not enough, unlike EXPLAIN and ASSESS.
     *
     * @return array{allowed: bool, reason: string}
     */
    private function governance(string $role): array
    {
        $resolved = Role::tryFromName($role);

        if ($resolved === null) {
            return ['allowed' => false, 'reason' => 'unknown_role'];
        }

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
     * @return array{frame: array<string, mixed>, value: array<string, mixed>, confidence: float|null}
     */
    private function reason(
        string $tenantId,
        string $signalId,
        string $actorId,
        ?object $signal,
        array $grounding,
    ): array {
        if (! $this->ai->isConfigured()) {
            // No provider: say so. An empty frame makes SufficiencyCheck return
            // UNDETERMINED, which is the honest answer — never a canned
            // recommendation dressed as reasoning.
            return $this->undetermined('no_ai_provider_configured');
        }

        $template = $this->prompts->active($tenantId, self::TEMPLATE);
        $groundingIds = array_column($grounding, 'id');

        try {
            $response = $this->ai->complete(
                new AiRequest(
                    systemPrompt: $this->prompts->render($template['template'], [
                        'categories' => implode(', ', self::CATEGORIES),
                        'priorities' => implode(', ', self::PRIORITIES),
                    ]),
                    userPrompt: $this->userPrompt($signal, $grounding),
                    responseSchema: $this->schema(),
                    temperature: 0.2,
                ),
                tenantId: $tenantId,
                actorId: $actorId,
                service: self::SERVICE,
                templateId: $template['id'],
                entityType: 'Signal',
                entityId: $signalId,
            );
        } catch (Throwable $e) {
            // The gateway has already recorded the failed execution. The verb's
            // job is to be honest about it, not to retry into a guess.
            return $this->undetermined('ai_call_failed');
        }

        $claims = GroundedClaims::fromResponse(
            $response, $groundingIds, 'claims', ['title', 'category', 'priority']
        );

        $gaps = $claims->gaps;
        $written = [];

        foreach ($claims->claims as $claim) {
            $category = (string) $claim['category'];

            if (! in_array($category, self::CATEGORIES, true)) {
                $gaps[] = 'ai_claim_unknown_category:'.$category;
                continue;
            }

            // Invariant 3, same rule the HTTP endpoint applies. A model may not
            // tell somebody to intervene without naming what to run.
            if (! EsoBindingRule::isSatisfied($category, isset($claim['esoId']) ? (string) $claim['esoId'] : null)) {
                $gaps[] = 'eso_binding_required';
                continue;
            }

            $written[] = $this->persist($tenantId, $actorId, $signalId, $claim, $category);
        }

        if ($written === []) {
            return $this->undetermined(...($gaps ?: ['ai_returned_no_usable_claims']));
        }

        // The frame is answered from the claims that SURVIVED the guardrail,
        // so a run whose every claim was dropped cannot present itself as a
        // decided result.
        return [
            'frame' => [
                'what_changed'              => $signal->classification ?? 'signal',
                'who_is_affected'           => $signal->related_entity_id ?? $signal->org_id ?? null,
                'when_did_it_start'         => $signal->created_date ?? null,
                'how_large_is_the_gap'      => ['recommendations' => count($written)],
                'what_evidence_supports_it' => $groundingIds,
                'what_would_falsify_it'     => ['dropped_claims' => $gaps],
                'what_is_the_root_cause_family' => $written[0]['category'],
            ],
            'value' => [
                'signalId'        => $signalId,
                'recommendations' => $written,
                // Surfaced even on success: a run that produced two good
                // recommendations and silently dropped a hallucinated third
                // must say so.
                'droppedClaims'   => array_values(array_unique($gaps)),
                'promptVersion'   => $template['version'],
            ],
            'confidence' => $written[0]['confidence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    private function persist(string $tenantId, string $actorId, string $signalId, array $claim, string $category): array
    {
        // The reasoning step this recommendation hangs off. Nullable in the
        // schema, and a model-authored recommendation has no human reasoning
        // step behind it — recording null is more honest than attaching it to
        // an unrelated one.
        $row = [
            'id'                => Uuid::uuid4()->toString(),
            'tenant_id'         => $tenantId,
            'reasoning_step_id' => null,
            'category'          => $category,
            'title'             => mb_substr((string) $claim['title'], 0, 300),
            'description'       => isset($claim['rationale']) ? (string) $claim['rationale'] : null,
            'priority'          => in_array((string) $claim['priority'], self::PRIORITIES, true)
                ? (string) $claim['priority'] : 'medium',
            'confidence'        => $this->clamp($claim['confidence'] ?? null),
            'dependencies'      => json_encode($claim['evidenceRefs']),
            'status'            => 'pending',
            'created_by'        => $actorId,
            'created_date'      => now()->format('Y-m-d H:i:s'),
        ];

        DB::table('hpbrain_recommendations')->insert($row);

        return [
            'id'           => $row['id'],
            'title'        => $row['title'],
            'category'     => $row['category'],
            'priority'     => $row['priority'],
            'confidence'   => $row['confidence'],
            'evidenceRefs' => $claim['evidenceRefs'],
            'signalId'     => $signalId,
        ];
    }

    /** @param array<int, array<string, mixed>> $grounding */
    private function userPrompt(?object $signal, array $grounding): string
    {
        $lines = [
            'SIGNAL: '.json_encode([
                'classification' => $signal->classification ?? null,
                'source'         => $signal->source ?? null,
                'severity'       => $signal->severity ?? null,
            ]),
            'GROUNDING (cite only these ids):',
        ];

        foreach ($grounding as $g) {
            $lines[] = sprintf('- id=%s kind=%s %s', $g['id'], $g['kind'], json_encode(
                $g['kind'] === 'evidence'
                    ? ['content' => $g['row']['content'] ?? null, 'confidence' => $g['row']['confidence'] ?? null]
                    : ['pattern' => $g['row']['pattern'] ?? null, 'domain' => $g['row']['domain'] ?? null]
            ));
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'claims' => [[
                'title' => 'string', 'category' => implode('|', self::CATEGORIES),
                'priority' => implode('|', self::PRIORITIES), 'confidence' => 'number 0..1',
                'rationale' => 'string', 'evidenceRefs' => ['string'], 'esoId' => 'string|null',
            ]],
        ];
    }

    private function clamp(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.5;
    }

    /**
     * An empty frame makes SufficiencyCheck return UNDETERMINED; the recorded
     * gaps then replace its generic seven-questions list in run().
     *
     * @return array{frame: array<string, mixed>, value: null, confidence: null}
     */
    private function undetermined(string ...$gaps): array
    {
        $this->failureGaps = array_values(array_unique($gaps));

        return ['frame' => [], 'value' => null, 'confidence' => null];
    }
}
