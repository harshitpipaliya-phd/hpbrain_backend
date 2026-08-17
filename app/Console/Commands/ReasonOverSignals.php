<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Undetermined\VerbResult;
use App\Domain\Universal\EntityResolver;
use App\Domain\Verbs\ExplainVerb;
use App\Domain\Verbs\RecommendVerb;
use App\Repositories\SignalRepository;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run reasoning across every 'new' or 'triaged' signal, every tenant.
 *
 * WHAT CHANGED, AND WHY IT IS THE WHOLE POINT OF THIS COMMAND.
 *
 * This command used to call SignalReasoner, a second reasoning path that
 * reached the provider directly and wrote a recommendation from whatever came
 * back. That path has no governance pre-check, no UODM sufficiency gate and no
 * GroundedClaims guardrail — so a model citing evidence it was never shown, or
 * telling somebody to intervene without naming an executable operation, was
 * written to hpbrain_recommendations exactly as if it had been checked. It also
 * meant the build had TWO reasoning implementations, of which only one is
 * covered by GoldenIntelligenceFlowTest.
 *
 * It now routes through the same ExplainVerb → RecommendVerb pipeline the HTTP
 * API uses and the golden test proves. SignalReasoner is left in place and is
 * no longer called from anywhere.
 *
 * EXPLAIN GATES RECOMMEND, deliberately. EXPLAIN costs nothing — it assembles
 * the seven-question UODM frame out of rows we already hold and calls no model.
 * RECOMMEND costs real money on every call. Running the free verb first means a
 * signal the Brain cannot even frame never reaches the provider: an UNDETERMINED
 * EXPLAIN names exactly which questions are unanswered, which is both the honest
 * answer and a better use of the budget than paying a model to reason over a
 * gap we have already identified.
 *
 * THE ADAPTER. The verbs take (tenantId, signalId, actorId, role) because over
 * HTTP those come from the token. A console run has no token, so the two values
 * are supplied below as constants — this is the entire adaptation, and no verb
 * changes to accommodate it. Both verbs re-check the role in their own
 * governance callable, so the console is authorized by exactly the same rule as
 * a request.
 *
 * COST IS BOUNDED BY --limit AND NOTHING ELSE, stated plainly because the
 * previous docblock claimed otherwise. Reasoning does not change a signal's
 * status, and a recommendation written by RECOMMEND carries no signal_id, so
 * this command cannot currently tell whether it has already reasoned over a
 * given signal. A second run WILL spend again on the same signals. Keep --limit
 * small until that link exists.
 */
final class ReasonOverSignals extends Command
{
    /**
     * Who the Brain acts as when nobody asked.
     *
     * A synthetic id rather than a borrowed human one: this is stamped on
     * hpbrain_recommendations.created_by and hpbrain_ai_executions.user_id, and
     * attributing an unattended batch run to a real person would make the audit
     * trail say something false. No foreign key points at these columns, so the
     * id does not need a row in hpbrain_auth_users — and should not have one.
     */
    private const ACTOR = 'brain-reason-signals';

    /**
     * ANALYST, and not higher. It is the least-privileged role that grants both
     * READ (which EXPLAIN requires) and CREATE (which RECOMMEND requires), and
     * it grants neither decision.approve nor eso.execute — so no scheduled run
     * of this command can approve its own recommendation or start an execution.
     */
    private const ROLE = 'analyst';

    protected $signature = 'brain:reason-signals
        {--tenant= : Reason over one tenant instead of all}
        {--signal= : Reason over one specific signal, ignoring --limit}
        {--limit=20 : Max signals to process per tenant per run, to bound real API cost}';

    protected $description = 'Reason over pending signals through the EXPLAIN → RECOMMEND verb pipeline';

