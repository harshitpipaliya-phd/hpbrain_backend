<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cases\CaseSignalLinker;
use App\Domain\Universal\EntityResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Open an investigation case for each qualifying signal.
 *
 * WHY THIS IS SAFE, AND WHERE THE LINE IS. A case CLAIMS NOTHING. It is a
 * container that says "this signal is worth looking into", pointing at a real
 * signal by foreign key — exactly the row GoldenIntelligenceFlowTest creates by
 * hand before it asks EXPLAIN anything. Every field below is copied from a
 * column or left null; there is no diagnosis, no root cause and no
 * interpretation anywhere in this file. The moment a row would need to say WHY
 * something is happening, it stops being a case and becomes a hypothesis, and
 * hypotheses are not created here or anywhere else automatically.
 *
 * WHAT QUALIFIES, AND WHY EACH TEST IS THERE
 *
 *   1. STATUS is 'new' or 'triaged'. A resolved or dismissed signal has already
 *      been dealt with; opening an investigation into it would be manufacturing
 *      work rather than finding it.
 *
 *   2. rule_key IS NOT NULL — the signal was DERIVED by a rule, not uploaded.
 *      This is the load-bearing filter. Most signals in this database are not
 *      findings at all: they are spreadsheet rows turned one-for-one into
 *      signals by the ingestion pipeline (1,499 in one tenant from a single fees
 *      workbook). Opening an investigation case per imported row would bury the
 *      handful of real findings under thousands of containers nobody asked for,
 *      and a case queue that cannot be read is worse than no case queue. A rule
 *      firing is the Brain asserting it noticed something; that is the event
 *      worth investigating.
 *
 *   3. THE SIGNAL HAS EVIDENCE. A case is somewhere to put an investigation, and
 *      a signal with no evidence has nothing to investigate yet — EXPLAIN
 *      returns no_grounding_evidence for it regardless of how many cases point
 *      at it. This filter is what keeps the case count honest rather than equal
 *      to the signal count.
 *
 *   4. NO CASE ALREADY POINTS AT IT. Re-running must not stack duplicates, the
 *      same discipline the detectors apply to signals themselves.
 *
 * A QUALIFYING SIGNAL DOES NOT ALWAYS GET ITS OWN CASE. If an open case is
 * already investigating the same problem — same rule_key, same affected party —
 * the signal is ATTACHED to it as a related signal instead. That is the whole
 * behavioural change here, and what it fixes is concrete: detection refreshes
 * rather than duplicates while a signal is unresolved, so a second signal for
 * one rule only appears after the first was resolved or dismissed. That is the
 * recurrence-after-resolution case ReasoningEngineController::earlyWarnings
 * calls the most valuable finding this system produces — and it used to open a
 * second case beside a still-open one, with nothing anywhere recording that the
 * two were about the same thing.
 *
 * ATTACHING NEVER REPOINTS. Only CaseSignalLinker::linkRelated is called;
 * hpbrain_cases.signal_id keeps naming the signal the case was opened for.
 * A case that silently changed what it was about would invalidate every
 * hypothesis already reasoned from it.
 *
 * The title is assembled from real column values only — classification, rule
 * key, affected count — so it reads as a label, not as a conclusion. A generated
 * sentence describing what is wrong would be the model's job, and this command
 * deliberately calls no model.
 */
final class OpenCasesForSignals extends Command
{
    /** Stamped on every row, so auto-opened cases are findable and reversible. */
    private const ACTOR = 'brain-open-cases';

    protected $signature = 'brain:open-cases
        {--tenant= : One tenant instead of all}
        {--limit=50 : Max cases to open per tenant per run}
        {--dry-run : Report what would be opened and write nothing}';

    protected $description = 'Open an investigation case for each rule-derived signal that has evidence and no case';

