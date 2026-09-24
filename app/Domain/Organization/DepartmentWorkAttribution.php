<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Intelligence\SqlDialect;
use App\Domain\Operations\OperationalIntelligence;
use App\Domain\Operations\StatusVocabulary;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE SECOND WAY A RECORD NAMES ITS DEPARTMENT: THROUGH WHO HANDLED IT.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 *
 * DepartmentIntelligenceMetrics attributes operational work by `department_label`
 * — the owning unit the source export STATED. Where the export says it, that is
 * authoritative and nothing here competes with it.
 *
 * It does not always say it. Measured on Fiber Valley: 49,056 of 225,103 records
 * carry no department at all, and among them are all 16,505 `job_order` rows —
 * the single richest work ledger the organization has, with occurred_at,
 * closed_at, status, zone and category populated on every row. Those records were
 * invisible to every department screen, and the unit they belong to showed no
 * work whatsoever.
 *
 * They are not unattributable. Their nine distinct `owner_name` values are nine
 * people on the CST - FVCPL roster and on no other. The work is that unit's as
 * certainly as if the column had said so.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * A DIFFERENT BASIS, PUBLISHED AS ONE — NOT A FALLBACK FOR THE FIRST
 *
 * "Work booked to this unit's name" and "work handled by the people on this
 * unit's roster" are different claims about different things. A shared services
 * desk scores badly on the first and well on the second, and quietly merging them
 * would make both unreadable. So this NEVER writes into the label-attributed
 * figures and never fills a gap in them: every figure it publishes travels with
 * `basis: 'owner'`, and the screen says which basis a number came from.
 *
 * Matching is EXACT on the person's full name as the ERP records it — first name
 * plus last name, the same string PersonProfileService links a person's own
 * records by. No prefix match, no fuzzy distance, no initials: two people called
 * "R Patel" are not one person, and a screen that decided they were would be
 * inventing the roster. A name that appears on two rosters is AMBIGUOUS and is
 * counted for neither unit; a record whose owner matches nobody is simply not any
 * unit's, and is counted nowhere.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * ONE TENANT-WIDE PASS, SO EVERY UNIT IS SCORED THE SAME WAY
 *
 * This deliberately computes for EVERY department at once rather than for the one
 * being opened. A rank is a comparison, and a unit scored on owner attribution
 * sitting in a league table of units scored on label attribution is not a
 * comparison — it is two measurements presented as one. Doing the whole tenant is
 * also what makes it affordable: three index-covered GROUP BYs, measured on the
 * live database at 247ms, 258ms and 41ms for 225,103 records, against 92s for the
 * single-department form before the covering indexes existed.
 *
 * The covering indexes are
 * 2026_09_01_000100_operational_records_owner_attribution_indexes. Without them
 * every aggregate here falls back to the clustered index, where each row drags
 * its inline JSON payload through the buffer pool.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHAT IS MEASURED PER OWNER, AND WHAT IS NOT
 *
 * Status counts, turnaround, the weekly received/resolved series and the age of
 * open work are all owner-restricted, so they are the unit's own figures.
 *
 * Geography, category mix and repeat-subject rate are NOT owner-restricted by any
 * index, and reading them per owner would be exactly the scan this class exists
 * to avoid. They are published only in the one case where the dataset-level
 * figure IS the unit's figure — when the unit's people own every record in the
 * dataset — and that condition travels in the payload as `ownershipShare`. Below
 * it they are returned as not measurable with the reason, never approximated from
 * the organization-wide number.
 */
final class DepartmentWorkAttribution
{
    private const TABLE = 'hpbrain_operational_records';

    /** Weeks of received-vs-resolved history the activity chart reads. */
    public const WEEKS = 8;

    /** The trailing window "recently resolved" means, anchored on the data. */
    public const RECENT_DAYS = 30;

    /** Days after which an open item is aging rather than in flight. */
    public const AGED_DAYS = 14;

    /**
     * Above this many open items, aging is left unmeasured rather than bought
     * with a row lookup per item. `occurred_at` and `status` share no index, so
     * the age of open work is the one figure here that has to touch rows: it is
     * affordable for hundreds and is the clustered-index scan for tens of
     * thousands. A unit over the bound gets the reason, not a guess.
     */
    private const AGING_ROW_BUDGET = 20000;

    /** How much of a dataset must speak the presence vocabulary to be a ledger of it. */
    private const PRESENCE_PURITY = 0.8;

    /** How many source files to name per dataset before a list stops being provenance. */
    private const SOURCE_FILES = 4;

