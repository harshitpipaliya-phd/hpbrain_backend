<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use App\Domain\Events\EventPublisher;
use App\Domain\Events\LoopEvent;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Writes signals for rules that RuleEvaluator cannot express as a row.
 *
 * WHY THIS EXISTS RATHER THAN SEVEN MORE hpbrain_signal_rules ROWS.
 * RuleEvaluator answers one shape of question: "which rows of an entity match
 * this predicate, and are there more than N of them". That covers every ERP
 * rule it replaced. The operational rules ask a different shape:
 *
 *   - what SHARE of closed complaints have no root cause (a ratio, not a count);
 *   - which zone carries a disproportionate share against an even split
 *     (a GROUP BY compared to a value derived from the grouping itself);
 *   - which subscribers appear four or more times in one month (GROUP BY
 *     ... HAVING, where the affected unit is the group, not the row);
 *   - what fraction of offered help-desk calls were dropped (a ratio between
 *     two columns of the same row).
 *
 * None of those is a predicate over rows, and a `severity` that depends on the
 * ratio cannot be a static column. Expressing them as rows would mean teaching
 * the rule table aggregates, grouping and derived thresholds — a redesign of a
 * core engine, not a port. So the rules stay as code and only their WRITE path
 * is unified here.
 *
 * WHAT IS UNIFIED. Everything that matters downstream:
 *   - the signal carries a real `rule_key`, so scheduled re-detection REFRESHES
 *     an open signal instead of stacking a duplicate every run;
 *   - the signal row is written BEFORE its evidence, so hpbrain_evidence's
 *     foreign key to hpbrain_signals is satisfied on MySQL and MariaDB;
 *   - the same OBSERVATION_MADE event is published in the same transaction.
 *
 * That last pair is why recordEvidence() buffers. The rules call it before
 * raise(), as the deleted SignalGenerator allowed; here the content is held and
 * inserted after the signal exists, so the call sites did not have to change
 * and the ordering bug cannot come back.
 */
final class OperationalSignalWriter
{
    private const ACTOR = 'system';

    /** @var array<string, array<string, mixed>> */
    private array $pending = [];

    public function __construct(
        private readonly EventPublisher $events,
    ) {
    }

    /**
     * Hold evidence content and reserve its id.
     *
     * Nothing is written until raise() has created the signal to attach it to.
     *
     * @param  array<string, mixed>  $content
     */
    public function recordEvidence(string $tenantId, array $content): string
    {
        $evidenceId = Uuid::uuid4()->toString();
        $this->pending[$evidenceId] = $content;

        return $evidenceId;
    }

    /**
     * Raise a signal, or refresh the open one this rule already raised.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $evidenceIds
     * @return array{created: bool, refreshed?: bool, signalId?: string, reason?: string}
     */
    public function raise(string $tenantId, array $data, array $evidenceIds = []): array
    {
        $ruleKey = (string) ($data['metadata']['rule'] ?? '');

        // Same rule, same tenant, still open: this is the ongoing problem, not a
        // new observation. Matching RuleEvaluator, resolved and dismissed
        // signals are NOT matched — a problem that came back is real news.
        $open = $ruleKey !== '' ? $this->openSignalFor($tenantId, $ruleKey) : null;

        if ($open !== null) {
            $this->refreshSignal($tenantId, $open, $data['metadata']);
            $this->discard($evidenceIds);

            return ['created' => false, 'refreshed' => true, 'signalId' => (string) $open->id];
        }

        $signalId = Uuid::uuid4()->toString();
        $rows = $this->take($evidenceIds);

        $this->events->publishInTransaction(
            LoopEvent::OBSERVATION_MADE,
            $tenantId,
            'Signal',
            self::ACTOR,
            [
                'signalId'       => $signalId,
                'source'         => $data['source'],
                'classification' => $data['classification'],
                'priority'       => $data['priority'],
                'severity'       => $data['severity'],
                'evidenceIds'    => array_keys($rows),
            ],
            function () use ($signalId, $tenantId, $data, $ruleKey, $rows) {
                DB::table('hpbrain_signals')->insert([
                    'id'             => $signalId,
                    'tenant_id'      => $tenantId,
                    'source'         => $data['source'],
                    'classification' => $data['classification'],
                    'rule_key'       => $ruleKey !== '' ? $ruleKey : null,
                    'priority'       => $data['priority'],
                    'severity'       => $data['severity'],
                    'confidence'     => $data['confidence'],
                    'metadata'       => json_encode($data['metadata']),
                    'status'         => 'new',
                    'created_by'     => self::ACTOR,
                    'created_date'   => now()->format('Y-m-d H:i:s'),
                ]);

                foreach ($rows as $evidenceId => $content) {
                    $this->insertEvidence($tenantId, $signalId, (string) $evidenceId, $content);
                }

                return ['entityId' => $signalId, 'result' => true];
            },
            correlationId: $signalId,
        );

        return ['created' => true, 'signalId' => $signalId];
    }

