<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Universal\EntityResolver;
use App\Domain\Signals\RuleCauseMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Propose a root-cause hypothesis for the signals whose rule can honestly state one.
 *
 * NOTHING HERE IS GENERATED. No model is called and no sentence is composed.
 * Every field written is copied from a rule definition, from a real evidence
 * row, or computed from counts of those rows. That is the whole design
 * constraint: a hypothesis is the Brain's claim about WHY something is
 * happening, and a claim assembled from plausible words is the one failure this
 * build has already caught and fixed once.
 *
 * THREE RULES ARE ELIGIBLE, BY EXPLICIT APPROVAL, AND NO OTHERS. Every other
 * rule detects a symptom whose cause is not in the data — a breached service
 * target, a stalled job order, a zone carrying more complaints than its share.
 * Their signals stay UNDETERMINED, and that is the correct answer, not a
 * shortfall. complaint_zone_concentration in particular must never be given a
 * family: the rule already refuses to name a cause in its own code, and
 * overriding that here would contradict a refusal made deliberately.
 *
 * TWO SOURCES OF TRUTH, FOR A REASON THAT IS NOT PREFERENCE.
 * hpbrain_signal_rules carries the approved family for RULES HELD AS ROWS, which
 * is where it belongs — reviewable in one query, changeable by UPDATE, and
 * overridable per tenant by the precedence RuleEvaluator already applies.
 * complaint_root_cause_unrecorded is NOT such a rule: it is an aggregate over a
 * share of closed tickets, which is precisely what OperationalSignalWriter
 * exists because a rule row cannot express. Its row would need universal_entity,
 * predicate and evidence_fields — all NOT NULL — filled with fiction, and would
 * break detection outright if anybody set is_active. So its approved values are
 * declared below instead, with the approval recorded beside them. See the report.
 */
final class ProposeHypotheses extends Command
{
    private const ACTOR = 'brain-propose-hypotheses';

    /**
     * Approved static values for rules that are CODE rather than rows.
     *
     * `statement` stands in for the recommended_action a rule row would carry
     * and is taken verbatim from the rule's own evidence `issue` string in
     * OperationalSignalRules — real text already written on every evidence row
     * this signal holds, not a description composed here.
     *
     * @var array<string, array{family: string, confidence: float, statement: string}>
     */
    private const CODE_RULES = [
        // Approved 2026-08-12: Information / 0.75 was approved for
        // people_without_email and Information / 0.85 for this rule. The
        // predicate IS the cause — a blank Final Solution on a closed ticket is
        // definitionally missing information. Held below 0.9 because deliberate
        // non-use of the field by policy remains a live alternative.
        'complaint_root_cause_unrecorded' => [
            'family'     => 'Information',
            'confidence' => 0.85,
            'statement'  => 'ticket closed with no Final Solution recorded',
        ],
    ];

    /**
     * Recorded cancellation reasons, mapped to families ONLY where the recorded
     * words state an operational cause.
     *
     * MEASURED, NOT ASSUMED. This table was built by reading the actual
     * distribution across all 353 cancelled work orders in the installation
     * before a line of it was written:
     *
     *   Cable Laying Issue              108   → Capacity
     *   No Network                       63   → Capacity
     *   Other                            47   → unmapped
     *   Permission Issue                 44   → External
     *   Client Deny For Box And Power    40   → customer choice, excluded
     *   Client Denied                    29   → customer choice, excluded
     *   Consil Problem                   12   → unmapped
     *   Plan Upgrade Deny                 6   → customer choice, excluded
     *   Delay                             2   → unmapped
     *   Wrong Commitment by Sales Person  1   → unmapped
     *   (absent)                          1   → unmapped
     *
     * 'Consil Problem' is left unmapped deliberately. It may well be a council
     * permission issue, which would make it External — and "may well be" is
     * exactly the reasoning that has no place in a root-cause claim. An unmapped
     * reason costs a hypothesis; a guessed one costs the reader's trust in every
     * hypothesis.
     *
     * 'Wrong Commitment by Sales Person' plausibly reads as Process or
     * Coordination and is left unmapped for a different reason: n=1 across the
     * whole dataset is an anecdote, not a family.
     *
     * @var array<string, string>
     */
    private const CANCELLATION_FAMILIES = [
        'cable laying issue' => 'Capacity',
        'no network'         => 'Capacity',
        'permission issue'   => 'External',
    ];

    /**
     * Reasons that are the CUSTOMER's decision, not an operational cause.
     *
     * Excluded from the vote rather than counted as unmapped: a cancellation the
     * subscriber chose is not evidence about the operator's root cause in either
     * direction, so letting it dilute the majority would be as wrong as letting
     * it win one.
     *
     * NOTE FOR THE RECORD: OperationalSignalRules' own docblock states that
     * 'Client Denied' is "excluded deliberately" from this rule — but the rule
     * counts every row with status 'Cancel' and applies no reason filter at all,
     * so 75 of the 353 cancellations it reports (21%) are customer choices. That
     * is a discrepancy in the DETECTION rule, out of scope here and not changed;
     * it is handled at this layer only in the sense that those reasons never
     * decide a family.
     *
     * @var array<int, string>
     */
    private const CUSTOMER_CHOICE = [
        'client denied',
        'client deny for box and power',
        'plan upgrade deny',
    ];

