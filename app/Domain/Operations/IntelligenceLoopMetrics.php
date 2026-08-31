<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE STATE OF THE INTELLIGENCE LIFECYCLE, STAGE BY STAGE — and what each empty
 * stage actually means.
 *
 * WHAT WAS WRONG WITH THE COUNTS ALONE. The loop was published as nine numbers in
 * a row: records, signals, evidence, cases, recommendations, decisions, executions,
 * outcomes, learnings. On an organization mid-way through its first week that read
 *
 *     225,103 · 5 · 21 · 5 · 0 · 0 · 0 · 0 · 0
 *
 * and the six zeroes were, to a reader, indistinguishable from a broken product.
 * They were nothing of the sort. No recommendation had been made because five
 * cases were still open and none had a hypothesis; no decision existed because
 * there was nothing to decide on; no outcome existed because nothing had been
 * executed. Every zero was CORRECT AND EXPLAINABLE, and the screen explained none
 * of them.
 *
 * So each stage below carries a `state` and a `message` alongside its count:
 *
 *   flowing   — the stage has output and the one before it does too
 *   ready     — empty, but everything it needs exists; this is where work is
 *   waiting   — empty because the stage before it is empty; not a fault
 *   dormant   — nothing upstream at all
 *
 * "No approved decisions yet — five investigations are open and none has reached a
 * hypothesis" is a status report. "0" is a defect report about a product that is
 * working correctly.
 *
 * NOTHING IS INVENTED TO FILL A STAGE. A count of zero stays a count of zero; only
 * the words beside it change. See OperationalIntelligence for the same rule applied
 * to derived measures.
 *
 * TENANT-SCOPED AND CHEAP. Every query is a COUNT or a small GROUP BY on an
 * indexed tenant column, and the largest result set is one row per severity.
 */
final class IntelligenceLoopMetrics
{
    /** How many rows the "recent" and "top" lists carry. */
    private const LIMIT = 12;

