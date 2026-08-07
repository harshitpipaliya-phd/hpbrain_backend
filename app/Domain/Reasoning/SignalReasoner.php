<?php

declare(strict_types=1);

namespace App\Domain\Reasoning;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Recommendation\RecommendationService;
use App\Repositories\EvidenceRepository;
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
 * THE CHAIN IS Signal → Evidence → ReasoningStep(s) → Recommendation, written
 * in that order inside one transaction. Three things follow from that shape:
 *
 *   1. EVIDENCE IS AN INPUT, NOT AN AFTERTHOUGHT. The model is shown the real
 *      hpbrain_evidence rows behind the signal, not just the signal's summary
 *      counters. A signal says "50 departments have no manager"; the evidence
 *      says WHICH ones. A recommendation reasoned from the counter alone can
 *      only restate the counter, which is what made the first version of this
 *      prompt produce generic text.
 *
 *   2. THE REASONING TRAIL IS STORED, NOT SUMMARISED. hpbrain_reasoning_steps
 *      carries step_order precisely so a trail can be more than one row. What
 *      the model ruled out, and why, is written as its own ordered step. A
 *      confidence number with no visible deliberation asks the reader to trust
 *      an opaque score; the steps let them audit the argument instead.
 *
 *   3. NOTHING IS COMMITTED HALF-WAY. A recommendation with no reasoning is an
 *      unsourced claim, and reasoning with no recommendation is deliberation
 *      nobody acts on. Neither half is meaningful alone, so the transaction
 *      covers all of it — steps, recommendation and evidence links together.
 *
 * NOT IMPLEMENTED HERE, DELIBERATELY: autonomy_level. The ESO contract
 * (contracts/eso/eso.schema.yaml §5.2) defines the autonomy ladder as
 * observe|suggest|approve|autonomous and says in terms: "use these trust
 * levels; do not invent others". Effective autonomy is meant to be computed
 * from policy + confidence + capability maturity — but hpbrain_policies,
 * hpbrain_executors and hpbrain_eso_definitions are all empty, so two of those
 * three inputs do not exist yet. Deriving a level from confidence alone and
 * labelling it "autonomy" would be a fabricated governance signal on a screen
 * that governs real decisions. It stays absent until the inputs are real.
 *
 * The provider is resolved through the container (ADR-004), so this file names
 * no vendor and needs no change when the configured provider does.
 */
final class SignalReasoner
{
    /** Stamped on every row this class writes, so AI-authored rows are findable. */
    private const AUTHOR = 'signal-reasoner';

    /**
     * How many evidence rows are put in front of the model.
     *
     * Evidence is homogeneous here — fifty rows of "department X has no
     * manager" teach the model nothing the first dozen did not, and each one
     * spends prompt tokens that the reasoning budget then cannot use. The
     * total is passed separately so a truncated sample is never mistaken for
     * the full extent of the problem.
     */
    private const EVIDENCE_SAMPLE_LIMIT = 12;