    public function handle(
        EntityResolver $resolver,
        SignalRepository $signals,
        ExplainVerb $explain,
        RecommendVerb $recommend,
    ): int {
        $only = $this->option('tenant');
        $onlySignal = $this->option('signal');
        $limit = (int) $this->option('limit');

        $tenantIds = array_keys($resolver->everyTenantWith('Person'));
        $failures = 0;
        $totals = ['explained' => 0, 'unframed' => 0, 'recommended' => 0, 'undetermined' => 0];

        foreach ($tenantIds as $tenantId) {
            $tenantId = (string) $tenantId;

            if ($only !== null && $tenantId !== $only) {
                continue;
            }

            try {
                // --signal names one signal and takes it whole; otherwise the
                // repository selects the batch, rule-derived signals first (see
                // SignalRepository::pendingForReasoning for why the ordering is
                // not simply newest-first).
                $pending = $onlySignal !== null
                    ? $this->named($signals, $tenantId, $onlySignal)
                    : $signals->pendingForReasoning($tenantId, $limit);

                if ($onlySignal !== null && $pending === []) {
                    continue;
                }

                $this->info("Tenant {$tenantId}: ".count($pending).' signals to reason over '
                    .($onlySignal !== null ? '(named signal)' : "(limit {$limit})"));

                foreach ($pending as $signal) {
                    $failures += $this->reasonOver(
                        $explain, $recommend, $tenantId, (string) $signal['id'], $totals
                    );
                }
            } catch (Throwable $e) {
                // One tenant's failure must not stop the others — same
                // discipline as DetectSignals.
                $failures++;
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. %d framed, %d unframed (EXPLAIN undetermined), %d recommended, %d undetermined at RECOMMEND.',
            $totals['explained'], $totals['unframed'], $totals['recommended'], $totals['undetermined']
        ));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The named signal, if it belongs to this tenant and is still pending.
     *
     * The status check is kept: --signal is for reaching a signal a batch would
     * not, not for reasoning over one somebody has already resolved or
     * dismissed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function named(SignalRepository $signals, string $tenantId, string $signalId): array
    {
        $signal = $signals->findById($tenantId, $signalId);

        return $signal !== null && in_array($signal['status'] ?? '', ['new', 'triaged'], true)
            ? [$signal]
            : [];
    }

    /**
     * One signal, through both verbs. Returns the number of FAULTS — which is
     * zero for an undetermined result, because "I do not know" is an answer and
     * a batch full of honest ones is a successful run, not a failed one.
     *
     * @param  array{explained: int, unframed: int, recommended: int, undetermined: int}  $totals
     */
    private function reasonOver(
        ExplainVerb $explain,
        RecommendVerb $recommend,
        string $tenantId,
        string $signalId,
        array &$totals,
    ): int {
        try {
            $explained = $explain->run($tenantId, $signalId, self::ACTOR, self::ROLE);
        } catch (Throwable $e) {
            // VerbPipeline throws for governance denial; anything else is a real
            // fault. Either way this signal is reported and the batch continues.
            $this->line("  ! {$signalId} → EXPLAIN failed: {$e->getMessage()}");

            return 1;
        }

        if ($explained->isUndetermined()) {
            // The gate. No provider call, no spend, and the gaps say what would
            // change the answer.
            $totals['unframed']++;
            $this->line("  — {$signalId} → EXPLAIN UNDETERMINED ({$this->gaps($explained)}) — RECOMMEND not called");

            return 0;
        }

        $totals['explained']++;
        $rootCause = $this->value($explained, 'rootCause') ?? 'unstated';
        $this->line("  ✓ {$signalId} → EXPLAIN DECIDED (root cause: {$rootCause}, evidence: "
            .count($explained->evidenceRefs).')');

        try {
            $recommended = $recommend->run($tenantId, $signalId, self::ACTOR, self::ROLE);
        } catch (Throwable $e) {
            $this->line("    ! RECOMMEND failed: {$e->getMessage()}");

            return 1;
        }

        if ($recommended->isUndetermined()) {
            $totals['undetermined']++;
            $this->line("    — RECOMMEND UNDETERMINED ({$this->gaps($recommended)})");

            return 0;
        }

        $totals['recommended']++;

        foreach ((array) ($this->value($recommended, 'recommendations') ?? []) as $row) {
            $this->line(sprintf(
                '    → %s [%s/%s, confidence %s] %s',
                $row['id'], $row['category'], $row['priority'],
                $row['confidence'] ?? 'null', $row['title']
            ));
        }

        // Surfaced on success too: a run that wrote two good recommendations and
        // silently dropped a hallucinated third must say so.
        $dropped = (array) ($this->value($recommended, 'droppedClaims') ?? []);

        if ($dropped !== []) {
            $this->line('    ⚠ GroundedClaims dropped: '.implode(', ', $dropped));
        }

        return 0;
    }

    private function gaps(VerbResult $result): string
    {
        return implode(', ', $result->gaps);
    }

    private function value(VerbResult $result, string $key): mixed
    {
        return is_array($result->value) ? ($result->value[$key] ?? null) : null;
    }
}
