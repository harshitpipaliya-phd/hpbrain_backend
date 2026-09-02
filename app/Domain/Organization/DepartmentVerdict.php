<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE DEPARTMENT INTELLIGENCE SCREEN, COMPOSED SERVER-SIDE.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * ONE VERDICT, AND EVERYTHING NEEDED TO CHECK IT
 *
 * The screen this feeds opens with a judgement — this unit is healthy, or it is
 * not — and then shows the reader every figure that judgement rests on and every
 * figure it could not use. That shape only works if the judgement and its
 * evidence come from ONE place. Composed here, they cannot disagree; assembled in
 * the browser from six endpoints, they eventually would.
 *
 * DepartmentProfile owns the score and nothing here recomputes it.
 * DepartmentWorkAttribution owns the owner-attributed work and nothing here
 * re-queries it. This class turns those into the sections of a page.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * TWO NUMBERS THAT ARE NEVER MIXED
 *
 *   HEALTH (0-100) is a verdict on performance, computed from the dimensions
 *   that are MEASURABLE. A dimension the organization cannot record leaves the
 *   average entirely — it is never entered as a zero.
 *
 *   CONFIDENCE (%) is how much of the model could be measured at all. Missing
 *   data lowers confidence. It never lowers health.
 *
 * Keeping them apart is the whole design. A unit with three excellent measured
 * dimensions and four unmeasurable ones is a strong unit we know little about,
 * and the single blended number that used to be published called it a failing
 * one.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * "NOT MEASURABLE" HAS A SHAPE, AND IT IS NOT A ZERO
 *
 * Every section that cannot be filled returns `supported: false` with a sentence
 * saying what is missing, and — where one exists — the screen that would fix it.
 * The blind-spot list is assembled from those same sentences, so the panel and
 * the list can never tell the reader different things. Nothing on this screen
 * renders 0 to mean "unknown", and nothing renders an em-dash on its own.
 *
 * Where a root cause cannot be established, the recommendation says UNDETERMINED
 * and names what would settle it. That is a first-class result here, not a
 * failure to produce one.
 */
final class DepartmentVerdict
{
    /** People per page in the roster. The screen pages; it never downloads a unit. */
    private const DEFAULT_PAGE_SIZE = 5;

    private const MAX_PAGE_SIZE = 50;

    /** The metric key department health is snapshotted under, for the delta. */
    public const HEALTH_METRIC = 'department.health';

    /**
     * Seeder fingerprints. A row written by a demo seeder is a rehearsal, not an
     * observation of this organization, and the screen labels it as one rather
     * than presenting it beside real findings.
     */
    private const SEEDER_MARKS = ['demo-seeder', 'seeder', 'fv-demo-seeder', 'loop-seeder'];

    public function __construct(
        private readonly EntityResolver $resolver,
        private readonly DepartmentVisibilityScope $visibility,
        private readonly DepartmentProfile $profiles,
        private readonly DepartmentWorkAttribution $attribution,
        private readonly DepartmentRosterReader $rosters,
    ) {
    }

    /**
     * @return array<string, mixed>|null  null when the unit is not this tenant's
     */
    public function forDepartment(
        string $tenant,
        string $departmentId,
        int $page = 1,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        bool $fresh = false,
    ): ?array {
        $unit = $this->resolver->resolve($tenant, 'OrganizationUnit');
        $person = $this->resolver->resolve($tenant, 'Person');

        $row = $this->unitRow($tenant, $departmentId, $unit);

        if ($row === null) {
            return null;
        }

        /*
          THE WHOLE ORGANIZATION'S ROSTERS, NOT JUST THIS UNIT'S.

          Owner attribution has to be computed for every unit at once — see
          DepartmentWorkAttribution — because a rank built from units measured
          two different ways is not a rank. It is three index-covered queries for
          the tenant, cached against a fingerprint of the records and the rosters.
        */
        $rosters = $this->rosters->forTenant($tenant);
        $ownerWork = $this->attribution->forTenant($tenant, $rosters, $fresh);

        $profile = $this->profiles->forDepartment($tenant, $departmentId, $ownerWork['departments'] ?? []);

        if ($profile === null) {
            return null;
        }

        $work = $profile['ownerWork'] ?? null;
        $ledger = $this->ledger($profile, $work);

        /*
          THE WEEKLY SERIES AND THE AGE OF OPEN WORK ARE OWNER-ONLY.

          Both need the roster to restrict on, so they exist for a unit whose
          work reaches it through its people and not for one whose work is
          booked to its name — the label aggregate carries a MONTHLY volume per
          unit and no closure dates. `activity()` reads whichever the ledger's
          basis actually supports and says which it drew, rather than presenting
          two different granularities as one chart.
        */
        $flow = ($ledger !== null && $ledger['basis'] === 'owner')
            ? $this->attribution->flowFor(
                $tenant,
                $departmentId,
                $rosters[$departmentId] ?? [],
                (string) $ledger['dataset'],
                (array) ($ledger['openStatuses'] ?? []),
                (int) $ledger['open'],
                $fresh,
            )
            : [
                'weeks' => [],
                'recent' => [
                    'supported' => false,
                    'received' => null,
                    'resolved' => null,
                    'reason' => $ledger === null
                        ? 'No dataset attributes work to this unit, so recent volume cannot be counted.'
                        : 'This unit\'s work is attributed by the name the export states, which records a monthly volume and no closure dates per unit — so arriving and finishing work cannot be separated week by week.',
                ],
                'aging' => [
                    'supported' => false,
                    'agedItems' => null,
                    'agedShare' => null,
                    'measured' => 0,
                    'thresholdDays' => DepartmentWorkAttribution::AGED_DAYS,
                    'oldestDays' => null,
                    'reason' => $ledger === null
                        ? 'No dataset attributes work to this unit, so the age of open items cannot be measured.'
                        : 'The age of open items is only readable for work attributed through the people who handled it; this unit\'s work is attributed by the name the export states, which carries no per-item dates here.',
                ],
            ];

        $signals = $this->signals($tenant, $departmentId);
        $capabilities = $this->capabilities($tenant, $departmentId);
        $delta = $this->delta($tenant, $departmentId, $profile['score'] ?? null);
        $blindSpots = $this->blindSpots($profile, $ledger, $flow, $signals, $capabilities);

        return [
            'department' => $this->department($tenant, $row, $unit, $profile, $work),
            'health' => $this->health($profile, $delta),
            'confidence' => $this->confidence($profile),
            'sinceRefresh' => $delta,
            'tiles' => $this->tiles($profile, $ledger, $flow, $signals, $capabilities),
            'state' => $this->state($profile, $ledger, $flow, $blindSpots),
            'performance' => $this->performance($profile, $ledger),
            'workload' => $this->workload($profile, $ledger, $flow),
            'activity' => $this->activity($ledger, $flow, $profile),
            'people' => $this->people($tenant, $departmentId, $person, $work, $page, $pageSize),
            'contribution' => $this->contribution($profile),
            'capabilities' => $capabilities,
            'signals' => $signals,
            'flow' => $this->crossUnitFlow($profile),
            'blindSpots' => $blindSpots,
            'scoreExplain' => $this->scoreExplain($profile),
            'recommendation' => $this->recommendation($profile, $ledger, $flow, $signals, $blindSpots),
            'sources' => $this->sources($work, $signals, $capabilities),
        ];
    }