    /**
     * Statuses that describe whether a person was there, not whether work closed.
     *
     * The value is the share of a working day the status credits. Leave and week
     * off sit in the denominator at weight 0 deliberately: a roster where half the
     * team is on approved leave has a real presence problem to plan around, and
     * dropping those days would report it as full attendance.
     *
     * @var array<string, float>
     */
    private const PRESENCE = [
        'present' => 1.0,
        'p' => 1.0,
        'full day' => 1.0,
        'fullday' => 1.0,
        'half day' => 0.5,
        'halfday' => 0.5,
        'hd' => 0.5,
        'absent' => 0.0,
        'a' => 0.0,
        'on leave' => 0.0,
        'leave' => 0.0,
        'week off' => 0.0,
        'weekoff' => 0.0,
        'holiday' => 0.0,
    ];

    /** @var array<int, string>|null */
    private ?array $indexNames = null;

    public function __construct(private readonly OperationalIntelligence $operations)
    {
    }

    /**
     * Owner-attributed work for every department in the organization.
     *
     * @param  array<string, array<int, string>>  $rosters  department id => full names
     * @return array{supported: bool, reason: string|null, departments: array<string, array<string, mixed>>, ambiguousNames: array<int, string>}
     */
    public function forTenant(string $tenantId, array $rosters, bool $fresh = false): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return $this->blank('This installation records no operational data.');
        }

        [$owners, $ambiguous] = $this->ownerIndex($rosters);

        if ($owners === []) {
            return $this->blank(
                'No person on any unit\'s roster has a recorded name, so no imported record can be matched to a unit this way.',
            );
        }

        /*
          KEYED ON THE ROSTERS AS WELL AS THE RECORDS. Moving one person between
          units changes both units' work without touching a single operational
          row, so a fingerprint made only of the records would keep serving the
          previous roster's answer indefinitely.
        */
        $key = 'brain:deptwork:v2:'.$tenantId.':'.substr(hash(
            'sha256',
            $this->operations->dataVersion($tenantId).'|'.json_encode($rosters),
        ), 0, 16);

        $store = Cache::store('file');

        if ($fresh) {
            $store->forget($key);
        }

        $hit = $store->get($key);

        if (is_array($hit)) {
            return $hit;
        }

        $computed = $this->compute($tenantId, $rosters, $owners, $ambiguous);
        $store->put($key, $computed, 21600);

        return $computed;
    }

    /**
     * The received/resolved series and the age of open work, for ONE unit.
     *
     * Separate from forTenant because these are the two aggregates that do not
     * fold out of a tenant-wide pass: the series needs a window anchored on this
     * roster's own last activity, and aging is the one figure that touches rows.
     * Only the department actually being opened pays for them.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $openStatuses
     * @return array<string, mixed>
     */
    public function flowFor(
        string $tenantId,
        string $departmentId,
        array $names,
        string $dataset,
        array $openStatuses,
        int $open,
        bool $fresh = false,
    ): array {
        if (! Schema::hasTable(self::TABLE) || $names === []) {
            return [
                'weeks' => [],
                'recent' => $this->recentWindow([]),
                'aging' => $this->agingBlank('No person on this unit\'s roster has a recorded name.'),
            ];
        }

        $key = 'brain:deptflow:v2:'.$tenantId.':'.$departmentId.':'.substr(hash(
            'sha256',
            $this->operations->dataVersion($tenantId).'|'.$dataset.'|'.implode("\n", $names).'|'.implode('|', $openStatuses),
        ), 0, 16);

        $store = Cache::store('file');

        if ($fresh) {
            $store->forget($key);
        }

        $hit = $store->get($key);

        if (is_array($hit)) {
            return $hit;
        }

        $weeks = $this->weeklyFlow($tenantId, $names, $dataset);

        $computed = [
            'weeks' => $weeks,
            'recent' => $this->recentWindow($weeks),
            'aging' => $this->aging($tenantId, $names, $dataset, $openStatuses, $open),
        ];

        $store->put($key, $computed, 21600);

        return $computed;
    }

    /* ══════════════════════════════════════════════════════ the tenant pass ══ */

    /**
     * @param  array<string, array<int, string>>  $rosters
     * @param  array<string, string>  $owners  normalised name => department id
     * @param  array<int, string>  $ambiguous
     * @return array{supported: bool, reason: string|null, departments: array<string, array<string, mixed>>, ambiguousNames: array<int, string>}
     */
    private function compute(string $tenantId, array $rosters, array $owners, array $ambiguous): array
    {
        $states = $this->statesPerDatasetPerOwner($tenantId, array_keys($owners));
        $turnaround = $this->turnaroundPerDatasetPerOwner($tenantId, array_keys($owners));
        /*
          RE-KEYED BY DATASET. The operational aggregate publishes `datasets` as
          an ordered LIST — the order is its ranking by volume, which matters to
          its own consumers — so a lookup by name has to build the index. Reading
          it as if it were a map is a silent miss: every `fields.status` comes
          back false and every unit reports that its work carries no status.
        */
        $catalogue = [];

        foreach ($this->operations->forTenant($tenantId)['datasets'] ?? [] as $entry) {
            $catalogue[(string) ($entry['dataset'] ?? '')] = $entry;
        }

        $sources = $this->sourceFiles($tenantId);

        // Fold the two owner-keyed passes onto their units.
        $perUnit = [];

        foreach ($states as $dataset => $byOwner) {
            foreach ($byOwner as $owner => $byStatus) {
                $unit = $owners[$owner] ?? null;

                if ($unit === null) {
                    continue;
                }

                foreach ($byStatus as $status => $count) {
                    $state = StatusVocabulary::classify($status === '' ? null : (string) $status);

                    $perUnit[$unit][$dataset]['owners'][$owner]['records'] = ($perUnit[$unit][$dataset]['owners'][$owner]['records'] ?? 0) + $count;
                    $perUnit[$unit][$dataset]['owners'][$owner]['states'][$state] = ($perUnit[$unit][$dataset]['owners'][$owner]['states'][$state] ?? 0) + $count;

                    // The RAW labels, kept per owner as well as summed: presence
                    // is read from the source's own vocabulary, not from the
                    // open/closed classification, which knows nothing about
                    // whether somebody turned up.
                    $perUnit[$unit][$dataset]['ownerStatuses'][$owner][(string) $status] = $count;
                    $perUnit[$unit][$dataset]['statuses'][(string) $status] = ($perUnit[$unit][$dataset]['statuses'][(string) $status] ?? 0) + $count;
                }
            }
        }

        $departments = [];

        foreach ($rosters as $unitId => $names) {
            $departments[(string) $unitId] = $this->unit(
                (string) $unitId,
                $names,
                $perUnit[(string) $unitId] ?? [],
                $turnaround,
                $owners,
                $catalogue,
                $sources,
            );
        }

        return [
            'supported' => true,
            'reason' => null,
            'departments' => $departments,
            'ambiguousNames' => $ambiguous,
        ];
    }

    /**
     * One unit's owner-attributed picture.
     *
     * @param  array<int, string>  $names
     * @param  array<string, array<string, mixed>>  $datasetsRaw  dataset => {owners, statuses}
     * @param  array<string, array<string, array{measured: int, seconds: float}>>  $turnaround
     * @param  array<string, string>  $owners
     * @param  array<string, array<string, mixed>>  $catalogue
     * @param  array<string, array<int, string>>  $sources
     * @return array<string, mixed>
     */
    private function unit(
        string $unitId,
        array $names,
        array $datasetsRaw,
        array $turnaround,
        array $owners,
        array $catalogue,
        array $sources,
    ): array {
        if ($datasetsRaw === []) {
            return [
                'supported' => false,
                'basis' => 'owner',
                'rosterSize' => count($names),
                'rosterMatched' => 0,
                'records' => 0,
                'datasets' => [],
                'work' => ['supported' => false, 'reason' => 'No imported record names anyone on this unit\'s roster as its owner, so work cannot be attributed to it this way.'],
                'presence' => $this->presenceBlank(count($names)),
                'perOwner' => [],
                'reason' => 'No imported record names anyone on this unit\'s roster as its owner, so work cannot be attributed to it this way.',
            ];
        }

        $datasets = [];
        $matched = [];

        foreach ($datasetsRaw as $dataset => $raw) {
            $records = array_sum(array_map(static fn ($o) => (int) $o['records'], $raw['owners']));
            $entry = $catalogue[$dataset] ?? [];
            $datasetTotal = (int) ($entry['records'] ?? 0);

            foreach (array_keys($raw['owners']) as $owner) {
                $matched[$owner] = true;
            }

            $datasets[$dataset] = [
                'dataset' => (string) $dataset,
                'label' => (string) ($entry['label'] ?? $this->humanise((string) $dataset)),
                'records' => $records,
                'owners' => count($raw['owners']),
                'datasetRecords' => $datasetTotal ?: null,
                // 1.0 means this roster owns every record in the dataset — the
                // one case where the dataset's own facets are this unit's.
                'ownershipShare' => $datasetTotal > 0 ? round($records / $datasetTotal, 4) : null,
                'hasTimeline' => (bool) ($entry['fields']['timeline'] ?? false),
                'hasClosure' => (bool) ($entry['fields']['closure'] ?? false),
                'hasStatus' => (bool) ($entry['fields']['status'] ?? false),
                'source' => $sources[$dataset] ?? [],
            ];
        }

        uasort($datasets, static fn ($a, $b) => $b['records'] <=> $a['records']);

        $presence = $this->presence($datasets, $datasetsRaw, count($names));
        $work = $this->work($datasets, $datasetsRaw, $turnaround, $owners, $unitId, $catalogue, $presence['dataset'] ?? null);

        return [
            'supported' => true,
            'basis' => 'owner',
            'basisLabel' => 'Records whose owner is a person on this unit\'s roster',
            'rosterSize' => count($names),
            'rosterMatched' => count($matched),
            'records' => array_sum(array_map(static fn ($d) => $d['records'], $datasets)),
            'datasets' => array_values($datasets),
            'work' => $work,
            'presence' => $presence,
            'perOwner' => $this->perOwner($datasetsRaw, $presence, $work),
            'reason' => null,
        ];
    }

    /**
     * The unit's work ledger: the dataset it handles most of, measured.
     *
     * "Most of" is decided on OWNER-ATTRIBUTED VOLUME, then gated on the dataset
     * carrying a status and a timeline, and finally on it not being the roster's
     * own presence register — a unit's biggest dataset is very often its
     * attendance ledger, which says nothing about what the unit DID. A unit whose
     * largest work-shaped dataset is still shapeless gets the reason rather than a
     * ledger built on an empty column.
     *
     * @param  array<string, array<string, mixed>>  $datasets
     * @param  array<string, array<string, mixed>>  $raw
     * @param  array<string, array<string, array{measured: int, seconds: float}>>  $turnaround
     * @param  array<string, string>  $owners
     * @param  array<string, array<string, mixed>>  $catalogue
     * @return array<string, mixed>
     */
    private function work(
        array $datasets,
        array $raw,
        array $turnaround,
        array $owners,
        string $unitId,
        array $catalogue,
        ?string $presenceDataset,
    ): array {
        $chosen = null;

        foreach ($datasets as $entry) {
            if ($entry['hasStatus'] && $entry['hasTimeline'] && $entry['dataset'] !== $presenceDataset) {
                $chosen = $entry;
                break;
            }
        }

        if ($chosen === null) {
            return [
                'supported' => false,
                'reason' => 'No dataset this roster owns records in carries both a status and a date, so the unit\'s work cannot be followed from open to closed.',
            ];
        }

        $dataset = (string) $chosen['dataset'];
        $statuses = $raw[$dataset]['statuses'] ?? [];

        $totals = [
            StatusVocabulary::OPEN => 0,
            StatusVocabulary::PROGRESS => 0,
            StatusVocabulary::COMPLETED => 0,
            StatusVocabulary::CANCELLED => 0,
            StatusVocabulary::UNKNOWN => 0,
        ];

        $openStatuses = [];

        foreach ($statuses as $status => $count) {
            $state = StatusVocabulary::classify($status === '' ? null : (string) $status);
            $totals[$state] += (int) $count;

            if (($state === StatusVocabulary::OPEN || $state === StatusVocabulary::PROGRESS) && (string) $status !== '') {
                $openStatuses[] = (string) $status;
            }
        }

        arsort($statuses);

        $open = $totals[StatusVocabulary::OPEN] + $totals[StatusVocabulary::PROGRESS];
        $completed = $totals[StatusVocabulary::COMPLETED];
        $cancelled = $totals[StatusVocabulary::CANCELLED];
        $classified = $open + $completed + $cancelled;

        $entry = $catalogue[$dataset] ?? [];
        $ownsWholeDataset = ($chosen['ownershipShare'] ?? null) !== null && $chosen['ownershipShare'] >= 1.0;

        return [
            'supported' => true,
            'reason' => null,
            'dataset' => $dataset,
            'label' => (string) $chosen['label'],
            'records' => (int) $chosen['records'],
            'ownershipShare' => $chosen['ownershipShare'],
            'source' => $chosen['source'],

            'open' => $open,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'classified' => $classified,
            'unclassified' => (int) $chosen['records'] - $classified,
            'completionRate' => $classified > 0 ? round($completed / $classified, 4) : null,
            'cancellationRate' => $classified > 0 ? round($cancelled / $classified, 4) : null,
            'openStatuses' => array_values(array_unique($openStatuses)),
            'statuses' => array_map(
                static fn ($label, $count) => ['status' => (string) $label, 'records' => (int) $count],
                array_keys($statuses),
                array_values($statuses),
            ),

            'turnaround' => $this->turnaroundFor($turnaround, $dataset, $owners, $unitId),

            /*
              FORWARDED ONLY WHEN THE UNIT OWNS THE WHOLE DATASET.

              These three are the organization-wide figures for the dataset, and
              they are the unit's figures exactly when no record in it belongs to
              anyone else. Where the unit owns part of it, publishing the whole
              would attribute other people's geography and other people's repeat
              customers to this unit — the "close enough" that makes a screen
              untrustworthy.
            */
            'facets' => $ownsWholeDataset
                ? [
                    'supported' => true,
                    'reason' => null,
                    'basis' => 'This unit\'s people own every record in '.$chosen['label'].', so the dataset\'s own breakdown is this unit\'s.',
                    'zones' => $entry['zones'] ?? [],
                    'categories' => $entry['categories'] ?? [],
                    'recurrence' => $entry['recurrence'] ?? null,
                ]
                : [
                    'supported' => false,
                    'reason' => 'Geography, category mix and repeat-subject rate are recorded per record but not per owner, so they can only be read for a unit that owns a whole dataset. This unit owns '
                        .($chosen['ownershipShare'] === null ? 'part' : round($chosen['ownershipShare'] * 100).'%')
                        .' of '.$chosen['label'].'.',
                    'zones' => [],
                    'categories' => [],
                    'recurrence' => null,
                ],
        ];
    }

    /**
     * @param  array<string, array<string, array{measured: int, seconds: float}>>  $turnaround
     * @param  array<string, string>  $owners
     * @return array<string, mixed>
     */
    private function turnaroundFor(array $turnaround, string $dataset, array $owners, string $unitId): array
    {
        $measured = 0;
        $seconds = 0.0;

        foreach ($turnaround[$dataset] ?? [] as $owner => $row) {
            if (($owners[$owner] ?? null) !== $unitId) {
                continue;
            }

            $measured += $row['measured'];
            $seconds += $row['seconds'];
        }

        if ($measured === 0) {
            return [
                'supported' => false,
                'measured' => 0,
                'averageHours' => null,
                'averageDays' => null,
                'reason' => 'No record this roster owns carries both an opened and a closed timestamp, so time-to-close cannot be measured.',
            ];
        }

        $hours = ($seconds / $measured) / 3600;

        return [
            'supported' => true,
            'measured' => $measured,
            'averageHours' => round($hours, 2),
            'averageDays' => round($hours / 24, 2),
            'reason' => null,
        ];
    }

    /**
     * The roster's presence ledger, if the organization keeps one.
     *
     * DETECTED FROM THE VOCABULARY, NOT FROM THE DATASET NAME. A register called
     * "attendance" in one ERP is "muster" in the next and "daily_status" in the
     * third, and a rule keyed on the name would work for exactly one customer.
     * What generalises is that a presence ledger's statuses come from a closed set
     * — present, absent, half day, leave — and a work ledger's do not.
     *
     * @param  array<string, array<string, mixed>>  $datasets
     * @param  array<string, array<string, mixed>>  $raw
     * @return array<string, mixed>
     */
    private function presence(array $datasets, array $raw, int $rosterSize): array
    {
        foreach ($datasets as $entry) {
            $dataset = (string) $entry['dataset'];
            $statuses = $raw[$dataset]['statuses'] ?? [];

            $total = array_sum($statuses);
            $known = 0;

            foreach ($statuses as $status => $count) {
                if (array_key_exists(mb_strtolower(trim((string) $status)), self::PRESENCE)) {
                    $known += (int) $count;
                }
            }

            if ($total === 0 || $known / $total < self::PRESENCE_PURITY) {
                continue;
            }

            $perOwner = [];

            foreach ($raw[$dataset]['ownerStatuses'] ?? [] as $owner => $byStatus) {
                foreach ($byStatus as $status => $count) {
                    $weight = self::PRESENCE[mb_strtolower(trim((string) $status))] ?? null;

                    if ($weight === null) {
                        continue;
                    }

                    $perOwner[$owner]['recorded'] = ($perOwner[$owner]['recorded'] ?? 0) + (int) $count;
                    $perOwner[$owner]['credited'] = ($perOwner[$owner]['credited'] ?? 0) + ((int) $count * $weight);
                }
            }

            $recorded = array_sum(array_column($perOwner, 'recorded'));
            $credited = array_sum(array_column($perOwner, 'credited'));

            return [
                'supported' => true,
                'dataset' => $dataset,
                'label' => (string) $entry['label'],
                'source' => $entry['source'],
                'peopleMeasured' => count($perOwner),
                'peopleOnRoster' => $rosterSize,
                'daysRecorded' => (int) $recorded,
                'rate' => $recorded > 0 ? round($credited / $recorded, 4) : null,
                'perOwner' => $perOwner,
                'method' => 'Days credited over days recorded, counting a half day as half. Approved leave and week-offs stay in the denominator: a roster that is absent for a good reason is still a roster that is absent.',
                'reason' => null,
            ];
        }

        return $this->presenceBlank($rosterSize);
    }

    /** @return array<string, mixed> */
    private function presenceBlank(int $rosterSize): array
    {
        return [
            'supported' => false,
            'dataset' => null,
            'label' => null,
            'source' => [],
            'peopleMeasured' => 0,
            'peopleOnRoster' => $rosterSize,
            'daysRecorded' => 0,
            'rate' => null,
            'perOwner' => [],
            'method' => null,
            'reason' => 'No dataset this roster appears in records presence — no imported status set reads as present, absent, half day or leave.',
        ];
    }

    /**
     * Per person, from the aggregates already in hand. No further query.
     *
     * A person the records never name is ABSENT FROM THIS MAP, so the caller
     * renders them as unmeasured rather than as zero: "handled nothing" and "is
     * not named by any import" are opposite findings about an employee, and only
     * one of them is about their work.
     *
     * @param  array<string, array<string, mixed>>  $raw
     * @param  array<string, mixed>  $presence
     * @param  array<string, mixed>  $work
     * @return array<string, array<string, mixed>>  owner name => facts
     */
    private function perOwner(array $raw, array $presence, array $work): array
    {
        $out = [];
        $workDataset = ($work['supported'] ?? false) === true ? (string) $work['dataset'] : null;

        foreach ($raw as $dataset => $entry) {
            foreach ($entry['owners'] ?? [] as $owner => $ownerRow) {
                $out[$owner]['records'] = ($out[$owner]['records'] ?? 0) + (int) $ownerRow['records'];
                $out[$owner]['datasets'][(string) $dataset] = (int) $ownerRow['records'];

                if ((string) $dataset === $workDataset) {
                    $states = $ownerRow['states'] ?? [];

                    $out[$owner]['handled'] = (int) $ownerRow['records'];
                    $out[$owner]['open'] = (int) (($states[StatusVocabulary::OPEN] ?? 0) + ($states[StatusVocabulary::PROGRESS] ?? 0));
                    $out[$owner]['completed'] = (int) ($states[StatusVocabulary::COMPLETED] ?? 0);
                }
            }
        }

        foreach (array_keys($out) as $owner) {
            $out[$owner]['handled'] ??= null;
            $out[$owner]['open'] ??= null;
            $out[$owner]['completed'] ??= null;
            $out[$owner]['handledDataset'] = $workDataset;
            $out[$owner]['presenceRate'] = null;
            $out[$owner]['presenceDays'] = null;

            if (($presence['supported'] ?? false) && isset($presence['perOwner'][$owner])) {
                $recorded = (float) ($presence['perOwner'][$owner]['recorded'] ?? 0);

                $out[$owner]['presenceDays'] = (int) $recorded;
                $out[$owner]['presenceRate'] = $recorded > 0
                    ? round(((float) $presence['perOwner'][$owner]['credited']) / $recorded, 4)
                    : null;
            }
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════════════════ the probes ══ */

    /**
     * Every owner's records, split by dataset and status, for the whole tenant.
     *
     * COVERED BY (tenant_id, dataset, owner_name, status): all four columns are
     * in the index in order, so this is an index-only scan and no row — and no
     * inline JSON payload — is ever fetched. Measured at 247ms over 225,103
     * records; the same GROUP BY without the index took 92s for ONE department.
     *
     * @param  array<int, string>  $names
     * @return array<string, array<string, array<string, int>>>  dataset => owner => status => records
     */
    private function statesPerDatasetPerOwner(string $tenantId, array $names): array
    {
        $rows = DB::table(DB::raw($this->from('idx_oprec_tenant_ds_owner_status')))
            ->where('tenant_id', $tenantId)
            ->whereIn('owner_name', $names)
            ->selectRaw('dataset, owner_name, status, COUNT(*) AS records')
            ->groupBy('dataset', 'owner_name', 'status')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->dataset][(string) $row->owner_name][(string) ($row->status ?? '')] = (int) $row->records;
        }

        return $out;
    }

    /**
     * Time-to-close per owner per dataset, for the whole tenant.
     *
     * COVERED BY (tenant_id, dataset, owner_name, occurred_at, closed_at): both
     * timestamps are in the index, so the average costs no row lookup.
     *
     * Rows whose closure precedes their opening are excluded rather than allowed
     * to pull the mean negative — a source with reversed timestamps is a data
     * fault to report, not a department that closes work before receiving it.
     *
     * @param  array<int, string>  $names
     * @return array<string, array<string, array{measured: int, seconds: float}>>
     */
    private function turnaroundPerDatasetPerOwner(string $tenantId, array $names): array
    {
        $seconds = SqlDialect::secondsBetween('occurred_at', 'closed_at');

        $rows = DB::table(DB::raw($this->from('idx_oprec_tenant_ds_owner_time')))
            ->where('tenant_id', $tenantId)
            ->whereIn('owner_name', $names)
            ->whereNotNull('occurred_at')
            ->whereNotNull('closed_at')
            ->whereRaw('closed_at >= occurred_at')
            ->selectRaw('dataset, owner_name, COUNT(*) AS measured, SUM('.$seconds.') AS total_seconds')
            ->groupBy('dataset', 'owner_name')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->dataset][(string) $row->owner_name] = [
                'measured' => (int) $row->measured,
                'seconds' => (float) $row->total_seconds,
            ];
        }

        return $out;
    }

    /**
     * Which import files each dataset's records came from, so a figure on screen
     * can be traced back to the spreadsheet it was read out of.
     *
     * COVERED BY (tenant_id, dataset, source_file).
     *
     * @return array<string, array<int, string>>
     */
    private function sourceFiles(string $tenantId): array
    {
        $rows = DB::table(DB::raw($this->from('idx_oprec_tenant_dataset_source_file')))
            ->where('tenant_id', $tenantId)
            ->whereNotNull('source_file')
            ->selectRaw('dataset, source_file')
            ->groupBy('dataset', 'source_file')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $dataset = (string) $row->dataset;

            if (count($out[$dataset] ?? []) >= self::SOURCE_FILES) {
                continue;
            }

            $out[$dataset][] = (string) $row->source_file;
        }

        return $out;
    }

    /**
     * Received against resolved, week by week.
     *
     * TWO PASSES OVER THE SAME COVERED INDEX, not one. A record is RECEIVED in
     * the week of `occurred_at` and RESOLVED in the week of `closed_at`, and those
     * are different weeks — grouping once and counting both would report every
     * item as resolved in the week it arrived, which is precisely the gap this
     * chart exists to show.
     *
     * @param  array<int, string>  $names
     * @return array<int, array<string, mixed>>
     */
    private function weeklyFlow(string $tenantId, array $names, string $dataset): array
    {
        $anchor = $this->latestActivity($tenantId, $names, $dataset);

        if ($anchor === null) {
            return [];
        }

        /*
          WEEKS ARE COUNTED BACK FROM THE LAST WEEK THE DATA COVERS, NOT FROM
          TODAY. An import that stopped three weeks ago must not draw three empty
          weeks and present them as a collapse in demand. The window travels with
          the series so the screen can say what it ends on.
        */
        $end = $anchor->modify('sunday this week')->setTime(23, 59, 59);
        $start = $end->modify('-'.(self::WEEKS - 1).' weeks')->modify('monday this week')->setTime(0, 0, 0);

        $received = $this->weeklyCount($tenantId, $names, $dataset, 'occurred_at', $start, $end);
        $resolved = $this->weeklyCount($tenantId, $names, $dataset, 'closed_at', $start, $end);

        $weeks = [];
        $cursor = $start;

        for ($i = 0; $i < self::WEEKS; $i++) {
            $key = $cursor->format('Y-m-d');

            $weeks[] = [
                'weekStart' => $key,
                'received' => (int) ($received[$key] ?? 0),
                'resolved' => (int) ($resolved[$key] ?? 0),
            ];

            $cursor = $cursor->modify('+1 week');
        }

        return $weeks;
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, int>  Monday of the week => records
     */
    private function weeklyCount(
        string $tenantId,
        array $names,
        string $dataset,
        string $column,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): array {
        $rows = DB::table(DB::raw($this->from('idx_oprec_tenant_ds_owner_time')))
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset)
            ->whereIn('owner_name', $names)
            ->whereNotNull($column)
            ->where($column, '>=', $start->format('Y-m-d H:i:s'))
            ->where($column, '<=', $end->format('Y-m-d H:i:s'))
            ->selectRaw($this->weekStart($column).' AS week_start, COUNT(*) AS records')
            ->groupBy('week_start')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[substr((string) $row->week_start, 0, 10)] = (int) $row->records;
        }

        return $out;
    }

    /** The Monday of a timestamp's week, in each dialect the suite runs on. */
    private function weekStart(string $column): string
    {
        return SqlDialect::isSqlite()
            ? "DATE({$column}, '-6 days', 'weekday 1')"
            : "DATE({$column} - INTERVAL WEEKDAY({$column}) DAY)";
    }

    /** @param array<int, string> $names */
    private function latestActivity(string $tenantId, array $names, string $dataset): ?\DateTimeImmutable
    {
        $row = DB::table(DB::raw($this->from('idx_oprec_tenant_ds_owner_time')))
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset)
            ->whereIn('owner_name', $names)
            ->selectRaw('MAX(occurred_at) AS latest')
            ->first();

        $latest = $row->latest ?? null;

        if ($latest === null || $latest === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $latest);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How much open work has been open too long.
     *
     * THE ONE FIGURE HERE THAT TOUCHES ROWS. `status` and `occurred_at` share no
     * index, so the age of open items cannot be read from one; this asks only for
     * the rows already known to be open, which is hundreds where the totals are
     * tens of thousands. Over AGING_ROW_BUDGET it declines to run and says so — a
     * bounded honest gap rather than an unbounded scan.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $openStatuses
     * @return array<string, mixed>
     */
    private function aging(string $tenantId, array $names, string $dataset, array $openStatuses, int $open): array
    {
        if ($open === 0) {
            return array_merge($this->agingBlank(null), [
                'supported' => true,
                'agedItems' => 0,
                'agedShare' => 0.0,
            ]);
        }

        if ($openStatuses === []) {
            return $this->agingBlank(
                'The open items carry no status this model recognises as open, so their age cannot be separated from closed work.',
            );
        }

        if ($open > self::AGING_ROW_BUDGET) {
            return $this->agingBlank(
                'This unit holds '.number_format($open).' open items. Their dates are recorded but not indexed alongside their status, so reading them would scan the whole record table; aging is left unmeasured rather than bought at that cost.',
            );
        }

        $days = SqlDialect::daysSince('occurred_at');

        $row = DB::table(DB::raw($this->from('idx_oprec_tenant_ds_owner_status')))
            ->where('tenant_id', $tenantId)
            ->where('dataset', $dataset)
            ->whereIn('owner_name', $names)
            ->whereIn('status', $openStatuses)
            ->whereNotNull('occurred_at')
            ->selectRaw(
                'COUNT(*) AS dated, '
                .'SUM(CASE WHEN '.$days.' > '.self::AGED_DAYS.' THEN 1 ELSE 0 END) AS aged, '
                .'MAX('.$days.') AS oldest',
            )
            ->first();

        $dated = (int) ($row->dated ?? 0);

        if ($dated === 0) {
            return $this->agingBlank('No open item carries the date it was raised, so how long it has been open cannot be measured.');
        }

        $aged = (int) ($row->aged ?? 0);

        return [
            'supported' => true,
            'agedItems' => $aged,
            'agedShare' => round($aged / $dated, 4),
            'measured' => $dated,
            'thresholdDays' => self::AGED_DAYS,
            'oldestDays' => (int) ($row->oldest ?? 0),
            'reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function agingBlank(?string $reason): array
    {
        return [
            'supported' => false,
            'agedItems' => null,
            'agedShare' => null,
            'measured' => 0,
            'thresholdDays' => self::AGED_DAYS,
            'oldestDays' => null,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $weeks
     * @return array<string, mixed>
     */
    private function recentWindow(array $weeks): array
    {
        if ($weeks === []) {
            return [
                'supported' => false,
                'received' => null,
                'resolved' => null,
                'weeks' => 0,
                'from' => null,
                'to' => null,
                'reason' => 'No record this roster owns carries a date, so recent volume cannot be counted.',
            ];
        }

        $slice = array_slice($weeks, -(int) ceil(self::RECENT_DAYS / 7));

        return [
            'supported' => true,
            'received' => array_sum(array_column($slice, 'received')),
            'resolved' => array_sum(array_column($slice, 'resolved')),
            'weeks' => count($slice),
            'from' => $slice[0]['weekStart'],
            'to' => $weeks[count($weeks) - 1]['weekStart'],
            'reason' => null,
        ];
    }

    /* ══════════════════════════════════════════════════════════════ plumbing ══ */

    /**
     * Roster names, inverted to owner => unit.
     *
     * A NAME ON TWO ROSTERS BELONGS TO NEITHER. Attribution has to be a function,
     * and a name that two units both claim cannot decide which one a record
     * belongs to. Splitting the records between them, or giving them to the larger
     * unit, would both be inventions; the name is dropped and reported instead.
     *
     * @param  array<string, array<int, string>>  $rosters
     * @return array{0: array<string, string>, 1: array<int, string>}
     */
    private function ownerIndex(array $rosters): array
    {
        $claims = [];

        foreach ($rosters as $unitId => $names) {
            foreach ($names as $name) {
                $clean = trim((string) $name);

                if ($clean === '') {
                    continue;
                }

                $claims[$clean][(string) $unitId] = true;
            }
        }

        $owners = [];
        $ambiguous = [];

        foreach ($claims as $name => $units) {
            if (count($units) > 1) {
                $ambiguous[] = $name;

                continue;
            }

            $owners[$name] = (string) array_key_first($units);
        }

        return [$owners, $ambiguous];
    }

    /** @return array{supported: bool, reason: string|null, departments: array<string, array<string, mixed>>, ambiguousNames: array<int, string>} */
    private function blank(string $reason): array
    {
        return ['supported' => false, 'reason' => $reason, 'departments' => [], 'ambiguousNames' => []];
    }

    /** The FROM clause, with the index hint only where it is valid and present. */
    private function from(string $index): string
    {
        return SqlDialect::isSqlite() || ! $this->hasIndex($index)
            ? self::TABLE
            : self::TABLE.' FORCE INDEX ('.$index.')';
    }

    /**
     * Whether an index exists, so a hint cannot turn a missing index into a fatal
     * query. The owner-attribution indexes arrive with a migration a given
     * installation may not have run yet. Read as one list and memoised per
     * instance, exactly as OperationalIntelligence does.
     */
    private function hasIndex(string $name): bool
    {
        if (SqlDialect::isSqlite()) {
            return false;
        }

        if ($this->indexNames === null) {
            $this->indexNames = array_map(
                static fn ($row) => (string) ($row->Key_name ?? $row->key_name ?? ''),
                DB::select('SHOW INDEX FROM '.self::TABLE),
            );
        }

        return in_array($name, $this->indexNames, true);
    }

    private function humanise(string $dataset): string
    {
        return ucwords(str_replace('_', ' ', $dataset));
    }
}