    public function handle(EntityResolver $resolver, CaseSignalLinker $linker): int
    {
        $only = $this->option('tenant');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $tenantIds = array_keys($resolver->everyTenantWith('Person'));
        $failures = 0;
        $opened = 0;
        $attached = 0;

        foreach ($tenantIds as $tenantId) {
            $tenantId = (string) $tenantId;

            if ($only !== null && $tenantId !== $only) {
                continue;
            }

            try {
                $signals = $this->qualifying($tenantId, $limit);

                $this->info("Tenant {$tenantId}: ".count($signals).' signal(s) qualify'
                    .($dryRun ? ' (dry run — nothing written)' : ''));

                foreach ($signals as $signal) {
                    $title = $this->title($signal);

                    // Is this the same problem an open case is already about?
                    $existing = $this->openCaseForSameProblem($tenantId, $signal);

                    if ($dryRun) {
                        $this->line($existing === null
                            ? "  · would open: {$title}"
                            : "  · would attach to {$existing['caseId']} ({$existing['scope']}): {$title}");

                        continue;
                    }

                    if ($existing !== null) {
                        // linkRelated, never linkPrimary: the case keeps the
                        // signal it was opened for, and this one joins it.
                        $linker->linkRelated($tenantId, $existing['caseId'], (string) $signal->id, self::ACTOR);
                        $attached++;
                        $this->line("  ↳ {$existing['caseId']} ← {$signal->id}  attached as related "
                            ."({$existing['scope']})");

                        continue;
                    }

                    $caseId = $this->open($linker, $tenantId, $signal, $title);
                    $opened++;
                    $this->line("  + {$caseId} → {$signal->id}  {$title}");
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? 'Dry run complete.'
            : "Done. {$opened} case(s) opened, {$attached} signal(s) attached to existing cases.");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The qualifying signals, oldest first.
     *
     * Oldest first because a problem that has been open longest is the one an
     * investigation queue should surface first, and because it makes a --limit
     * run deterministic rather than arbitrary.
     *
     * @return array<int, object>
     */
    private function qualifying(string $tenantId, int $limit): array
    {
        return DB::table('hpbrain_signals as s')
            ->where('s.tenant_id', $tenantId)
            ->whereIn('s.status', ['new', 'triaged'])
            ->whereNotNull('s.rule_key')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('hpbrain_evidence as e')
                ->whereColumn('e.signal_id', 's.id')->where('e.tenant_id', $tenantId))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('hpbrain_cases as c')
                ->whereColumn('c.signal_id', 's.id')->where('c.tenant_id', $tenantId))
            // ALSO not already attached to a case as a related signal. Without
            // this a signal that joined an existing case would qualify again on
            // every run — linkRelated is idempotent so nothing would be written
            // twice, but the command would report the same attachment forever
            // and a --limit would be consumed by work already done.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('hpbrain_case_signals as cs')
                ->whereColumn('cs.signal_id', 's.id')->where('cs.tenant_id', $tenantId))
            ->orderBy('s.created_date')
            ->limit($limit)
            ->select(
                's.id', 's.classification', 's.rule_key', 's.source', 's.severity', 's.priority', 's.metadata',
                // The scope columns, so the same-problem test below can read who
                // each signal is about without a second query per signal.
                's.related_entity_type', 's.related_entity_id', 's.department_id', 's.org_id',
            )
            ->get()
            ->all();
    }

    /**
     * An open case already investigating this exact problem, if there is one.
     *
     * WHAT "THE SAME PROBLEM" MEANS, AND WHY IT IS NOT A NEW IDEA. Two things
     * must agree: the RULE that fired, and WHO IT IS ABOUT. The rule is
     * `rule_key` — the Brain's own name for the condition it detected. Who it is
     * about is the scope ladder ExplainVerb::affectedParty already answers
     * `who_is_affected` from, and that SignalSubject was written to populate:
     * related entity first, then department, then organization. Reusing that
     * ladder rather than inventing a comparison means a signal groups with
     * another exactly when EXPLAIN would say they affect the same party.
     *
     * WHY THIS MATTERS AT ALL. Detection refreshes rather than duplicates while
     * a signal is unresolved, so a second signal for the same rule only appears
     * after the first was resolved or dismissed — the recurrence-after-resolution
     * case, which is the finding this product treats as most valuable. Until now
     * that second signal opened its own case beside a still-open one, and nothing
     * recorded that the two were the same problem.
     *
     * A SIGNAL WITH NO SCOPE NEVER GROUPS. All three columns null means we do not
     * know who it affects, and "unknown affects unknown" is not a match — it is
     * two unknowns. Grouping on it would merge unrelated findings on the strength
     * of a shared rule name alone.
     *
     * @return array{caseId: string, scope: string}|null
     */
    private function openCaseForSameProblem(string $tenantId, object $signal): ?array
    {
        $scope = $this->scopeOf($signal);

        if ($scope === null || ($signal->rule_key ?? null) === null) {
            return null;
        }

        $query = DB::table('hpbrain_cases as c')
            // The case's PRIMARY signal — hpbrain_cases.signal_id, which stays
            // authoritative for that. Related signals already attached to the
            // case are deliberately not compared against: the case is about its
            // primary, and chaining off a related one could drift a case away
            // from what it was opened for.
            ->join('hpbrain_signals as p', function ($j) use ($tenantId) {
                $j->on('p.id', '=', 'c.signal_id')->where('p.tenant_id', '=', $tenantId);
            })
            ->where('c.tenant_id', $tenantId)
            // Still being worked. A resolved or closed case is finished, and
            // attaching a fresh recurrence to it would reopen a conclusion by
            // the back door.
            ->whereNotIn('c.status', ['resolved', 'closed'])
            ->where('p.rule_key', $signal->rule_key);

        $this->applyScope($query, $scope);

        // Oldest first: where several open cases somehow share a problem, the
        // one that has been investigating it longest is the one to join.
        $caseId = $query->orderBy('c.created_date')->orderBy('c.id')->value('c.id');

        return $caseId === null
            ? null
            : ['caseId' => (string) $caseId, 'scope' => $scope['label']];
    }