    /**
     * ONE WORK LEDGER, ON THE SAME ATTRIBUTION PRECEDENCE THE SCORE USES.
     *
     * ═════════════════════════════════════════════════════════════════════════
     * WHY THIS EXISTS
     *
     * The panels read owner-attributed work only, and the SCORE reads label
     * attribution first. On the unit named "CST" — 47,693 records booked to its
     * name and nobody on its roster — that produced a page that contradicted
     * itself: a health score built from its completion rate, sitting above a
     * panel saying no dataset attributes work to this unit. Both statements came
     * from this codebase and one of them was wrong on any reading.
     *
     * So the precedence is defined ONCE and both consume it: the label basis
     * wins wherever the export states an owning unit, because that is the
     * organization's own declaration; the owner basis speaks only where the
     * export is silent. Identical to DepartmentProfile::dimensions(), and the
     * `basis` field says which one produced the figures so the screen can label
     * them and the two can never be mistaken for each other.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $ownerWork
     * @return array<string, mixed>|null  null when neither basis reaches this unit
     */
    private function ledger(array $profile, ?array $ownerWork): ?array
    {
        $performance = (array) ($profile['performance'] ?? []);
        $labelRecords = $performance['records'] ?? null;

        // ---- the label basis: the owning unit the export stated
        if ($labelRecords !== null && (int) $labelRecords > 0) {
            $classified = (int) ($performance['classified'] ?? 0);
            $turnaroundHours = $performance['turnaroundHours'] ?? null;
            $turnaroundMeasured = (int) ($performance['turnaroundMeasured'] ?? 0);

            return [
                'basis' => 'label',
                'dataset' => $profile['work']['primaryDataset'] ?? null,
                'label' => $this->humanise((string) ($profile['work']['primaryDataset'] ?? 'imported records')),
                'source' => [],
                'records' => (int) $labelRecords,
                'open' => (int) ($performance['backlog'] ?? 0),
                'completed' => (int) ($performance['completed'] ?? 0),
                'cancelled' => (int) ($performance['cancelled'] ?? 0),
                'classified' => $classified,
                'completionRate' => $performance['completionRate'] ?? null,
                'cancellationRate' => $performance['cancellationRate'] ?? null,
                'openStatuses' => [],
                'turnaround' => [
                    'supported' => $turnaroundHours !== null && $turnaroundMeasured > 0,
                    'measured' => $turnaroundMeasured,
                    'averageHours' => $turnaroundHours,
                    'averageDays' => $turnaroundHours === null ? null : round((float) $turnaroundHours / 24, 2),
                    'reason' => $turnaroundHours === null
                        ? 'Too few records attributed to this unit carry both an opened and a closed timestamp for time-to-close to be measured.'
                        : null,
                ],
                // Geography and repeat rate are per record and not per unit on
                // this basis, so they are unmeasured rather than borrowed from
                // the organization-wide figure.
                'facets' => [
                    'supported' => false,
                    'reason' => 'Geography, category mix and repeat-subject rate are recorded per record. On work attributed by the name the export states they cannot be read back for one unit without re-reading every record it owns.',
                    'zones' => [],
                    'categories' => [],
                    'recurrence' => null,
                ],
            ];
        }

        // ---- the owner basis: work handled by people on this unit's roster
        $owner = $ownerWork['work'] ?? null;

        return ($owner['supported'] ?? false) === true ? $owner + ['basis' => 'owner'] : null;
    }

    private function humanise(string $dataset): string
    {
        return $dataset === '' ? 'Imported records' : ucwords(str_replace('_', ' ', $dataset));
    }