    /** The rule whose family is derived per signal from real recorded reasons. */
    private const DERIVED_RULE = 'work_order_cancellation_rate';

    protected $signature = 'brain:propose-hypotheses
        {--tenant= : One tenant instead of all}
        {--limit=50 : Max hypotheses to propose per tenant per run}
        {--dry-run : Report what would be proposed and write nothing}';

    protected $description = 'Propose a root-cause hypothesis for signals whose rule states a real cause';

    public function handle(EntityResolver $resolver, RuleCauseMetadata $metadata): int
    {
        $only = $this->option('tenant');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $tenantIds = array_keys($resolver->everyTenantWith('Person'));
        $failures = 0;
        $written = 0;
        $declined = 0;

        foreach ($tenantIds as $tenantId) {
            $tenantId = (string) $tenantId;

            if ($only !== null && $tenantId !== $only) {
                continue;
            }

            try {
                $candidates = $this->candidates($tenantId, $limit, $metadata);

                $this->info("Tenant {$tenantId}: ".count($candidates).' eligible signal(s)'
                    .($dryRun ? ' (dry run — nothing written)' : ''));

                foreach ($candidates as $row) {
                    $proposal = $this->proposalFor($tenantId, $row, $metadata);

                    if ($proposal === null) {
                        $declined++;
                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf('  · would propose %s @ %.4f — %s',
                            $proposal['root_cause_family'], $proposal['confidence'], $proposal['statement']));
                        continue;
                    }

                    DB::table('hpbrain_hypotheses')->insert($proposal);
                    $written++;

                    $this->line(sprintf('  + %s → case %s  %s @ %.4f  "%s"  evidence=%d',
                        $proposal['id'], $proposal['case_id'], $proposal['root_cause_family'],
                        $proposal['confidence'], $proposal['statement'],
                        count(json_decode($proposal['supporting_evidence_ids'], true))));
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run complete. {$declined} signal(s) declined."
            : "Done. {$written} hypothesis(es) proposed, {$declined} declined.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Signals eligible to be reasoned about at all.
     *
     * A case is required and not created here: a hypothesis hangs off a case by
     * foreign key, and ExplainVerb reaches it by joining cases on signal_id.
     * brain:open-cases owns that step, so a signal with no case is skipped
     * rather than silently given one by a second writer.
     *
     * @return array<int, object>
     */
    private function candidates(string $tenantId, int $limit, RuleCauseMetadata $metadata): array
    {
        $eligible = array_merge(
            array_keys(self::CODE_RULES),
            [self::DERIVED_RULE],
            $metadata->approvedRuleKeys($tenantId),
        );

        return DB::table('hpbrain_signals as s')
            ->join('hpbrain_cases as c', function ($j) use ($tenantId) {
                $j->on('c.signal_id', '=', 's.id')->where('c.tenant_id', '=', $tenantId);
            })
            ->where('s.tenant_id', $tenantId)
            ->whereIn('s.status', ['new', 'triaged'])
            ->whereIn('s.rule_key', array_values(array_unique($eligible)))
            // One proposal per case. A rejected hypothesis does NOT block a new
            // one — rejecting it is how an investigation moves on, and the case
            // lifecycle returns to investigating precisely so another can be
            // stated.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('hpbrain_hypotheses as h')
                ->whereColumn('h.case_id', 'c.id')->where('h.tenant_id', $tenantId)
                ->where('h.status', '!=', 'rejected'))
            ->orderBy('s.created_date')
            ->limit($limit)
            ->select('s.id as signal_id', 's.rule_key', 'c.id as case_id')
            ->get()
            ->all();
    }

    /**
     * Row-held rules this tenant resolves that carry an approved family.
     *
     * Read through the same platform/tenant precedence RuleEvaluator applies, so
     * a tenant that has overridden a shipped rule gets its own approved family
     * rather than the platform one.
     *
     * @return array<int, string>
     */
    /**
     * The row to write, or null when this signal cannot honestly carry one.
     *
     * @return array<string, mixed>|null
     */
    private function proposalFor(string $tenantId, object $row, RuleCauseMetadata $metadata): ?array
    {
        $ruleKey = (string) $row->rule_key;
        $signalId = (string) $row->signal_id;

        $evidence = DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenantId)->where('signal_id', $signalId)
            ->where('status', 'active')
            ->get(['id', 'content']);

        if ($evidence->isEmpty()) {
            // supporting_evidence_ids is NOT NULL and an empty array is not
            // support. A hypothesis standing on nothing is the ungrounded
            // generation ADR-004 prohibits, whatever produced it.
            $this->line("  — {$signalId} ({$ruleKey}): declined — no active evidence to stand on");

            return null;
        }

        $stated = $ruleKey === self::DERIVED_RULE
            ? $this->derivedFromReasons($signalId, $ruleKey, $evidence)
            : $this->staticFor($tenantId, $ruleKey, $metadata, $evidence);

        if ($stated === null) {
            return null;
        }

        return [
            'id'                      => Uuid::uuid4()->toString(),
            'tenant_id'               => $tenantId,
            'case_id'                 => (string) $row->case_id,
            'statement'               => $stated['statement'],
            'root_cause_family'       => $stated['family'],
            'confidence'              => round($stated['confidence'], 4),
            // PROPOSED, never confirmed. Nothing in this command has tested the
            // claim against an outcome, and 'confirmed' is what a case reads to
            // decide it may resolve.
            'status'                  => 'proposed',
            'supporting_evidence_ids' => json_encode($evidence->pluck('id')->all()),
            'proposed_by'             => self::ACTOR,
            'created_date'            => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The approved family for a rule that declares one statically.
     *
     * @return array{family: string, confidence: float, statement: string}|null
     */
    private function staticFor(string $tenantId, string $ruleKey, RuleCauseMetadata $metadata, $evidence): ?array
    {
        $approved = $metadata->approvedFor($tenantId, $ruleKey);

        if ($approved !== null) {
            return [
                'family'     => $approved['family'],
                'confidence' => $approved['confidence'],
                'statement'  => $approved['statement'] ?? $this->statementFromEvidence($evidence),
            ];
        }

        return self::CODE_RULES[$ruleKey] ?? null;
    }

    private function statementFromEvidence($evidence): string
    {
        foreach ($evidence as $row) {
            $content = json_decode((string) $row->content, true);

            if (! is_array($content)) {
                continue;
            }

            $issue = trim((string) ($content['issue'] ?? ''));

            if ($issue !== '') {
                return $issue;
            }
        }

        return 'rule predicate has a human-approved root-cause classification';
    }

    /**
     * A family read out of the reasons this firing actually recorded.
     *
     * THE THRESHOLDS, AND WHY EACH ONE IS THERE. A family is stated only when
     * the recorded words genuinely point one way:
     *
     *   - at least two mapped reasons, because one row is an anecdote;
     *   - mapped reasons covering at least half the evidence, so a signal whose
     *     reasons are mostly unrecognised cannot be decided by a small
     *     recognisable minority;
     *   - a STRICT majority for the leading family, so a tie states nothing.
     *
     * Confidence is the share of THIS SIGNAL'S OWN EVIDENCE whose recorded
     * reason points at the family — a counted proportion, not a judgement. It is
     * bounded by the evidence: reasons that were unmapped or customer-chosen
     * hold it down, which is the honest direction for them to push.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $evidence
     * @return array{family: string, confidence: float, statement: string}|null
     */
    private function derivedFromReasons(string $signalId, string $ruleKey, $evidence): ?array
    {
        $counts = [];
        $mapped = 0;
        $observed = [];

        foreach ($evidence as $e) {
            $content = json_decode((string) $e->content, true);
            $reason = is_array($content) ? trim((string) ($content['reason'] ?? '')) : '';
            $key = mb_strtolower($reason);

            if ($reason !== '') {
                $observed[] = $reason;
            }

            if ($key === '' || in_array($key, self::CUSTOMER_CHOICE, true)) {
                continue;
            }

            $family = self::CANCELLATION_FAMILIES[$key] ?? null;

            if ($family === null) {
                continue;
            }

            $counts[$family] = ($counts[$family] ?? 0) + 1;
            $mapped++;
        }

        $total = $evidence->count();
        $summary = $observed === [] ? 'none recorded' : implode(', ', array_unique($observed));

        if ($mapped < 2 || $mapped * 2 < $total) {
            $this->line("  — {$signalId} ({$ruleKey}): declined — only {$mapped} of {$total} recorded "
                ."reason(s) map to a family [{$summary}]");

            return null;
        }

        arsort($counts);
        $family = (string) array_key_first($counts);
        $top = $counts[$family];

        if ($top * 2 <= $mapped) {
            $this->line("  — {$signalId} ({$ruleKey}): declined — no majority family among {$mapped} "
                .'mapped reason(s): '.json_encode($counts)." [{$summary}]");

            return null;
        }

        return [
            'family'     => $family,
            'confidence' => $top / $total,
            // The statement is the counted evidence, verbatim reasons included.
            // It says what was recorded and how often; it does not explain.
            'statement'  => sprintf(
                '%d of %d recorded cancellation reasons on this signal point to %s (reasons recorded: %s)',
                $top, $total, $family, $summary
            ),
        ];
    }
}