    /**
     * Who a signal is about, by the same ladder ExplainVerb reads.
     *
     * Deliberately `! empty()` rather than `!== null`, matching
     * ExplainVerb::affectedParty: an empty string in one of these columns is an
     * absent answer, not a party named ''.
     *
     * @return array{rung: string, type: string|null, id: string, label: string}|null
     */
    private function scopeOf(object $signal): ?array
    {
        if (! empty($signal->related_entity_id)) {
            $type = (string) ($signal->related_entity_type ?? 'entity');

            return [
                'rung' => 'entity', 'type' => $type, 'id' => (string) $signal->related_entity_id,
                'label' => $type.':'.$signal->related_entity_id,
            ];
        }

        if (! empty($signal->department_id)) {
            return [
                'rung' => 'department', 'type' => null, 'id' => (string) $signal->department_id,
                'label' => 'department:'.$signal->department_id,
            ];
        }

        if (! empty($signal->org_id)) {
            return [
                'rung' => 'organization', 'type' => null, 'id' => (string) $signal->org_id,
                'label' => 'organization:'.$signal->org_id,
            ];
        }

        return null;
    }

    /**
     * Constrain the primary signal `p` to resolve to the SAME rung of the ladder.
     *
     * The higher rungs are required to be absent, not merely different: a signal
     * naming an entity and a signal naming only an organization are about
     * different parties even when the organization matches, because the ladder
     * stops at the first answer it finds. Without the absence checks a specific
     * finding about one person would group with an organization-wide one.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array{rung: string, type: string|null, id: string, label: string}  $scope
     */
    private function applyScope($query, array $scope): void
    {
        $absent = fn ($q, string $column) => $q->where(
            fn ($w) => $w->whereNull($column)->orWhere($column, '')
        );

        match ($scope['rung']) {
            'entity' => $query
                ->where('p.related_entity_id', $scope['id'])
                // COALESCE mirrors affectedParty's `?? 'entity'` fallback, so a
                // signal with an id and no type compares equal to another of the
                // same shape rather than to everything.
                ->whereRaw("coalesce(p.related_entity_type, 'entity') = ?", [$scope['type']]),

            'department' => $absent($query, 'p.related_entity_id')
                ->where('p.department_id', $scope['id']),

            'organization' => $absent($absent($query, 'p.related_entity_id'), 'p.department_id')
                ->where('p.org_id', $scope['id']),
        };
    }

    /**
     * A label, not a claim.
     *
     * The affected count is included when the rule recorded one because "50
     * departments" and "1 department" are different investigations, and it is a
     * number the rule counted rather than one this command reasoned to.
     */
    private function title(object $signal): string
    {
        $metadata = json_decode((string) ($signal->metadata ?? '{}'), true);
        $count = is_array($metadata) ? ($metadata['affectedCount'] ?? null) : null;

        $title = sprintf('%s: %s', (string) $signal->classification, (string) $signal->rule_key);

        if (is_numeric($count)) {
            $title .= sprintf(' (%d affected)', (int) $count);
        }

        return mb_substr($title, 0, 300);
    }

    /**
     * The description states the signal's own facts and nothing else — where it
     * came from, how bad the detector called it, and that no diagnosis has been
     * recorded yet. That last line matters: a case with an empty description
     * looks like one nobody has written up, and this one has simply not been
     * investigated, which is a different thing.
     *
     * THE SIGNAL IS ATTACHED BY CaseSignalLinker, NOT BY THIS INSERT. The linker
     * is the single writer for a case's signal relationships — it writes
     * hpbrain_cases.signal_id and the role='primary' junction row together, so
     * the two records of that one fact cannot drift apart. It updates an
     * existing case rather than creating one, so the row is inserted with a null
     * signal first and linked immediately after, both inside one transaction:
     * a case this command opens either has its signal recorded in both places or
     * was never written at all. A signal-less case would qualify again on the
     * next run and quietly accumulate duplicates for the same signal.
     */
    private function open(CaseSignalLinker $linker, string $tenantId, object $signal, string $title): string
    {
        $caseId = Uuid::uuid4()->toString();
        $now = now()->format('Y-m-d H:i:s');

        DB::transaction(function () use ($linker, $caseId, $tenantId, $signal, $title, $now): void {
            $this->insertCase($caseId, $tenantId, $signal, $title, $now);

            $linker->linkPrimary($tenantId, $caseId, (string) $signal->id, self::ACTOR);
        });

        return $caseId;
    }

    /** The case row itself, with no signal on it yet. */
    private function insertCase(string $caseId, string $tenantId, object $signal, string $title, string $now): void
    {
        DB::table('hpbrain_cases')->insert([
            'id'           => $caseId,
            'tenant_id'    => $tenantId,
            // Deliberately absent: the linker owns this column.
            'signal_id'    => null,
            'title'        => $title,
            'description'  => sprintf(
                'Opened automatically for signal %s (source %s, severity %s, priority %s). '
                .'No hypothesis has been recorded; the root cause is not yet known.',
                $signal->id, (string) $signal->source,
                (string) ($signal->severity ?? 'unstated'), (string) ($signal->priority ?? 'unstated')
            ),
            'status'       => 'open',
            'created_by'   => self::ACTOR,
            'created_date' => $now,
            'updated_date' => $now,
        ]);
    }
}