    /* ═══════════════════════════════════════════════════════════ the header ══ */

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $work
     * @return array<string, mixed>
     */
    private function department(string $tenant, array $row, ResolvedSource $unit, array $profile, ?array $work): array
    {
        $org = $this->organization($tenant);
        $contribution = $profile['contribution'] ?? [];

        return [
            'id' => (string) $row[$unit->primaryKey],
            'name' => (string) ($row[$unit->field('name')] ?? ''),
            // The register's own code column, only where the tenant maps one.
            // Null rather than a slice of the name: an invented code is a
            // identifier other systems will try to look up.
            'code' => $unit->has('code') ? $this->blankToNull($row[$unit->field('code')] ?? null) : null,
            'description' => $unit->has('description') ? $this->blankToNull($row[$unit->field('description')] ?? null) : null,
            'headcount' => (int) ($profile['people']['total'] ?? 0),
            'workforceSharePct' => $contribution['peopleShare'] ?? null,
            'organization' => $org + [
                // The organization's average department health, from the same
                // engine that scored this unit — not a second definition of
                // "organization score" computed somewhere else.
                'score' => $profile['position']['organizationAverage'] ?? null,
                'scoreBasis' => 'The mean health of every department this model can score.',
                'departments' => (int) ($profile['position']['score']['of'] ?? 0),
            ],
            'datasetsConnected' => $work === null ? 0 : count($work['datasets'] ?? []),
            'attribution' => $work === null ? null : [
                'basis' => $work['basis'] ?? 'owner',
                'label' => $work['basisLabel'] ?? null,
                'rosterMatched' => $work['rosterMatched'] ?? 0,
                'rosterSize' => $work['rosterSize'] ?? 0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function organization(string $tenant): array
    {
        try {
            $source = $this->resolver->resolve($tenant, 'Organization');
        } catch (\Throwable) {
            return ['id' => $tenant, 'name' => null, 'industry' => null];
        }

        $row = DB::table($source->table)->where($source->tenantKey, $tenant)->first();

        if ($row === null) {
            return ['id' => $tenant, 'name' => null, 'industry' => null];
        }

        $row = (array) $row;

        return [
            'id' => $tenant,
            'name' => $source->has('name') ? $this->blankToNull($row[$source->field('name')] ?? null) : null,
            'code' => $source->has('code') ? $this->blankToNull($row[$source->field('code')] ?? null) : null,
            'industry' => $source->has('industry') ? $this->blankToNull($row[$source->field('industry')] ?? null) : null,
        ];
    }

    /**
     * The verdict itself.
     *
     * UNDETERMINED IS A RESULT. A unit with nothing measurable gets the word,
     * not a zero and not a hopeful midpoint — the two readings are opposite and
     * only one of them is bad news.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>
     */
    private function health(array $profile, array $delta): array
    {
        $score = $profile['score'] ?? null;

        return [
            'score' => $score,
            'band' => $score === null ? 'undetermined' : (string) $profile['status'],
            'label' => $score === null ? 'Undetermined' : (string) $profile['statusLabel'],
            'deltaSinceRefresh' => $delta['delta'] ?? null,
            'previousScore' => $delta['previousScore'] ?? null,
            'previousDate' => $delta['previousDate'] ?? null,
            // The engine's own sentences, joined. Not a summary of them: the
            // reader has to be able to find each clause in a panel below.
            'reason' => $score === null
                ? 'Nothing this model reads is recorded for this unit yet, so no verdict can be reached. The blind spots below say what would produce one.'
                : implode(' ', (array) ($profile['health']['lines'] ?? [])),
            'rule' => 'Computed from measurable dimensions only. A dimension this organization cannot record is excluded, never scored as zero.',
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function confidence(array $profile): array
    {
        $measured = (int) ($profile['measuredCount'] ?? 0);
        $total = (int) ($profile['dimensionCount'] ?? 0);

        /*
          WEIGHTED, NOT COUNTED. Four cheap dimensions measured out of seven is
          not the same knowledge as the two heaviest ones measured out of seven,
          and a plain count says it is. The denominator is the model's whole
          weight; the numerator is the weight that could be evaluated.
        */
        $totalWeight = 0.0;
        $measuredWeight = 0.0;

        foreach ($profile['dimensions'] ?? [] as $dimension) {
            $totalWeight += (float) $dimension['weight'];

            if ($dimension['score'] !== null) {
                $measuredWeight += (float) $dimension['weight'];
            }
        }

        return [
            'pct' => $totalWeight > 0 ? (int) round($measuredWeight / $totalWeight * 100) : null,
            'measurableDimensions' => $measured,
            'totalDimensions' => $total,
            'band' => (string) ($profile['confidence'] ?? 'none'),
            'caption' => sprintf(
                '%d of %d dimensions measurable · the verdict uses measured data only',
                $measured,
                $total,
            ),
        ];
    }

    /* ══════════════════════════════════════════════════════════════ movement ══ */

    /**
     * What changed since the last time this unit was measured.
     *
     * READ FROM RECORDED HISTORY, NEVER INFERRED. Movement needs two
     * measurements, and until `brain:snapshot` has run on two different days
     * there is only one. This returns `supported: false` with that sentence
     * rather than a zero delta, because "unchanged" and "never compared" are
     * different claims and only one of them is reassuring.
     *
     * @return array<string, mixed>
     */
    private function delta(string $tenant, string $departmentId, ?int $score): array
    {
        $blank = [
            'supported' => false,
            'delta' => null,
            'previousScore' => null,
            'previousDate' => null,
            'changes' => [],
            'reason' => 'This unit has been measured once. Movement needs two measurements, so nothing can be compared yet — the next scheduled refresh produces the first delta.',
        ];

        if (! Schema::hasTable('hpbrain_metric_snapshots') || $score === null) {
            return $blank;
        }

        $rows = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', $tenant)
            ->where('metric_key', self::HEALTH_METRIC)
            ->where('dimension_key', $departmentId)
            ->whereNotNull('value')
            ->orderByDesc('snapshot_date')
            ->limit(2)
            ->get(['snapshot_date', 'value']);

        if ($rows->count() < 2) {
            return $blank;
        }

        $previous = $rows[1];
        $delta = $score - (int) round((float) $previous->value);

        return [
            'supported' => true,
            'delta' => $delta,
            'previousScore' => (int) round((float) $previous->value),
            'previousDate' => (string) $previous->snapshot_date,
            'changes' => [[
                'label' => sprintf('Health %s%d', $delta >= 0 ? '+' : '', $delta),
                'detail' => sprintf('was %d on %s', (int) round((float) $previous->value), (string) $previous->snapshot_date),
                'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            ]],
            'reason' => null,
        ];
    }

    /* ═════════════════════════════════════════════════════════════════ tiles ══ */

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $ledger
     * @param  array<string, mixed>  $flow
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $capabilities
     * @return array<int, array<string, mixed>>
     */
    private function tiles(array $profile, ?array $ledger, array $flow, array $signals, array $capabilities): array
    {
        $people = (int) ($profile['people']['total'] ?? 0);
        $share = $profile['contribution']['peopleShare'] ?? null;
        $aging = $flow['aging'] ?? [];
        $recent = $flow['recent'] ?? [];

        $open = array_filter($signals, static fn ($s) => $s['open'] === true);
        $high = array_filter($open, static fn ($s) => in_array($s['severity'], ['high', 'critical'], true));

        return [
            $this->tile('people', 'People', $people, 'count',
                $share === null ? null : round($share * 100, 1).'% of the recorded workforce', 'neutral'),

            $this->tile('open', 'Open work items',
                $ledger === null ? null : (int) $ledger['open'], 'count',
                $ledger === null
                    ? null
                    : (($aging['supported'] ?? false)
                        ? number_format((int) $aging['agedItems']).' older than '.(int) $aging['thresholdDays'].' days'
                        : (string) ($aging['reason'] ?? '')),
                ($aging['supported'] ?? false) && (int) $aging['agedItems'] > 0 ? 'crit' : 'neutral',
                $ledger === null ? 'No dataset attributes work to this unit, so open items cannot be counted.' : null,
            ),

            $this->tile('resolved', 'Resolved recently',
                ($recent['supported'] ?? false) ? (int) $recent['resolved'] : null, 'count',
                ($recent['supported'] ?? false)
                    ? 'in the '.(int) $recent['weeks'].' weeks to '.(string) $recent['to']
                    : null,
                'good',
                ($recent['supported'] ?? false) ? null : (string) ($recent['reason'] ?? 'No dated work is attributed to this unit.'),
            ),

            $this->tile('signals', 'Signals', count($signals), 'count',
                sprintf('%d open · %d high severity', count($open), count($high)),
                count($high) > 0 ? 'crit' : (count($open) > 0 ? 'warn' : 'good')),

            $this->tile('evidence', 'Evidence',
                ($profile['evidence']['supported'] ?? false) ? (int) $profile['evidence']['total'] : null,
                'count', 'records behind those signals', 'neutral',
                ($profile['evidence']['supported'] ?? false) ? null : (string) ($profile['evidence']['reason'] ?? '')),

            $this->tile('capability', 'Capability coverage',
                $capabilities['coveragePct'], 'percent',
                $capabilities['caption'], $capabilities['tone'],
                $capabilities['reason']),
        ];
    }

    /** @return array<string, mixed> */
    private function tile(
        string $key,
        string $label,
        int|float|null $value,
        string $format,
        ?string $hint,
        string $tone = 'neutral',
        ?string $reason = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'format' => $format,
            'hint' => $hint === '' ? null : $hint,
            'tone' => $tone,
            // Present exactly when `value` is null. The screen renders this
            // sentence in place of the number; it never renders a 0 or a dash.
            'reason' => $value === null ? ($reason ?: 'Not recorded by any connected source.') : null,
        ];
    }

    /* ══════════════════════════════════════════════════════════ the sections ══ */

    /**
     * What this unit's records say about where it stands, and what is left to do.
     *
     * GENERATED FROM THE FIGURES, NOT WRITTEN FOR THEM. Every sentence names the
     * number it came from — the narrative is DepartmentProfile's, produced from
     * the same aggregates the panels render, so a reader can check each clause
     * against a panel. Nothing is emitted where the input is null.
     *
     * The task list is the same discipline: a task exists because a measurement
     * produced it, and each carries the measurement as its reason.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $ledger
     * @param  array<string, mixed>  $flow
     * @param  array<int, array<string, mixed>>  $blindSpots
     * @return array<string, mixed>
     */
    private function state(array $profile, ?array $ledger, array $flow, array $blindSpots): array
    {
        $narrative = (array) ($profile['narrative'] ?? []);
        $summary = [];

        foreach (['observation', 'trend', 'risk'] as $kind) {
            foreach ($narrative as $line) {
                if ($line['kind'] === $kind) {
                    $summary[] = (string) $line['text'];
                    break;
                }
            }
        }

        $tasks = [];
        $aging = $flow['aging'] ?? [];
        $recent = $flow['recent'] ?? [];

        if (($aging['supported'] ?? false) && (int) $aging['agedItems'] > 0) {
            $tasks[] = [
                'title' => sprintf(
                    'Close or reassign %s items open longer than %d days',
                    number_format((int) $aging['agedItems']),
                    (int) $aging['thresholdDays'],
                ),
                'status' => 'todo',
                'meta' => sprintf(
                    'The oldest has been open %s days. Measured over %s open items that carry a raised date.',
                    number_format((int) $aging['oldestDays']),
                    number_format((int) $aging['measured']),
                ),
            ];
        }

        if (($recent['supported'] ?? false) && (int) $recent['received'] > (int) $recent['resolved']) {
            $gap = (int) $recent['received'] - (int) $recent['resolved'];

            $tasks[] = [
                'title' => sprintf('Close the %s-item gap between work arriving and work finishing', number_format($gap)),
                'status' => 'todo',
                'meta' => sprintf(
                    '%s received against %s resolved over the %d weeks to %s.',
                    number_format((int) $recent['received']),
                    number_format((int) $recent['resolved']),
                    (int) $recent['weeks'],
                    (string) $recent['to'],
                ),
            ];
        }

        foreach ($profile['people']['fields'] ?? [] as $field) {
            if ((int) $field['missing'] > 0) {
                $tasks[] = [
                    'title' => sprintf('Record %s for %s people', lcfirst((string) $field['label']), number_format((int) $field['missing'])),
                    'status' => 'todo',
                    'meta' => sprintf(
                        '%s of %s have it. People coverage is a scored dimension, so filling this moves the health score directly.',
                        number_format((int) $field['have']),
                        number_format((int) $field['have'] + (int) $field['missing']),
                    ),
                ];
            }
        }

        foreach ($blindSpots as $spot) {
            $tasks[] = [
                'title' => sprintf('Make %s measurable', lcfirst((string) $spot['dimension'])),
                'status' => 'todo',
                'meta' => (string) $spot['reason'],
            ];
        }

        if ($ledger !== null && (int) $ledger['completed'] > 0) {
            $tasks[] = [
                'title' => sprintf('%s items closed', number_format((int) $ledger['completed'])),
                'status' => 'done',
                'meta' => sprintf(
                    '%s%% of %s classified records in %s ended in a result rather than a cancellation.',
                    round((float) ($ledger['completionRate'] ?? 0) * 100),
                    number_format((int) $ledger['classified']),
                    (string) $ledger['label'],
                ),
            ];
        }

        return [
            'summary' => $summary === []
                ? 'Nothing this model reads is recorded for this unit yet, so there is no state to describe. The blind spots below say what would produce one.'
                : implode(' ', $summary),
            'narrative' => $narrative,
            'tasks' => array_slice($tasks, 0, 6),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $ledger
     * @return array<int, array<string, mixed>>
     */
    private function performance(array $profile, ?array $ledger): array
    {
        $turnaround = $ledger['turnaround'] ?? null;
        $facets = $ledger['facets'] ?? null;
        $recurrence = ($facets['supported'] ?? false) ? ($facets['recurrence'] ?? null) : null;

        return [
            $this->measure(
                'completion',
                'Work completed',
                $ledger === null ? null : $ledger['completionRate'],
                'rate',
                $ledger === null
                    ? 'No dataset attributes work to this unit, so completion cannot be measured.'
                    : sprintf('%s of %s classified records', number_format((int) $ledger['completed']), number_format((int) $ledger['classified'])),
                $ledger['label'] ?? null,
            ),
            $this->measure(
                'turnaround',
                'Average time to close',
                ($turnaround['supported'] ?? false) ? $turnaround['averageDays'] : null,
                'days',
                ($turnaround['supported'] ?? false)
                    ? sprintf('measured over %s closed records', number_format((int) $turnaround['measured']))
                    : (string) ($turnaround['reason'] ?? 'No dataset attributes work to this unit, so time-to-close cannot be measured.'),
                $ledger['label'] ?? null,
            ),
            $this->measure(
                'cancellation',
                'Work cancelled',
                $ledger === null ? null : $ledger['cancellationRate'],
                'rate',
                $ledger === null
                    ? 'No dataset attributes work to this unit, so cancellation cannot be measured.'
                    : sprintf('%s of %s classified records ended without a result', number_format((int) $ledger['cancelled']), number_format((int) $ledger['classified'])),
                $ledger['label'] ?? null,
            ),
            /*
              REPEAT RATE, NOT "SLA COMPLIANCE".

              An SLA figure needs a target, and no connected source records one —
              not a column, not a config, not an import profile. Publishing a
              percentage against a threshold this code invented would be the
              single most convincing false number on the page, because it looks
              exactly like the real thing. What IS recorded is whether the same
              subject comes back, which is a genuine service-quality reading.
            */
            $this->measure(
                'repeat',
                'Subjects that came back',
                ($recurrence['supported'] ?? false) ? $recurrence['repeatRate'] : null,
                'rate',
                ($recurrence['supported'] ?? false)
                    ? sprintf('%s of %s distinct subjects appear more than once', number_format((int) $recurrence['repeated']), number_format((int) $recurrence['subjects']))
                    : (string) (($facets['reason'] ?? null) ?: 'No dataset attributes work to this unit, so repeat activity cannot be measured.'),
                $ledger['label'] ?? null,
            ),
            $this->measure(
                'sla',
                'Resolution within target',
                null,
                'rate',
                'No connected source records a resolution target for this unit — no column, no import profile and no organization setting carries one. A percentage against a target this screen invented would look exactly like a measured one.',
                null,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $ledger
     * @param  array<string, mixed>  $flow
     * @return array<int, array<string, mixed>>
     */
    private function workload(array $profile, ?array $ledger, array $flow): array
    {
        $people = (int) ($profile['people']['total'] ?? 0);
        $aging = $flow['aging'] ?? [];
        $unclaimed = $profile['unclaimedWork'] ?? null;

        return [
            $this->measure(
                'backlog',
                'Open backlog',
                $ledger === null ? null : (int) $ledger['open'],
                'count',
                $ledger === null
                    ? 'No dataset attributes work to this unit, so its backlog cannot be counted.'
                    : sprintf('of %s classified records', number_format((int) $ledger['classified'])),
                $ledger['label'] ?? null,
            ),
            $this->measure(
                'perPerson',
                'Open work per person',
                ($ledger === null || $people === 0) ? null : round((int) $ledger['open'] / $people, 2),
                'decimal',
                $people === 0
                    ? 'Nobody is assigned to this unit in the source system, so work per person has no denominator.'
                    : ($ledger === null
                        ? 'No dataset attributes work to this unit, so work per person cannot be measured.'
                        : sprintf('across %s people', number_format($people))),
                $ledger['label'] ?? null,
            ),
            $this->measure(
                'aged',
                'Open longer than '.(int) ($aging['thresholdDays'] ?? 14).' days',
                ($aging['supported'] ?? false) ? $aging['agedShare'] : null,
                'rate',
                ($aging['supported'] ?? false)
                    ? sprintf('%s of %s open items that carry a raised date', number_format((int) $aging['agedItems']), number_format((int) $aging['measured']))
                    : (string) ($aging['reason'] ?? 'No dataset attributes work to this unit, so aging cannot be measured.'),
                $ledger['label'] ?? null,
            ),
            /*
              WORK BOOKED AGAINST A DIFFERENT ROW OF THE SAME REGISTER.

              Not a blocker and not merged in: this ERP holds two rows for one
              real unit — the workforce on one, the imported work on the other —
              and reporting the pairing is the only honest thing to do with it.
              Moving those records onto this unit would be inventing the
              organization's structure. See DepartmentIntelligenceMetrics.
            */
            $this->measure(
                'unclaimed',
                'Booked against a paired register row',
                $unclaimed === null ? null : (int) $unclaimed['records'],
                'count',
                $unclaimed === null
                    ? 'No other unit on this register carries work that looks like this one\'s.'
                    : sprintf('recorded against "%s", a separate row on this register', (string) $unclaimed['label']),
                null,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function measure(
        string $key,
        string $label,
        int|float|null $value,
        string $format,
        string $hint,
        ?string $source,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'format' => $format,
            // When `value` is null this IS the content of the row: the sentence
            // saying what is missing, never a dash and never a zero.
            'hint' => $hint,
            'measurable' => $value !== null,
            'source' => $source,
        ];
    }

    /**
     * Received against resolved, with the one arithmetic consequence stated.
     *
     * THE PROJECTION IS DIVISION, NOT A FORECAST. It says how long the CURRENT
     * gap would take to double the CURRENT backlog if neither changed — which is
     * a way of reading the chart, not a prediction that anything will hold. It is
     * suppressed entirely where the gap is zero or negative, because a backlog
     * that is shrinking has no doubling time and a fitted curve over eight points
     * would be a model the data cannot support.
     *
     * @param  array<string, mixed>|null  $ledger
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>
     */
    private function activity(?array $ledger, array $flow, array $profile): array
    {
        $weeks = (array) ($flow['weeks'] ?? []);

        /*
          THE LABEL BASIS DRAWS A DIFFERENT CHART, AND SAYS SO.

          The label aggregate carries a monthly VOLUME per unit and no closure
          dates, so arriving and finishing work cannot be separated on it. One
          line is drawn, `resolved` is null on every point, and the caption
          states the granularity — rather than either inventing a second series
          or leaving the section blank on a unit that has three years of history.
        */
        if ($weeks === [] && $ledger !== null && $ledger['basis'] === 'label') {
            $series = (array) ($profile['trend']['series'] ?? []);

            if ($series !== []) {
                return [
                    'supported' => true,
                    'granularity' => 'month',
                    'weeks' => array_map(static fn ($point) => [
                        'weekStart' => (string) $point['period'],
                        'received' => (int) $point['records'],
                        // Not zero: closure is not recorded on this basis, and a
                        // zero line would read as "nothing was ever finished".
                        'resolved' => null,
                    ], $series),
                    'received' => array_sum(array_column($series, 'records')),
                    'resolved' => null,
                    'projection' => null,
                    'source' => $ledger['label'],
                    'sourceFiles' => [],
                    'note' => 'Monthly volume of work booked to this unit\'s name. Work finishing cannot be plotted beside work arriving on this attribution, because the records do not carry a closure date readable per unit.',
                    'reason' => null,
                ];
            }
        }

        if ($weeks === []) {
            return [
                'supported' => false,
                'granularity' => null,
                'weeks' => [],
                'projection' => null,
                'source' => $ledger['label'] ?? null,
                'note' => null,
                'reason' => $ledger === null
                    ? 'No dataset attributes work to this unit, so its activity over time cannot be plotted.'
                    : (string) ($flow['recent']['reason'] ?? 'No record this unit owns carries a date, so its activity over time cannot be plotted.'),
            ];
        }

        $received = array_sum(array_column($weeks, 'received'));
        $resolved = array_sum(array_column($weeks, 'resolved'));
        $gap = ($received - $resolved) / max(1, count($weeks));
        $backlog = $ledger === null ? null : (int) $ledger['open'];

        $projection = null;

        if ($gap > 0 && $backlog !== null && $backlog > 0) {
            $projection = [
                'weeklyGap' => (int) round($gap),
                'backlogDoubleWeeks' => (int) ceil($backlog / $gap),
                'note' => sprintf(
                    'Work is arriving about %s items a week faster than it closes. If both rates held, the %s open items would double in about %d weeks.',
                    number_format((int) round($gap)),
                    number_format($backlog),
                    (int) ceil($backlog / $gap),
                ),
            ];
        } elseif ($gap < 0) {
            $projection = [
                'weeklyGap' => (int) round($gap),
                'backlogDoubleWeeks' => null,
                'note' => sprintf(
                    'Work is closing about %s items a week faster than it arrives, so the backlog is shrinking rather than growing.',
                    number_format(abs((int) round($gap))),
                ),
            ];
        }

        return [
            'supported' => true,
            'granularity' => 'week',
            'weeks' => $weeks,
            'received' => $received,
            'resolved' => $resolved,
            'projection' => $projection,
            'source' => $ledger['label'] ?? null,
            'sourceFiles' => $ledger['source'] ?? [],
            'note' => null,
            'reason' => null,
        ];
    }

    /* ════════════════════════════════════════════════════════════════ people ══ */

    /**
     * The roster, one page at a time, ordered by measured volume.
     *
     * PAGED ON THE SERVER. A unit of 770 people is a real shape on this data, and
     * a screen that downloads all of them to show five is a screen that stops
     * working on the largest unit — which is the one most worth looking at.
     *
     * NO PER-PERSON VERDICT IS PUBLISHED, and the reason travels with the list.
     * The roster carries no role for anyone here, so a trainee and a supervisor
     * are indistinguishable, and a ranking by volume would read as a performance
     * judgement between two people doing different jobs. Volume is shown as
     * volume; nothing is graded.
     *
     * @param  array<string, mixed>|null  $work
     * @return array<string, mixed>
     */
    private function people(
        string $tenant,
        string $departmentId,
        ResolvedSource $person,
        ?array $work,
        int $page,
        int $pageSize,
    ): array {
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, $pageSize));
        $page = max(1, $page);

        $base = DB::table($person->table)
            ->where($person->tenantKey, $tenant)
            ->where($person->field('unit'), $departmentId);

        // The same liveness rule the roster reader applies, so the page count and
        // the attribution can never be built from different populations.
        $this->rosters->activeRows($base, $person);

        $total = (clone $base)->count();
        $pages = (int) max(1, ceil($total / $pageSize));
        $page = min($page, $pages);

        $columns = array_values(array_unique(array_filter([
            $person->primaryKey,
            $person->has('firstName') ? $person->field('firstName') : null,
            $person->has('lastName') ? $person->field('lastName') : null,
            $person->has('email') ? $person->field('email') : null,
            $person->has('position') ? $person->field('position') : null,
            $person->has('externalRef') ? $person->field('externalRef') : null,
        ])));

        /*
          ORDERED BY THE PRIMARY KEY, THEN RE-ORDERED BY VOLUME WITHIN THE PAGE.

          The volume figures live in an owner-keyed aggregate, not in a column, so
          the database cannot sort by them and a global sort would mean loading
          every person. The order is therefore stable and stated rather than
          silently partial: the caller is told the sort is by roster order, and
          the volume column is labelled as a figure, not a rank.
        */
        $rows = (clone $base)
            ->orderBy($person->primaryKey)
            ->forPage($page, $pageSize)
            ->get($columns);

        $perOwner = (array) ($work['perOwner'] ?? []);
        $workDataset = $work['work']['label'] ?? null;
        $presence = $work['presence'] ?? ['supported' => false];

        $items = [];

        foreach ($rows as $raw) {
            $row = (array) $raw;

            // Built by the roster reader, so the name that links a record to this
            // unit is the same name that links it to this person.
            $name = $this->rosters->displayName($row, $person);
            $facts = $name === null ? null : ($perOwner[$name] ?? null);

            $items[] = [
                'id' => (string) $row[$person->primaryKey],
                'name' => $name,
                'email' => $person->has('email') ? $this->blankToNull($row[$person->field('email')] ?? null) : null,
                // NULL, NOT "Unassigned". The roster carries no role for these
                // people; a placeholder string would be indistinguishable on
                // screen from a role literally called that.
                'role' => $person->has('position') ? $this->positionTitle($tenant, $row[$person->field('position')] ?? null) : null,
                'externalRef' => $person->has('externalRef') ? $this->blankToNull($row[$person->field('externalRef')] ?? null) : null,

                // Absent from the attribution map means the imports never name
                // this person — reported as unmeasured, never as zero work.
                'linked' => $facts !== null,
                'records' => $facts['records'] ?? null,
                'handled' => $facts['handled'] ?? null,
                'open' => $facts['open'] ?? null,
                'completed' => $facts['completed'] ?? null,
                'presenceRate' => $facts['presenceRate'] ?? null,
                'presenceDays' => $facts['presenceDays'] ?? null,
                'reason' => $facts === null
                    ? 'No imported record names this person, so nothing can be attributed to them.'
                    : null,
            ];
        }

        return [
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'pages' => $pages,
            'from' => $total === 0 ? 0 : (($page - 1) * $pageSize) + 1,
            'to' => min($page * $pageSize, $total),
            'items' => $items,
            'linkedTotal' => count($perOwner),
            'workLabel' => $workDataset,
            'presenceLabel' => ($presence['supported'] ?? false) ? $presence['label'] : null,
            'presenceMethod' => ($presence['supported'] ?? false) ? $presence['method'] : null,
            'sort' => 'Roster order. Volume is shown as a figure, not a ranking.',
            'verdictNote' => 'No per-person verdict is published. This roster records no role, so two people doing different jobs cannot be told apart, and grading them on volume alone would compare a trainee with a supervisor.',
        ];
    }

    private function positionTitle(string $tenant, mixed $positionId): ?string
    {
        if ($positionId === null || $positionId === '' || (int) $positionId === 0) {
            return null;
        }

        try {
            $source = $this->resolver->resolve($tenant, 'Position');
        } catch (\Throwable) {
            return null;
        }

        if (! $source->has('title')) {
            return null;
        }

        $title = DB::table($source->table)
            ->where($source->primaryKey, $positionId)
            ->value($source->field('title'));

        return $this->blankToNull($title);
    }

    /* ═══════════════════════════════════════════════════ the comparison set ══ */

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function contribution(array $profile): array
    {
        $contribution = (array) ($profile['contribution'] ?? []);
        $position = (array) ($profile['position'] ?? []);

        return [
            'workforceSharePct' => $contribution['peopleShare'] ?? null,
            'recordSharePct' => $contribution['recordShare'] ?? null,
            'recordShareReason' => ($contribution['recordShare'] ?? null) === null
                ? 'Share of the organization\'s work needs every unit\'s work attributed the same way, and this organization does not attribute all of it.'
                : null,
            'rank' => $position['score']['rank'] ?? null,
            'rankOf' => $position['score']['of'] ?? null,
            'organizationAverage' => $position['organizationAverage'] ?? null,
            'difference' => $position['difference'] ?? null,
            'activityRank' => $position['activity']['rank'] ?? null,
            'activityOf' => $position['activity']['of'] ?? null,
            'sizeRank' => $position['size']['rank'] ?? null,
            'sizeOf' => $position['size']['of'] ?? null,
            'note' => 'Ranks are over the units this reader can see, so every position refers to a list they can get back to.',
        ];
    }

    /* ═════════════════════════════════════════════════════════ capabilities ══ */

    /**
     * What this unit is expected to be able to do, and how much of it is assessed.
     *
     * COVERAGE IS ASSESSED-OVER-EXPECTED, and both halves are stated. A unit with
     * five expected capabilities and one assessment is 20% covered; a unit with no
     * expected capabilities is not 0% covered, it is unmeasured, and the two are
     * reported differently.
     *
     * @return array<string, mixed>
     */
    private function capabilities(string $tenant, string $departmentId): array
    {
        if (! Schema::hasTable('hpbrain_capability_assignments')) {
            return $this->capabilityBlank('This installation does not record capabilities.');
        }

        $assignments = DB::table('hpbrain_capability_assignments as a')
            ->leftJoin('hpbrain_capabilities as c', 'c.id', '=', 'a.capability_id')
            ->where('a.tenant_id', $tenant)
            ->where('a.target_type', 'Department')
            ->where('a.target_id', $departmentId)
            ->get(['a.id', 'a.status', 'a.assigned_by', 'c.name', 'c.category', 'c.criticality']);

        if ($assignments->isEmpty()) {
            return $this->capabilityBlank(
                'No capability is expected of this unit on the register, so there is nothing to measure coverage against. Assign the capabilities this unit is meant to hold.',
            );
        }

        $proficiency = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $tenant)
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->orderByDesc('assessed_date')
            ->get()
            ->groupBy('assignment_id')
            ->map(static fn ($rows) => $rows->first());

        $items = [];
        $assessed = 0;

        foreach ($assignments as $assignment) {
            $p = $proficiency->get($assignment->id);

            $levels = $p === null ? [] : array_values(array_filter(
                [$p->knowledge_level, $p->ability_level, $p->skill_level, $p->behaviour_level, $p->attitude_level],
                static fn ($v) => $v !== null,
            ));

            $average = $levels === [] ? null : array_sum(array_map('floatval', $levels)) / count($levels);

            if ($average !== null) {
                $assessed++;
            }

            $items[] = [
                'name' => (string) ($assignment->name ?? 'Unnamed capability'),
                'category' => $this->blankToNull($assignment->category ?? null),
                'criticality' => $this->blankToNull($assignment->criticality ?? null),
                'state' => $p === null ? null : $this->blankToNull($p->capability_state ?? null),
                // 0-5 on the assessment model's own scale, republished as a share
                // of it so one bar can carry it. The scale travels alongside.
                'level' => $average === null ? null : round($average, 2),
                'levelOf' => 5,
                'assessedDate' => $p === null ? null : ($p->assessed_date ?? null),
                'confidence' => $p === null || $p->evidence_confidence === null ? null : round((float) $p->evidence_confidence, 2),
                // Rehearsal data, labelled. See SEEDER_MARKS.
                'seeded' => $this->isSeeded($p->assessed_by ?? null) || $this->isSeeded($assignment->assigned_by ?? null),
                'reason' => $average === null
                    ? 'Expected of this unit but never assessed, so its level is unknown.'
                    : null,
            ];
        }

        $expected = count($items);
        $coverage = $expected > 0 ? (int) round($assessed / $expected * 100) : null;
        $seeded = count(array_filter($items, static fn ($i) => $i['seeded'] === true));

        return [
            'supported' => true,
            'expected' => $expected,
            'assessed' => $assessed,
            'coveragePct' => $coverage,
            'items' => $items,
            'seededCount' => $seeded,
            'caption' => sprintf('%d of %d expected capabilities assessed', $assessed, $expected),
            'tone' => $coverage === null ? 'neutral' : ($coverage >= 80 ? 'good' : ($coverage >= 40 ? 'warn' : 'crit')),
            'note' => $seeded > 0
                ? sprintf('%d of these assessments were written by a demo seeder rather than observed in this organization, and are labelled as such.', $seeded)
                : null,
            'reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function capabilityBlank(string $reason): array
    {
        return [
            'supported' => false,
            'expected' => 0,
            'assessed' => 0,
            'coveragePct' => null,
            'items' => [],
            'seededCount' => 0,
            'caption' => null,
            'tone' => 'neutral',
            'note' => null,
            'reason' => $reason,
        ];
    }

    /* ═════════════════════════════════════════════════════════════ signals ══ */

    /**
     * Every signal raised against this unit, open and closed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function signals(string $tenant, string $departmentId): array
    {
        if (! Schema::hasTable('hpbrain_signals')) {
            return [];
        }

        $rows = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenant)
            ->where('department_id', $departmentId)
            ->orderByDesc('created_date')
            ->limit(25)
            ->get();

        $evidence = Schema::hasTable('hpbrain_evidence') && $rows->isNotEmpty()
            ? DB::table('hpbrain_evidence')
                ->where('tenant_id', $tenant)
                ->whereIn('signal_id', $rows->pluck('id')->all())
                ->selectRaw('signal_id, COUNT(*) AS records')
                ->groupBy('signal_id')
                ->pluck('records', 'signal_id')
            : collect();

        $out = [];

        foreach ($rows as $row) {
            $meta = json_decode((string) ($row->metadata ?? '{}'), true);
            $meta = is_array($meta) ? $meta : [];

            $status = strtolower((string) $row->status);

            $out[] = [
                'id' => (string) $row->id,
                // The rule's own title where it wrote one; otherwise its
                // classification humanised. Never a title composed here from the
                // figures, which would read as a finding nothing recorded.
                'title' => $this->blankToNull($meta['title'] ?? null)
                    ?? ucfirst(str_replace('_', ' ', (string) $row->classification)),
                'detail' => $this->blankToNull($meta['description'] ?? null),
                'severity' => strtolower((string) $row->severity),
                'status' => $status,
                'open' => ! in_array($status, ['resolved', 'closed', 'dismissed'], true),
                'raisedAt' => $row->created_date,
                'updatedAt' => $row->updated_date,
                'confidence' => $row->confidence === null ? null : round((float) $row->confidence, 2),
                'evidenceCount' => (int) ($evidence[$row->id] ?? 0),
                'recommendedAction' => $this->blankToNull($meta['recommendedAction'] ?? null),
                'source' => (string) $row->source,
                // Rehearsal data, labelled rather than hidden or presented as an
                // observation of this organization.
                'seeded' => $this->isSeeded($row->created_by ?? null) || ($meta['demo'] ?? false) === true,
            ];
        }

        return $out;
    }

    /**
     * Work moving between this unit and others.
     *
     * NOT MEASURABLE ON ANY CONNECTED SOURCE, and said so rather than left off the
     * page. An escalation is a relationship between two units, and the record
     * table has one department column: it can say which unit owns a record, never
     * which unit sent it or is holding it up. The mockup this screen was drawn
     * from showed "27 blocked on Field Ops"; nothing recorded here can produce
     * that number, so the panel says what would.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function crossUnitFlow(array $profile): array
    {
        return [
            'supported' => false,
            'items' => [],
            'reason' => 'No connected source records work moving between units. Each imported record names at most one owning department, so escalations out, escalations in, and time blocked on another unit cannot be told apart from work this unit simply holds.',
            'fixLabel' => 'Review the import profile',
            'fixRoute' => 'ingestion',
            'requires' => 'A referring-unit or blocked-by column on the work export, mapped in the import profile.',
        ];
    }

    /* ═════════════════════════════════════════════════════════ blind spots ══ */

    /**
     * Every dimension the model could not measure, with what would fix it.
     *
     * ASSEMBLED FROM THE SAME SENTENCES THE PANELS SHOW, so the list and the
     * panels can never say different things about the same gap. Each entry
     * carries a route into the screen that fixes it, because a blind spot the
     * reader cannot act on is a complaint rather than a finding.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $ledger
     * @param  array<string, mixed>  $flow
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $capabilities
     * @return array<int, array<string, mixed>>
     */
    private function blindSpots(array $profile, ?array $ledger, array $flow, array $signals, array $capabilities): array
    {
        $out = [];

        foreach ($profile['dimensions'] ?? [] as $dimension) {
            if ($dimension['score'] !== null) {
                continue;
            }

            $out[] = [
                'key' => (string) $dimension['key'],
                'dimension' => (string) $dimension['label'],
                'reason' => (string) $dimension['basis'],
                'weight' => (float) $dimension['weight'],
                'fixLabel' => $this->fixLabel((string) $dimension['key']),
                'fixRoute' => $this->fixRoute((string) $dimension['key']),
                'scoredAsZero' => false,
            ];
        }

        if (! ($capabilities['supported'] ?? false)) {
            $out[] = [
                'key' => 'capability',
                'dimension' => 'Capability coverage',
                'reason' => (string) $capabilities['reason'],
                'weight' => null,
                'fixLabel' => 'Assign capabilities',
                'fixRoute' => 'capabilities',
                'scoredAsZero' => false,
            ];
        }

        if (! (($flow['aging']['supported'] ?? false)) && $ledger !== null) {
            $out[] = [
                'key' => 'aging',
                'dimension' => 'Age of open work',
                'reason' => (string) ($flow['aging']['reason'] ?? 'The age of open items cannot be read from the connected source.'),
                'weight' => null,
                'fixLabel' => 'View import profile',
                'fixRoute' => 'ingestion',
                'scoredAsZero' => false,
            ];
        }

        $out[] = [
            'key' => 'flow',
            'dimension' => 'Work moving between units',
            'reason' => (string) $this->crossUnitFlow($profile)['reason'],
            'weight' => null,
            'fixLabel' => 'Review the import profile',
            'fixRoute' => 'ingestion',
            'scoredAsZero' => false,
        ];

        $out[] = [
            'key' => 'sla',
            'dimension' => 'Resolution against target',
            'reason' => 'No connected source records a resolution target for this unit, so compliance against one cannot be computed. Time-to-close IS measured and is shown instead.',
            'weight' => null,
            'fixLabel' => 'Review the import profile',
            'fixRoute' => 'ingestion',
            'scoredAsZero' => false,
        ];

        if ($signals === []) {
            $out[] = [
                'key' => 'signals',
                'dimension' => 'Signals against this unit',
                'reason' => 'No signal names this unit. Signal detection runs over the organization, so this is a finding about the unit rather than a gap — but nothing here can be read as a trend until one is raised.',
                'weight' => null,
                'fixLabel' => 'Open Signals',
                'fixRoute' => 'signals',
                'scoredAsZero' => false,
            ];
        }

        return $out;
    }

    private function fixLabel(string $key): string
    {
        return match ($key) {
            'operational', 'workload', 'execution', 'service' => 'Open in Ingestion',
            'people' => 'Complete the roster',
            'signal' => 'Open Signals',
            'confidence' => 'Open in Ingestion',
            default => 'Open in Ingestion',
        };
    }

    private function fixRoute(string $key): string
    {
        return match ($key) {
            'people' => 'people',
            'signal' => 'signals',
            default => 'ingestion',
        };
    }

    /* ═══════════════════════════════════════════════════════ the arithmetic ══ */

    /**
     * The score, shown as the sum it is.
     *
     * THE WEIGHTS COME FROM THE SCORING ENGINE, not from a copy. DepartmentProfile
     * publishes them on each dimension and this arranges them; there is no second
     * table of weights anywhere, and no client-side one, so a panel explaining the
     * formula cannot come to explain a formula the server is not using.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function scoreExplain(array $profile): array
    {
        $measured = array_values(array_filter(
            (array) ($profile['dimensions'] ?? []),
            static fn ($d) => $d['score'] !== null,
        ));

        $excluded = array_values(array_filter(
            (array) ($profile['dimensions'] ?? []),
            static fn ($d) => $d['score'] === null,
        ));

        $totalWeight = array_sum(array_map(static fn ($d) => (float) $d['weight'], $measured));
        $components = [];

        foreach ($measured as $dimension) {
            /*
              THE WEIGHT IS SHOWN AS THE SHARE IT ACTUALLY IS.

              The engine divides by the SURVIVING weight, so a dimension worth
              1.25 out of a possible 7.75 is worth 1.25 out of 3.5 once four
              dimensions drop out — and it is that second figure the points on
              screen are built from. Printing the raw 1.25 beside points computed
              from the re-based share is how a "how this is calculated" table
              stops adding up in front of the reader.
            */
            $share = $totalWeight > 0 ? (float) $dimension['weight'] / $totalWeight : 0.0;

            $components[] = [
                'key' => (string) $dimension['key'],
                'label' => (string) $dimension['label'],
                'basis' => (string) $dimension['basis'],
                'attribution' => $dimension['attribution'] ?? null,
                'valuePct' => (int) $dimension['score'],
                'rawWeight' => (float) $dimension['weight'],
                'weight' => round($share, 4),
                'points' => round((int) $dimension['score'] * $share, 1),
            ];
        }

        return [
            'components' => $components,
            'excluded' => array_map(static fn ($d) => [
                'key' => (string) $d['key'],
                'label' => (string) $d['label'],
                'rawWeight' => (float) $d['weight'],
                'reason' => (string) $d['basis'],
            ], $excluded),
            'total' => $profile['score'] ?? null,
            'totalWeight' => round($totalWeight, 2),
            'modelWeight' => round($totalWeight + array_sum(array_map(static fn ($d) => (float) $d['weight'], $excluded)), 2),
            // The sentence changes shape when nothing was excluded, rather than
            // announcing "the 0 dimensions that cannot be measured" — a rule
            // stated about an empty set reads as a warning about a problem the
            // reader then goes looking for.
            'note' => $excluded === []
                ? 'Every dimension of this model is measurable for this unit, so the score is built from all of them and nothing was excluded. Where a dimension cannot be measured it leaves the average rather than entering it as a zero, and its absence lowers data confidence instead of health.'
                : 'The '.count($excluded).' '.(count($excluded) === 1 ? 'dimension' : 'dimensions')
                    .' that cannot be measured are excluded from the average rather than scored as zero — their absence lowers data confidence, not health. Each one rejoins the formula at its own weight as soon as the data behind it exists.',
        ];
    }

    /**
     * One thing to do next, and honesty about whether we know why.
     *
     * THE SUFFICIENCY GATE IS THE POINT. A recommendation is only as good as the
     * questions that could be answered before making it, so the count of answered
     * questions travels with it, and where the answered set does not establish a
     * cause the root cause is published as UNDETERMINED with the missing input
     * named. A confident recommendation on top of an unknown cause is the failure
     * mode this whole screen is built against.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>|null  $ledger
     * @param  array<string, mixed>  $flow
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<int, array<string, mixed>>  $blindSpots
     * @return array<string, mixed>
     */
    private function recommendation(array $profile, ?array $ledger, array $flow, array $signals, array $blindSpots): array
    {
        $action = (array) ($profile['nextAction'] ?? []);

        /*
          THE SEVEN QUESTIONS THIS MODEL ASKS BEFORE IT RECOMMENDS ANYTHING.
          Each is answerable or not from what the connected sources record, and
          the gate below is simply how many came back with an answer.
        */
        $questions = [
            'Who works here?' => (int) ($profile['people']['total'] ?? 0) > 0,
            'What work does this unit do?' => $ledger !== null,
            'How much of it is finished?' => $ledger !== null && $ledger['completionRate'] !== null,
            'How long does it take?' => ($ledger['turnaround']['supported'] ?? false) === true,
            'How old is what is still open?' => ($flow['aging']['supported'] ?? false) === true,
            'Is work arriving faster than it closes?' => ($flow['recent']['supported'] ?? false) === true,
            'Who or what is holding it up?' => false, // no source records cross-unit flow
        ];

        $answered = count(array_filter($questions));
        $total = count($questions);

        /*
          A CAUSE IS DETERMINED ONLY WHERE SOMETHING RECORDED ESTABLISHES IT.
          A gap between arriving and closing work says WHAT is happening; it does
          not say why, and calling a capacity shortfall "the cause" because it is
          the most familiar explanation would be a guess wearing a verdict's
          clothes. Cross-unit flow is unrecorded, so the one alternative
          explanation cannot be ruled out — which is exactly what leaves this
          UNDETERMINED rather than merely uncertain.
        */
        $rootCause = 'UNDETERMINED';
        $missing = 'No connected source records work moving between units, so a backlog caused by this unit and a backlog caused by another unit holding its work look identical.';

        return [
            'title' => (string) ($action['title'] ?? 'Nothing is recommended yet'),
            'body' => (string) ($action['detail'] ?? 'Not enough is measurable to recommend an action.'),
            'target' => (string) ($action['target'] ?? 'ingestion'),
            'confidence' => $answered >= 6 ? 'moderate' : ($answered >= 4 ? 'low' : 'very low'),
            'confidenceReason' => sprintf(
                'Based on %d of %d questions this model can answer from the connected sources.',
                $answered,
                $total,
            ),
            'sufficiencyGate' => [
                'answered' => $answered,
                'total' => $total,
                'questions' => array_map(
                    static fn ($q, $a) => ['question' => $q, 'answered' => $a],
                    array_keys($questions),
                    array_values($questions),
                ),
            ],
            'rootCause' => $rootCause,
            'rootCauseMissing' => $missing,
            'alternative' => $ledger === null
                ? null
                : 'The alternative reading is that the work is being held elsewhere and this unit is carrying the wait. Ruling it in or out needs a referring-unit column on the work export; until then neither reading can be preferred on evidence.',
            'blindSpotsRemaining' => count($blindSpots),
        ];
    }

    /**
     * Where the figures on this page came from.
     *
     * @param  array<string, mixed>|null  $work
     * @param  array<int, array<string, mixed>>  $signals
     * @param  array<string, mixed>  $capabilities
     * @return array<int, array<string, mixed>>
     */
    private function sources(?array $work, array $signals, array $capabilities): array
    {
        $out = [];

        foreach ((array) ($work['datasets'] ?? []) as $dataset) {
            $out[] = [
                'kind' => 'import',
                'label' => (string) $dataset['label'],
                'records' => (int) $dataset['records'],
                'files' => (array) ($dataset['source'] ?? []),
            ];
        }

        if ($signals !== []) {
            $out[] = ['kind' => 'signals', 'label' => 'Signals raised against this unit', 'records' => count($signals), 'files' => []];
        }

        if ($capabilities['supported'] ?? false) {
            $out[] = ['kind' => 'capabilities', 'label' => 'Capability register', 'records' => (int) $capabilities['expected'], 'files' => []];
        }

        return $out;
    }

    /* ══════════════════════════════════════════════════════════════ plumbing ══ */

    /**
     * @return array<string, mixed>|null
     */
    private function unitRow(string $tenant, string $departmentId, ResolvedSource $unit): ?array
    {
        $query = DB::table($unit->table)
            ->where($unit->primaryKey, $departmentId)
            ->where($unit->tenantKey, $tenant);

        if ($unit->has('deletedAt')) {
            $query->whereNull($unit->field('deletedAt'));
        }

        $this->visibility->apply($query, $unit, $tenant);

        $row = $query->first();

        return $row === null ? null : (array) $row;
    }

    private function isSeeded(mixed $author): bool
    {
        $value = mb_strtolower(trim((string) ($author ?? '')));

        if ($value === '') {
            return false;
        }

        foreach (self::SEEDER_MARKS as $mark) {
            if (str_contains($value, $mark)) {
                return true;
            }
        }

        return false;
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