    public function __construct(
        private readonly SignalRepository $signals,
        private readonly EvidenceRepository $evidence,
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

        $evidence = $this->evidence->list($tenantId, null, $signalId);

        /** @var AiProvider $provider */
        $provider = app(AiProvider::class);

        try {
            $response = $provider->complete($this->buildRequest($signal, $evidence));
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

        // `title` is NOT required. The recommendations table needs one (NOT
        // NULL) but a title is a restatement of text the model already gave
        // us, so a missing one is truncated from that text rather than
        // failing an otherwise-good answer.
        $title = trim((string) ($parsed['title'] ?? ''));
        $title = $title !== '' ? Str::limit($title, 250, '') : Str::limit($text, 120);

        $trail = $this->normaliseTrail($parsed['reasoning_trail'] ?? null);

        $recommendationId = (string) Str::uuid();

        // One UTC timestamp for every row in the chain. The repositories default
        // created_date to UTC but updated_date has no such default, so it would
        // fall through to the column's current_timestamp() — the DATABASE
        // server's local zone, which is not UTC here. That produced a row whose
        // updated_date was three hours AFTER its created_date at the moment it
        // was inserted, and "last touched" ordering built on that is wrong.
        $now = gmdate('Y-m-d H:i:s');

        DB::transaction(function () use (
            $tenantId, $signalId, $recommendationId, $title, $text,
            $confidence, $category, $priority, $urgency, $response, $trail, $evidence, $now
        ) {
            $stepIds = $this->writeTrail($tenantId, $signalId, $trail, $confidence, $response->model, $now);

            $this->recommendations->insert([
                'id' => $recommendationId,
                'tenant_id' => $tenantId,
                // The recommendation hangs off the LAST step — the conclusion.
                // The earlier steps are reachable from it through the shared
                // signal_id, ordered by step_order, which is how the trail is
                // read back without a second foreign key per step.
                'reasoning_step_id' => $stepIds[array_key_last($stepIds)],
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

            $this->linkEvidence($tenantId, $recommendationId, $evidence, $now);
        });

        return $recommendationId;
    }

    /**
     * @param  array<string, mixed>  $signal
     * @param  array<int, array<string, mixed>>  $evidence
     */
    private function buildRequest(array $signal, array $evidence): AiRequest
    {
        $sample = array_slice($evidence, 0, self::EVIDENCE_SAMPLE_LIMIT);

        return new AiRequest(
            // The prompt asks for three specific things — the pattern, the
            // consequence, the next action — because a request for "a
            // recommendation" reliably returns a restatement of the input.
            // The prohibitions are explicit for the same reason: naming the
            // failure mode is what suppresses it.
            systemPrompt: <<<'PROMPT'
                You are reasoning over one operational signal for a real
                business, using the real evidence rows that produced it.

                Write for an operations manager who will act on this today.
                Your recommendation_text MUST do three things, in order:
                  1. Name the SPECIFIC pattern in the evidence — reference the
                     real records, names or counts you were given. Not "there
                     may be an issue" or "data quality concerns exist".
                  2. State the concrete consequence of leaving it alone. What
                     actually goes wrong, to whom, and when.
                  3. Give ONE next action, and say which ROLE should own it.

                INVENT NOTHING. This is absolute and overrides every
                instruction above about being specific. You may state only
                what is present in the evidence you were given. In particular:
                  - Never name an individual person. Say "the HRIS
                    administrator" or "the owner of the department record",
                    never a personal name. A plausible name is a fabricated
                    one, and a reader cannot tell the difference.
                  - Never invent a date, deadline, meeting, report, schedule
                    or system that was not given to you. No "before Friday",
                    no "in the next sprint", no "at the monthly review".
                  - Never invent counts, amounts or record ids. Quote the
                    numbers you were actually given.
                If being specific would require making something up, be
                general instead and note the gap in your reasoning_trail.
                Specificity about the EVIDENCE is the goal; specificity about
                people and dates you were not told is the failure.

                Do not write corporate filler. Do not hedge with "consider
                reviewing" or "it may be advisable". Do not recommend forming a
                committee, conducting a comprehensive audit, or establishing a
                framework. Three sentences to five, plain English.

                reasoning_trail is where you show your work: what you
                considered, and what you RULED OUT and why. An alternative
                explanation you rejected is more useful to the reader than a
                restatement of your conclusion. If the evidence does not let
                you distinguish between two explanations, say so in the trail
                and lower your confidence — do not pick one to sound decisive.

                The evidence sample may be smaller than the total affected
                count. Reason about the full count; quote only what you saw.

                If the evidence is insufficient to say anything specific and
                useful, set confidence low and say plainly what is missing.
                A low-confidence answer that names its gap is worth more than
                a confident one that invents a pattern.

                Return ONLY a JSON object matching the given schema.
                PROMPT,
            userPrompt: json_encode([
                'signal' => [
                    'rule' => $signal['rule_key'] ?? null,
                    'classification' => $signal['classification'] ?? null,
                    'severity' => $signal['severity'] ?? null,
                    'source' => $signal['source'] ?? null,
                    'detector_confidence' => $signal['confidence'] ?? null,
                    'metadata' => $signal['metadata'] ?? null,
                ],
                'evidence' => [
                    'total_rows_available' => count($evidence),
                    'rows_shown' => count($sample),
                    'observations' => array_map(static fn (array $e): array => [
                        'source' => $e['source'] ?? null,
                        'type' => $e['evidence_type'] ?? null,
                        'confidence' => $e['confidence'] ?? null,
                        'content' => $e['content'] ?? null,
                    ], $sample),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            responseSchema: [
                'title' => 'string, under 120 characters, states the specific finding',
                'recommendation_text' => 'string: specific pattern, then consequence, then one next action',
                'reasoning_trail' => 'array of strings, ordered — what you considered and what you ruled out, with reasons',
                'confidence' => 'float, 0.0 to 1.0',
                'category' => 'string: risk | compliance | opportunity | watch',
            ],
            // MEASURED, not guessed. Asking for a reasoning trail roughly
            // trebled the reply: a measured run of this exact request spent
            // 1724 of AiRequest's default 2048 output tokens — 84% of the
            // ceiling. The provider's `thinking` mode is on by default and its
            // hidden reasoning tokens are drawn from the SAME budget as the
            // visible answer, so a slightly longer trail crosses the line,
            // comes back with finish_reason 'length', and truncates the JSON
            // mid-string. A truncated reply fails ->json() and is reported as
            // UNDETERMINED — indistinguishable, from the outside, from a model
            // that had nothing to say. That is a silent failure caused purely
            // by an output ceiling, so the ceiling is raised to give the trail
            // room it demonstrably needs.
            maxTokens: 4096,
        );
    }

    /**
     * The trail as an ordered list of non-empty strings, or [] if the model
     * gave nothing usable.
     *
     * A malformed trail does NOT fail the recommendation. The trail is
     * explanatory; the recommendation is the claim. Discarding a sound,
     * well-evidenced answer because its explanation came back as a nested
     * object instead of a list of strings would be strictness spent in the
     * wrong place. An absent trail is recorded honestly as absent, below.
     *
     * @return array<int, string>
     */
    private function normaliseTrail(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $steps = [];

        foreach ($raw as $entry) {
            // Strings are the asked-for shape. Scalars are close enough to
            // read. Anything else — a nested object, a list — is skipped
            // rather than json_encode()d into the trail, because a reader
            // opening the reasoning should never be shown raw serialisation.
            if (! is_scalar($entry)) {
                continue;
            }

            $step = trim((string) $entry);

            if ($step !== '') {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * Writes the trail as ordered reasoning steps and returns their ids.
     *
     * Always writes at least one step. When the model returned no usable
     * trail the single step says so explicitly rather than being omitted:
     * a recommendation whose reasoning is silently missing looks identical
     * to one nobody has opened yet, and the difference matters to whoever
     * is deciding whether to trust it.
     *
     * @param  array<int, string>  $trail
     * @return array<int, string>  step ids, in written order
     */
    private function writeTrail(
        string $tenantId,
        string $signalId,
        array $trail,
        float $confidence,
        string $model,
        string $now,
    ): array {
        // The model's identity is recorded here because
        // hpbrain_recommendations has no `model` column, and "which model
        // said this" is the first question asked of any recommendation that
        // turns out to be wrong.
        $descriptions = $trail === []
            ? [sprintf('Reasoned over signal %s using %s. The model returned no reasoning trail.', $signalId, $model)]
            : $trail;

        $stepIds = [];

        foreach (array_values($descriptions) as $index => $description) {
            $stepId = (string) Str::uuid();

            $this->steps->insert([
                'id' => $stepId,
                'tenant_id' => $tenantId,
                'signal_id' => $signalId,
                'step_order' => $index + 1,
                'description' => $description,
                // Every step carries the answer's confidence rather than a
                // per-step figure. The model was not asked to score its
                // individual steps, and inventing a gradient across them
                // would be a fabricated precision.
                'confidence_score' => $confidence,
                'created_by' => self::AUTHOR,
                'created_date' => $now,
            ]);

            $stepIds[] = $stepId;
        }

        // The provenance line is appended as the final step so the trail ends
        // with what produced it, and so array_key_last() gives the conclusion
        // a reader would expect when the model DID supply a trail.
        if ($trail !== []) {
            $stepId = (string) Str::uuid();

            $this->steps->insert([
                'id' => $stepId,
                'tenant_id' => $tenantId,
                'signal_id' => $signalId,
                'step_order' => count($descriptions) + 1,
                'description' => sprintf('Concluded from the above using %s.', $model),
                'confidence_score' => $confidence,
                'created_by' => self::AUTHOR,
                'created_date' => $now,
            ]);

            $stepIds[] = $stepId;
        }

        return $stepIds;
    }

    /**
     * Records which evidence rows this recommendation actually rests on.
     *
     * EVERY evidence row for the signal is linked, not just the sampled subset
     * the model was shown. The claim is about the whole population the signal
     * detected; linking only the twelve rows that fitted in the prompt would
     * misrepresent a recommendation about fifty departments as resting on
     * twelve, and the reverse index exists so a disputed observation can be
     * traced to every claim built on it.
     *
     * @param  array<int, array<string, mixed>>  $evidence
     */
    private function linkEvidence(string $tenantId, string $recommendationId, array $evidence, string $now): void
    {
        if ($evidence === []) {
            return;
        }

        DB::table('hpbrain_recommendation_evidence')->insert(array_map(
            static fn (array $e): array => [
                'tenant_id' => $tenantId,
                'recommendation_id' => $recommendationId,
                'evidence_id' => $e['id'],
                'linked_date' => $now,
            ],
            $evidence
        ));
    }
}