    /**
     * The unresolved signal this rule already raised, if there is one.
     *
     * Reads the real rule_key column rather than a JSON path, because
     * JSON_UNQUOTE exists on MySQL and MariaDB and not on the SQLite the suite
     * runs against.
     */
    private function openSignalFor(string $tenantId, string $ruleKey): ?object
    {
        return DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['resolved', 'dismissed'])
            ->where('rule_key', $ruleKey)
            ->orderByDesc('created_date')
            ->first();
    }

    /**
     * Bring an open signal's figures up to date without raising another.
     *
     * created_date is left alone: how long a problem has been open is the most
     * useful fact about it.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function refreshSignal(string $tenantId, object $signal, array $metadata): void
    {
        $previous = json_decode((string) $signal->metadata, true);
        $previous = is_array($previous) ? $previous : [];

        DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->where('id', $signal->id)
            ->update([
                'metadata'     => json_encode(array_merge($previous, $metadata, [
                    'firstCount' => $previous['firstCount'] ?? ($previous['affectedCount'] ?? null),
                    'lastSeenAt' => now()->format('Y-m-d H:i:s'),
                ])),
                'updated_date' => now()->format('Y-m-d H:i:s'),
            ]);
    }

    /** @param array<string, mixed> $content */
    private function insertEvidence(
        string $tenantId,
        string $signalId,
        string $evidenceId,
        array $content,
    ): void {
        $contentJson = json_encode($content);
        $provenanceJson = json_encode([
            'source'     => $content['source'] ?? 'import',
            'ts'         => now()->format('Y-m-d\TH:i:s\Z'),
            'confidence' => 1.0,
        ]);

        DB::table('hpbrain_evidence')->insert([
            'id'            => $evidenceId,
            'tenant_id'     => $tenantId,
            'signal_id'     => $signalId,
            'source'        => $content['source'] ?? 'import',
            'evidence_type' => 'observation',
            'content'       => $contentJson,
            'provenance'    => $provenanceJson,
            'confidence'    => 1.0,
            'hash'          => hash('sha256', $contentJson.'|'.$provenanceJson),
            'status'        => 'active',
            'created_by'    => self::ACTOR,
            'created_date'  => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Claim the buffered content for these ids.
     *
     * @param  array<int, string>  $evidenceIds
     * @return array<string, array<string, mixed>>
     */
    private function take(array $evidenceIds): array
    {
        $rows = [];

        foreach ($evidenceIds as $id) {
            if (isset($this->pending[$id])) {
                $rows[$id] = $this->pending[$id];
                unset($this->pending[$id]);
            }
        }

        return $rows;
    }

    /**
     * Drop buffered content for a signal that was refreshed rather than raised.
     *
     * Without this the buffer would grow for the lifetime of the process, and
     * a later rule could flush another rule's abandoned evidence.
     *
     * @param  array<int, string>  $evidenceIds
     */
    private function discard(array $evidenceIds): void
    {
        foreach ($evidenceIds as $id) {
            unset($this->pending[$id]);
        }
    }
}