    /**
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId): array
    {
        $signals = $this->signals($tenantId);
        $evidence = $this->evidence($tenantId);
        $cases = $this->cases($tenantId);
        $downstream = $this->downstream($tenantId);

        return [
            'tenantId' => $tenantId,
            'signals' => $signals,
            'evidence' => $evidence,
            'cases' => $cases,
            'stages' => $this->stages($signals, $evidence, $cases, $downstream),
            'derivation' => [
                'method' => 'Counts and distributions over this organization\'s own signal, evidence and case rows.',
                'emptiness' => 'A stage with no rows is reported as empty with the reason it is empty. No stage is populated to avoid showing a zero.',
                'scope' => 'Every query is filtered to tenant_id = '.$tenantId.'.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signals(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_signals')) {
            return ['total' => 0, 'supported' => false, 'reason' => 'This installation has no signal store.'];
        }

        $rows = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->select('id', 'rule_key', 'severity', 'priority', 'classification', 'status', 'confidence', 'department_id', 'related_entity_type', 'related_entity_id', 'created_date', 'updated_date', 'metadata')
            ->orderByDesc('created_date')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'total' => 0,
                'supported' => false,
                'reason' => 'No detector has fired for this organization yet. Signals appear once operational data is ingested and the rule set runs.',
                'bySeverity' => [],
                'byClassification' => [],
                'byStatus' => [],
                'recent' => [],
            ];
        }

        $resolvedStates = ['resolved', 'closed', 'dismissed'];

        $bySeverity = [];
        $byClassification = [];
        $byStatus = [];
        $open = 0;
        $resolved = 0;
        $highOpen = 0;
        $confidenceSum = 0.0;
        $confidenceCount = 0;
        $detail = [];

        // One query for every signal's evidence count rather than one per signal.
        $evidenceCounts = Schema::hasTable('hpbrain_evidence')
            ? DB::table('hpbrain_evidence')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('signal_id')
                ->selectRaw('signal_id, COUNT(*) AS n')
                ->groupBy('signal_id')
                ->pluck('n', 'signal_id')
                ->all()
            : [];

        $caseCounts = Schema::hasTable('hpbrain_cases')
            ? DB::table('hpbrain_cases')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('signal_id')
                ->selectRaw('signal_id, COUNT(*) AS n')
                ->groupBy('signal_id')
                ->pluck('n', 'signal_id')
                ->all()
            : [];

        foreach ($rows as $row) {
            $severity = strtolower((string) ($row->severity ?? 'unknown'));
            $classification = (string) ($row->classification ?? 'unclassified');
            $status = strtolower((string) ($row->status ?? 'unknown'));

            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
            $byClassification[$classification] = ($byClassification[$classification] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $isResolved = in_array($status, $resolvedStates, true);
            $isResolved ? $resolved++ : $open++;

            if (! $isResolved && in_array($severity, ['high', 'critical'], true)) {
                $highOpen++;
            }

            if ($row->confidence !== null) {
                $confidenceSum += (float) $row->confidence;
                $confidenceCount++;
            }

            $meta = $this->decode($row->metadata);
            $evidenceCount = (int) ($evidenceCounts[$row->id] ?? 0);

            $detail[] = [
                'id' => (string) $row->id,
                'ruleKey' => (string) ($row->rule_key ?? ''),
                'title' => $this->titleFor((string) ($row->rule_key ?? ''), $classification),
                'classification' => $classification,
                'severity' => $severity,
                'priority' => strtolower((string) ($row->priority ?? 'medium')),
                'status' => $status,
                'resolved' => $isResolved,
                'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                'evidenceCount' => $evidenceCount,
                'grounded' => $evidenceCount > 0,
                'caseCount' => (int) ($caseCounts[$row->id] ?? 0),
                'departmentId' => $row->department_id === null ? null : (string) $row->department_id,
                'relatedEntityType' => $row->related_entity_type === null ? null : (string) $row->related_entity_type,
                'relatedEntityId' => $row->related_entity_id === null ? null : (string) $row->related_entity_id,
                'firstSeenAt' => (string) $row->created_date,
                'lastSeenAt' => (string) ($meta['lastSeenAt'] ?? $row->updated_date),
                'ageDays' => $this->ageDays((string) $row->created_date),
                /*
                  THE DETECTOR'S OWN NUMBERS, PASSED THROUGH UNCHANGED.

                  Every rule writes what it measured into `metadata` — the share
                  that breached, the zone that over-indexed, the subscribers who
                  came back. That is the only place in the product where a signal
                  can say WHAT HAPPENED rather than merely that something did, and
                  it was being thrown away at the API boundary. Selected keys are
                  republished verbatim; nothing here computes or rephrases them.
                */
                'measurements' => $this->measurements($meta),
                'scope' => $this->scopeOf($meta),
            ];
        }

        arsort($bySeverity);
        arsort($byClassification);
        arsort($byStatus);

        $total = count($detail);

        return [
            'supported' => true,
            'reason' => null,
            'total' => $total,
            'open' => $open,
            'resolved' => $resolved,
            'highSeverityOpen' => $highOpen,
            'grounded' => count(array_filter($detail, fn ($s) => $s['grounded'])),
            'ungrounded' => count(array_filter($detail, fn ($s) => ! $s['grounded'])),
            'underInvestigation' => count(array_filter($detail, fn ($s) => $s['caseCount'] > 0)),
            'resolutionRate' => $total > 0 ? round($resolved / $total, 4) : null,
            'groundedRate' => $total > 0 ? round(count(array_filter($detail, fn ($s) => $s['grounded'])) / $total, 4) : null,
            'averageConfidence' => $confidenceCount > 0 ? round($confidenceSum / $confidenceCount, 4) : null,
            'bySeverity' => $this->distribution($bySeverity, $total),
            'byClassification' => $this->distribution($byClassification, $total),
            'byStatus' => $this->distribution($byStatus, $total),
            'distinctRules' => count(array_unique(array_column($detail, 'ruleKey'))),
            'signals' => $detail,
            'recent' => array_slice($detail, 0, self::LIMIT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_evidence')) {
            return ['total' => 0, 'supported' => false, 'reason' => 'This installation has no evidence store.'];
        }

        $rows = DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenantId)
            ->select('id', 'signal_id', 'source', 'evidence_type', 'status', 'confidence', 'created_date', 'observed_date', 'content')
            ->orderByDesc('created_date')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'total' => 0,
                'supported' => false,
                'reason' => 'No evidence has been recorded. Evidence is written by the detectors that raise signals, so it appears with the first grounded signal.',
                'byType' => [],
                'bySource' => [],
                'recent' => [],
            ];
        }

        $byType = [];
        $bySource = [];
        $byStatus = [];
        $perSignal = [];
        $confidenceSum = 0.0;
        $confidenceCount = 0;
        $newest = null;
        $oldest = null;
        $detail = [];

        foreach ($rows as $row) {
            $type = (string) ($row->evidence_type ?? 'unspecified');
            $content = $this->decode($row->content);
            $source = (string) ($content['source'] ?? ($row->source ?? 'unspecified'));
            $status = strtolower((string) ($row->status ?? 'unknown'));

            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            if ($row->signal_id !== null) {
                $perSignal[(string) $row->signal_id] = ($perSignal[(string) $row->signal_id] ?? 0) + 1;
            }

            if ($row->confidence !== null) {
                $confidenceSum += (float) $row->confidence;
                $confidenceCount++;
            }

            $created = (string) $row->created_date;
            $newest = $newest === null || $created > $newest ? $created : $newest;
            $oldest = $oldest === null || $created < $oldest ? $created : $oldest;

            $detail[] = [
                'id' => (string) $row->id,
                'signalId' => $row->signal_id === null ? null : (string) $row->signal_id,
                'type' => $type,
                'source' => $source,
                'status' => $status,
                'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 4),
                'recordedAt' => $created,
                'observedAt' => $row->observed_date === null ? null : (string) $row->observed_date,
                'ageDays' => $this->ageDays($created),
                /*
                  The evidence body is a JSON object written by the detector,
                  naming the exact source row it read. Republished as key/value
                  pairs so a reader can see the observation rather than a blob,
                  and so "grounded" can be verified rather than trusted.
                */
                'facts' => $this->facts($content),
            ];
        }

        arsort($byType);
        arsort($bySource);
        arsort($byStatus);

        $total = count($detail);
        $signalsWithEvidence = count($perSignal);

        return [
            'supported' => true,
            'reason' => null,
            'total' => $total,
            'signalsCovered' => $signalsWithEvidence,
            'perSignalAverage' => $signalsWithEvidence > 0 ? round($total / $signalsWithEvidence, 2) : null,
            'bestSupportedSignal' => $perSignal === [] ? null : [
                'signalId' => (string) array_search(max($perSignal), $perSignal, true),
                'evidenceCount' => max($perSignal),
            ],
            'averageConfidence' => $confidenceCount > 0 ? round($confidenceSum / $confidenceCount, 4) : null,
            'newestAt' => $newest,
            'oldestAt' => $oldest,
            'freshnessDays' => $newest === null ? null : $this->ageDays($newest),
            'byType' => $this->distribution($byType, $total),
            'bySource' => $this->distribution($bySource, $total),
            'byStatus' => $this->distribution($byStatus, $total),
            'perSignal' => $perSignal,
            'evidence' => $detail,
            'recent' => array_slice($detail, 0, self::LIMIT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cases(string $tenantId): array
    {
        if (! Schema::hasTable('hpbrain_cases')) {
            return ['total' => 0, 'supported' => false, 'reason' => 'This installation has no case store.'];
        }

        $rows = DB::table('hpbrain_cases')
            ->where('tenant_id', $tenantId)
            ->select('id', 'signal_id', 'title', 'description', 'status', 'resolved_hypothesis_id', 'created_by', 'created_date', 'updated_date')
            ->orderByDesc('created_date')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'total' => 0,
                'supported' => false,
                'reason' => 'No investigation has been opened. A case is opened against a signal, so this fills as signals are triaged.',
                'byStatus' => [],
                'cases' => [],
            ];
        }

        $linkedEvidence = Schema::hasTable('hpbrain_case_evidence')
            ? DB::table('hpbrain_case_evidence')
                ->where('tenant_id', $tenantId)
                ->selectRaw('case_id, COUNT(*) AS n')
                ->groupBy('case_id')
                ->pluck('n', 'case_id')
                ->all()
            : [];

        $linkedSignals = Schema::hasTable('hpbrain_case_signals')
            ? DB::table('hpbrain_case_signals')
                ->where('tenant_id', $tenantId)
                ->selectRaw('case_id, COUNT(*) AS n')
                ->groupBy('case_id')
                ->pluck('n', 'case_id')
                ->all()
            : [];

        $hypotheses = Schema::hasTable('hpbrain_hypotheses')
            ? DB::table('hpbrain_hypotheses')
                ->where('tenant_id', $tenantId)
                ->selectRaw('case_id, COUNT(*) AS n')
                ->groupBy('case_id')
                ->pluck('n', 'case_id')
                ->all()
            : [];

        // Signal severity carries over to the case it was opened for. A case has
        // no severity column of its own, and inventing one would be a judgement
        // this class is not entitled to make.
        $signalSeverity = Schema::hasTable('hpbrain_signals')
            ? DB::table('hpbrain_signals')
                ->where('tenant_id', $tenantId)
                ->pluck('severity', 'id')
                ->all()
            : [];

        $signalRule = Schema::hasTable('hpbrain_signals')
            ? DB::table('hpbrain_signals')
                ->where('tenant_id', $tenantId)
                ->pluck('rule_key', 'id')
                ->all()
            : [];

        $byStatus = [];
        $bySeverity = [];
        $detail = [];
        $openStates = ['open', 'new', 'investigating', 'triage', 'in_progress'];

        foreach ($rows as $row) {
            $status = strtolower((string) ($row->status ?? 'unknown'));
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $severity = strtolower((string) ($signalSeverity[$row->signal_id] ?? 'unknown'));
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;

            $evidenceCount = (int) ($linkedEvidence[$row->id] ?? 0);
            $hypothesisCount = (int) ($hypotheses[$row->id] ?? 0);

            $detail[] = [
                'id' => (string) $row->id,
                'title' => (string) ($row->title ?? 'Untitled investigation'),
                'description' => (string) ($row->description ?? ''),
                'status' => $status,
                'open' => in_array($status, $openStates, true),
                'severity' => $severity,
                'signalId' => $row->signal_id === null ? null : (string) $row->signal_id,
                'ruleKey' => (string) ($signalRule[$row->signal_id] ?? ''),
                'attachedEvidence' => $evidenceCount,
                'linkedSignals' => (int) ($linkedSignals[$row->id] ?? ($row->signal_id !== null ? 1 : 0)),
                'hypotheses' => $hypothesisCount,
                'resolvedHypothesisId' => $row->resolved_hypothesis_id === null ? null : (string) $row->resolved_hypothesis_id,
                'openedAt' => (string) $row->created_date,
                'lastMovedAt' => (string) $row->updated_date,
                'ageDays' => $this->ageDays((string) $row->created_date),
                'openedBy' => (string) ($row->created_by ?? 'unknown'),
                /*
                  WHERE THE INVESTIGATION ACTUALLY IS — not a claim that it is
                  finished. A case is only reported resolved when the row says so.
                */
                'stage' => $row->resolved_hypothesis_id !== null
                    ? 'root cause identified'
                    : ($hypothesisCount > 0 ? 'hypotheses under test' : 'awaiting hypothesis'),
            ];
        }

        arsort($byStatus);
        arsort($bySeverity);

        $total = count($detail);
        $open = count(array_filter($detail, fn ($c) => $c['open']));

        return [
            'supported' => true,
            'reason' => null,
            'total' => $total,
            'open' => $open,
            'closed' => $total - $open,
            'withHypothesis' => count(array_filter($detail, fn ($c) => $c['hypotheses'] > 0)),
            'withResolvedCause' => count(array_filter($detail, fn ($c) => $c['resolvedHypothesisId'] !== null)),
            'awaitingHypothesis' => count(array_filter($detail, fn ($c) => $c['hypotheses'] === 0)),
            'averageAgeDays' => $total > 0 ? round(array_sum(array_column($detail, 'ageDays')) / $total, 1) : null,
            'oldestOpenDays' => $open > 0 ? max(array_column(array_filter($detail, fn ($c) => $c['open']), 'ageDays')) : null,
            'byStatus' => $this->distribution($byStatus, $total),
            'bySeverity' => $this->distribution($bySeverity, $total),
            'cases' => $detail,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function downstream(string $tenantId): array
    {
        $tables = [
            'hypotheses' => 'hpbrain_hypotheses',
            'recommendations' => 'hpbrain_recommendations',
            'decisions' => 'hpbrain_decisions',
            'executions' => 'hpbrain_eso_executions',
            'outcomes' => 'hpbrain_outcomes',
            'learnings' => 'hpbrain_learnings',
        ];

        $out = [];

        foreach ($tables as $key => $table) {
            $out[$key] = Schema::hasTable($table)
                ? DB::table($table)->where('tenant_id', $tenantId)->count()
                : 0;
        }

        $out['approvedDecisions'] = Schema::hasTable('hpbrain_decisions')
            ? DB::table('hpbrain_decisions')->where('tenant_id', $tenantId)->whereIn('status', ['approved', 'accepted'])->count()
            : 0;

        return $out;
    }

    /**
     * Each stage, with the reason it is where it is.
     *
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $cases
     * @param  array<string, int>  $downstream
     * @return array<int, array<string, mixed>>
     */
    private function stages(array $signals, array $evidence, array $cases, array $downstream): array
    {
        $signalTotal = (int) ($signals['total'] ?? 0);
        $evidenceTotal = (int) ($evidence['total'] ?? 0);
        $caseTotal = (int) ($cases['total'] ?? 0);
        $withHypothesis = (int) ($cases['withHypothesis'] ?? 0);

        $stage = function (string $key, string $label, int $count, bool $upstreamReady, string $whenFlowing, string $whenReady, string $whenWaiting): array {
            $state = $count > 0 ? 'flowing' : ($upstreamReady ? 'ready' : 'waiting');

            return [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'state' => $state,
                'message' => $count > 0 ? $whenFlowing : ($upstreamReady ? $whenReady : $whenWaiting),
            ];
        };

        return [
            $stage('signals', 'Signals', $signalTotal, true,
                $signalTotal.' pattern'.($signalTotal === 1 ? '' : 's').' detected in the connected data.',
                'Detectors are configured but nothing has crossed a threshold.',
                'No operational data has been ingested for detectors to read.'),

            $stage('evidence', 'Evidence', $evidenceTotal, $signalTotal > 0,
                $evidenceTotal.' observations recorded against '.(int) ($evidence['signalsCovered'] ?? 0).' signals.',
                'Signals exist but none has been grounded in a source record yet.',
                'Evidence is written alongside signals; none exist to ground.'),

            $stage('cases', 'Investigations', $caseTotal, $signalTotal > 0,
                $caseTotal.' investigation'.($caseTotal === 1 ? '' : 's').' open against detected signals.',
                'Signals are waiting to be triaged into investigations.',
                'An investigation is opened against a signal; none exist yet.'),

            $stage('hypotheses', 'Hypotheses', (int) $downstream['hypotheses'], $caseTotal > 0,
                (int) $downstream['hypotheses'].' candidate explanations under test.',
                'Ready for deliberation — '.$caseTotal.' investigation'.($caseTotal === 1 ? ' is' : 's are').' open with no proposed cause yet.',
                'A hypothesis belongs to an investigation; none is open.'),

            $stage('recommendations', 'Recommendations', (int) $downstream['recommendations'], $withHypothesis > 0,
                (int) $downstream['recommendations'].' grounded actions proposed.',
                'Investigations have reached a hypothesis and are ready to produce actions.',
                'A recommendation follows from a tested hypothesis; none has been recorded.'),

            $stage('decisions', 'Decisions', (int) $downstream['decisions'], (int) $downstream['recommendations'] > 0,
                (int) $downstream['decisions'].' decisions recorded, '.(int) $downstream['approvedDecisions'].' approved.',
                'Recommendations are waiting on an approve or reject.',
                'No approved decisions yet — nothing has been recommended to decide on.'),

            $stage('executions', 'Executions', (int) $downstream['executions'], (int) $downstream['approvedDecisions'] > 0,
                (int) $downstream['executions'].' actions carried out.',
                'Approved decisions are ready to be executed.',
                'Execution follows an approved decision; none has been approved.'),

            $stage('outcomes', 'Outcomes', (int) $downstream['outcomes'], (int) $downstream['executions'] > 0,
                (int) $downstream['outcomes'].' measured results recorded.',
                'Executions are complete and their effect is ready to be measured.',
                'An outcome measures an execution; none has run.'),

            $stage('learnings', 'Learning', (int) $downstream['learnings'], (int) $downstream['outcomes'] > 0,
                (int) $downstream['learnings'].' reusable lessons captured.',
                'Outcomes are recorded and ready to be turned into reusable guidance.',
                'Learning is distilled from measured outcomes; none exists yet.'),
        ];
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    /**
     * @param  array<string, int>  $counts
     * @return array<int, array<string, mixed>>
     */
    private function distribution(array $counts, int $total): array
    {
        $out = [];

        foreach ($counts as $name => $count) {
            $out[] = [
                'name' => (string) $name,
                'label' => $this->humanise((string) $name),
                'count' => $count,
                'share' => $total > 0 ? round($count / $total, 4) : null,
            ];
        }

        return $out;
    }

    /**
     * The numeric findings a detector recorded, as label/value pairs.
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, array<string, mixed>>
     */
    private function measurements(array $meta): array
    {
        $interesting = [
            'affectedCount' => 'Affected',
            'breachedCount' => 'Breached',
            'totalCount' => 'Population',
            'breachShare' => 'Breach share',
            'thresholdHours' => 'Threshold (hours)',
            'threshold' => 'Threshold',
            'zone' => 'Concentrated in',
            'zoneCount' => 'Records in that area',
            'zoneShare' => 'Share of the period',
            'expectedShare' => 'Expected share',
            'worstCaseCount' => 'Worst single case',
            'period' => 'Period',
            'firstCount' => 'First observed count',
        ];

        $out = [];

        foreach ($interesting as $key => $label) {
            if (! array_key_exists($key, $meta) || $meta[$key] === null || is_array($meta[$key])) {
                continue;
            }

            $value = $meta[$key];

            $out[] = [
                'key' => $key,
                'label' => $label,
                'value' => is_bool($value) ? ($value ? 'yes' : 'no') : $value,
                // A key ending in "Share" is a proportion the detector wrote as a
                // fraction; saying so lets the client format it without guessing.
                'format' => str_ends_with($key, 'Share') ? 'ratio' : (is_numeric($value) ? 'number' : 'text'),
            ];
        }

        return $out;
    }

    /**
     * The named things a detector pointed at — areas, subjects, samples.
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, array<string, mixed>>
     */
    private function scopeOf(array $meta): array
    {
        $out = [];

        foreach (['topZones', 'sampleSubjects', 'topCategories', 'sampleIds'] as $key) {
            if (! isset($meta[$key]) || ! is_array($meta[$key])) {
                continue;
            }

            foreach (array_slice($meta[$key], 0, 6) as $entry) {
                if (is_array($entry)) {
                    $name = (string) ($entry['label'] ?? $entry['subject'] ?? $entry['name'] ?? '');
                    $count = $entry['total'] ?? $entry['complaints'] ?? $entry['count'] ?? null;
                } else {
                    $name = (string) $entry;
                    $count = null;
                }

                if ($name === '') {
                    continue;
                }

                $out[] = ['group' => $key, 'name' => $name, 'count' => $count === null ? null : (int) $count];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, array<string, mixed>>
     */
    private function facts(array $content): array
    {
        $out = [];

        foreach ($content as $key => $value) {
            if (is_array($value) || $value === null || $value === '') {
                continue;
            }

            $out[] = ['key' => $key, 'label' => $this->humanise((string) $key), 'value' => $value];

            if (count($out) >= 10) {
                break;
            }
        }

        return $out;
    }

    /**
     * A readable title from a rule key, which is all a signal has.
     *
     * `complaint_sla_breach` under classification `service_quality` becomes
     * "Complaint SLA Breach". The rule key is the detector's own name for what it
     * found; nothing is added to it.
     */
    private function titleFor(string $ruleKey, string $classification): string
    {
        if ($ruleKey === '') {
            return $this->humanise($classification);
        }

        return $this->humanise($ruleKey);
    }

    private function humanise(string $value): string
    {
        $spaced = trim(str_replace(['_', '-', '.'], ' ', $value));

        if ($spaced === '') {
            return 'Unspecified';
        }

        $words = array_map(function (string $word): string {
            // Acronyms the source already wrote in capitals stay in capitals.
            return in_array(strtolower($word), ['sla', 'kpi', 'id', 'crf', 'it', 'gps', 'kyc', 'kra'], true)
                ? strtoupper($word)
                : mb_convert_case($word, MB_CASE_TITLE, 'UTF-8');
        }, preg_split('/\s+/', $spaced) ?: [$spaced]);

        return implode(' ', $words);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function ageDays(?string $timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        $at = strtotime($timestamp);

        return $at === false ? null : (int) max(0, floor((time() - $at) / 86400));
    }
}
