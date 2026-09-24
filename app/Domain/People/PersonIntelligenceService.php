<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\ResolvedSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * D1-D7: builds the full Person Intelligence payload for the redesigned
 * profile screen.
 *
 * The payload exactly matches the OpenAPI contract in
 * contracts/openapi/hpbrain.openapi.yaml under the
 * `PersonIntelligenceResponse` schema. The contract is the API; this class
 * is the only place that decides which components feed the standing and how.
 *
 * HONESTY RULES (R2-R5). Every value is null when the input that would let
 * us compute it is not present, and "UNDETERMINED" is rendered as a literal
 * string by the frontend, not as zero. Volume is used ONLY for consistency
 * while the person has no assigned role; while a role is unassigned,
 * contribution feeds standing via week-to-week variance, never as a ranking.
 */
final class PersonIntelligenceService
{
    public function __construct(
        private readonly PersonAttachmentService $attachment,
        private readonly PersonContributionPresenceService $contrib,
        private readonly PersonRecordsService $records,
    ) {
    }

    public function build(string $tenantId, string $personId): ?array
    {
        return $this->buildWithPage($tenantId, $personId, 1, self::DEFAULT_PAGE_SIZE);
    }

    public function buildWithPage(string $tenantId, string $personId, int $page, int $pageSize): ?array
    {
        $person = $this->person($tenantId, $personId);
        if ($person === null) {
            return null;
        }

        $rules = $this->attachment->rules($tenantId, $personId)['rules'] ?? [];
        $deptId = $person['department_id'] ?? null;

        $contrib = $this->contrib->build($tenantId, $personId, is_int($deptId) ? $deptId : null, $rules);
        $mismatches = $this->records->mismatches($tenantId, $rules);
        $cleared = $this->records->cleared($tenantId, $rules);
        $records = $this->records->build($tenantId, $personId, $page, $pageSize);

        $capability = $this->capability($tenantId, $personId, $person);
        $loop = $this->loop($tenantId, $personId);
        $previous = $this->previousSnapshot($tenantId, $personId);
        $currentSnapshot = $this->snapshotData($contrib, $mismatches, $capability);

        $standing = $this->standing($contrib, $capability, $mismatches, $person);
        $confidence = $this->confidence($contrib, $capability, $loop, $person);
        $sinceRefresh = $this->sinceRefresh($previous, $currentSnapshot);
        $blindSpots = $this->blindSpots($contrib, $capability, $mismatches, $person);
        $scoreExplain = $this->scoreExplain($standing, $contrib, $capability, $mismatches, $person);
        $recommendation = $this->recommendation($contrib, $capability, $mismatches, $person, $standing);

        $this->writeSnapshot($tenantId, $personId, $currentSnapshot);

        return [
            'person' => $person,
            'standing' => $standing,
            'confidence' => $confidence,
            'sinceRefresh' => $sinceRefresh,
            'contribution' => [
                'handledTotal' => $contrib['handledTotal'],
                'handled30d' => $contrib['handled30d'],
                'weeklyTrend' => $contrib['weeklyTrend'],
                'teamSharePct' => $contrib['teamSharePct'],
                'highLoad' => $contrib['highLoad'],
                'supervisedCount' => $contrib['supervisedCount'],
            ],
            'presence' => $contrib['presence'],
            'consistency' => [
                'mismatches' => [
                    'count' => $mismatches['count'],
                    'windowDays' => $mismatches['windowDays'],
                    'sampleDates' => $mismatches['sampleDates'],
                    'likelyCause' => $mismatches['likelyCause'],
                ],
                'cleared' => $cleared,
            ],
            'capability' => $capability,
            'loop' => $loop,
            'recordsSummary' => $records['summary'],
            'recordsPage' => $records['page'],
            'blindSpots' => $blindSpots,
            'scoreExplain' => $scoreExplain,
            'recommendation' => $recommendation,
        ];
    }

    private const DEFAULT_PAGE_SIZE = 25;

    /** @return array<string, mixed>|null */
    private function person(string $tenantId, string $personId): ?array
    {
        try {
            $source = app(EntityResolver::class)->resolve($tenantId, 'Person');
        } catch (Throwable) {
            return null;
        }

        $row = DB::table($source->table)
            ->where($source->primaryKey, $personId)
            ->where($source->tenantKey, $tenantId)
            ->whereNull('deleted_at')
            ->where($source->field('status'), 1)
            ->first();

        if (! $row) {
            return null;
        }

        $row = (array) $row;
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        $deptId = isset($row['department_id']) ? (int) $row['department_id'] : null;
        $deptName = null;
        $deptCode = null;
        if ($deptId !== null && Schema::hasTable('hrms_departments')) {
            $dept = DB::table('hrms_departments')->where('id', $deptId)->first();
            if ($dept) {
                $deptName = (string) ($dept->department ?? '');
                $deptCode = $deptName !== '' ? strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $deptName)) : null;
            }
        }

        $profileName = null;
        if (isset($row['user_profile_id']) && Schema::hasTable('tbluserprofilemaster')) {
            $p = DB::table('tbluserprofilemaster')->where('id', $row['user_profile_id'])->first();
            if ($p) {
                $profileName = (string) ($p->name ?? null);
            }
        }

        $orgName = null;
        if (Schema::hasTable('institute_detail')) {
            $org = DB::table('institute_detail')->where('sub_institute_id', $tenantId)->first();
            if ($org) {
                $orgName = (string) ($org->organization_name ?? '');
            }
        }

        $hasPosition = isset($row['position']) || isset($row['jobtitle_id']);
        $roleAssigned = $hasPosition && ! empty($row['position'] ?? $row['jobtitle_id'] ?? null);

        return [
            'id' => (string) $personId,
            'name' => trim($first . ' ' . $last),
            'firstName' => $first,
            'lastName' => $last,
            'email' => $row['email'] ?? null,
            'phone' => $row['mobile'] ?? null,
            'gender' => $row['gender'] ?? null,
            'role' => $profileName,
            'roleAssigned' => $roleAssigned,
            'departmentId' => $deptId,
            'departmentName' => $deptName,
            'departmentCode' => $deptCode,
            'orgName' => $orgName,
            'recordCreated' => $row['created_at'] ?? null,
            'recordCount' => (int) DB::table('hpbrain_operational_records')
                ->where('tenant_id', $tenantId)
                ->where(function ($w) use ($first, $last, $personId) {
                    $full = trim($first . ' ' . $last);
                    $w->where('owner_name', $full)
                      ->orWhere('supervisor_name', $full)
                      ->orWhere('subject_ref', (string) $personId);
                })
                ->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    private function capability(string $tenantId, string $personId, array $person): array
    {
        if (! Schema::hasTable('hpbrain_capability_assignments')) {
            return [
                'name' => null, 'score' => null, 'of' => 5,
                'kasba' => ['knowledge' => null, 'ability' => null, 'skill' => null, 'behaviour' => null, 'attitude' => null],
                'assessedAt' => null,
                'vsTeam' => null, 'vsRole' => 'UNDETERMINED', 'trajectory' => 'UNDETERMINED',
                'unlock' => 'Schedule a KASBA assessment to start measuring capability.',
            ];
        }

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $tenantId)
            ->where('target_type', 'Person')
            ->where('target_id', $personId)
            ->orderBy('assigned_date')
            ->get();

        if ($assignments->isEmpty()) {
            return [
                'name' => null, 'score' => null, 'of' => 5,
                'kasba' => ['knowledge' => null, 'ability' => null, 'skill' => null, 'behaviour' => null, 'attitude' => null],
                'assessedAt' => null,
                'vsTeam' => null, 'vsRole' => 'UNDETERMINED', 'trajectory' => 'UNDETERMINED',
                'unlock' => 'Assign a capability to this person to start measuring.',
            ];
        }

        $proficiency = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $tenantId)
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->orderByDesc('assessed_date')
            ->get()
            ->groupBy('assignment_id')
            ->map(fn ($rows) => $rows->first());

        $nameByCap = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $assignments->pluck('capability_id')->all())
            ->pluck('name', 'id');

        $primary = $assignments->first();
        $latest = $proficiency->get($primary->id);
        $previous = $proficiency->slice(1, 1)->first();

        $kasba = [
            'knowledge' => $latest->knowledge_level ?? null,
            'ability'   => $latest->ability_level ?? null,
            'skill'     => $latest->skill_level ?? null,
            'behaviour' => $latest->behaviour_level ?? null,
            'attitude'  => $latest->attitude_level ?? null,
        ];

        $assessed = array_filter($kasba, fn ($v) => $v !== null);
        $overall = $assessed === [] ? null : round(array_sum($assessed) / count($assessed), 2);

        /*
            LIKE FOR LIKE, OR THE COMPARISON IS A FICTION.

            This person's figure is their KASBA overall — the mean of the five
            dimensions. The team average was being read from `knowledge_level`
            alone, so the screen put an overall score beside a knowledge-only
            average and called the difference a gap. Whichever way the two
            happened to fall, the arrow was measuring the difference between two
            different questions.

            Each teammate is now reduced the same way this person is, and only
            then averaged.
        */
        $teamRows = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $tenantId)
            ->whereIn('assignment_id', DB::table('hpbrain_capability_assignments')
                ->where('tenant_id', $tenantId)
                ->where('capability_id', $primary->capability_id)
                ->pluck('id'))
            ->get(['assignment_id', 'assessed_date', 'knowledge_level', 'ability_level', 'skill_level', 'behaviour_level', 'attitude_level']);

        $latestPerAssignment = $teamRows
            ->sortByDesc('assessed_date')
            ->groupBy('assignment_id')
            ->map(fn ($rows) => $rows->first());

        $teamOveralls = $latestPerAssignment
            ->map(function ($row) {
                $levels = array_filter([
                    $row->knowledge_level ?? null,
                    $row->ability_level ?? null,
                    $row->skill_level ?? null,
                    $row->behaviour_level ?? null,
                    $row->attitude_level ?? null,
                ], fn ($v) => $v !== null);

                return $levels === [] ? null : array_sum(array_map('floatval', $levels)) / count($levels);
            })
            ->filter(fn ($v) => $v !== null)
            ->values();

        $teamAvg = $teamOveralls->isEmpty() ? null : round((float) $teamOveralls->avg(), 2);

        $vsRole = 'UNDETERMINED';
        $roleValue = null;
        if (! ($person['roleAssigned'] ?? false)) {
            $vsRole = 'UNDETERMINED';
        } else {
            $jobRoleId = $person['position'] ?? $person['jobtitle_id'] ?? null;
            if (is_int($jobRoleId) || is_string($jobRoleId)) {
                $req = DB::table('hpbrain_job_role_capability_requirements')
                    ->where('tenant_id', $tenantId)
                    ->where('job_role_id', $jobRoleId)
                    ->where('capability_id', $primary->capability_id)
                    ->first();
                if ($req) {
                    $required = (float) $req->required_level;
                    $vsRole = ['value' => $overall, 'required' => round($required, 2)];
                }
            }
        }

        $trajectory = 'UNDETERMINED';
        if ($previous && $overall !== null) {
            $prevAssessed = array_filter([
                'knowledge' => $previous->knowledge_level ?? null,
                'ability' => $previous->ability_level ?? null,
                'skill' => $previous->skill_level ?? null,
                'behaviour' => $previous->behaviour_level ?? null,
                'attitude' => $previous->attitude_level ?? null,
            ], fn ($v) => $v !== null);
            $prevOverall = $prevAssessed === [] ? null : round(array_sum($prevAssessed) / count($prevAssessed), 2);
            if ($prevOverall !== null) {
                $delta = $overall - $prevOverall;
                $trajectory = $delta > 0.25 ? 'improving' : ($delta < -0.25 ? 'declining' : 'stable');
            }
        }

        return [
            'name' => (string) ($nameByCap[$primary->capability_id] ?? 'Capability'),
            'score' => $overall,
            'of' => 5,
            'kasba' => $kasba,
            'assessedAt' => $latest->assessed_date ?? null,
            'vsTeam' => $overall === null ? null : ['value' => $overall, 'teamAvg' => $teamAvg],
            'vsRole' => $vsRole,
            'trajectory' => $trajectory,
            'unlock' => $overall === null ? 'Schedule a KASBA assessment to start measuring capability.' : null,
        ];
    }

    /**
     * @return array{signals:int, cases:int, decisions:int, executions:int}
     */
    private function loop(string $tenantId, string $personId): array
    {
        $base = ['signals' => 0, 'cases' => 0, 'decisions' => 0, 'executions' => 0];
        if (! Schema::hasTable('hpbrain_signals')) {
            return $base;
        }

        $base['signals'] = (int) DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->where('related_entity_type', 'Person')
            ->where('related_entity_id', $personId)
            ->count();

        $signalIds = DB::table('hpbrain_signals')
            ->where('tenant_id', $tenantId)
            ->where('related_entity_type', 'Person')
            ->where('related_entity_id', $personId)
            ->pluck('id');

        if ($signalIds->isNotEmpty() && Schema::hasTable('hpbrain_cases')) {
            $caseIds = DB::table('hpbrain_cases')
                ->where('tenant_id', $tenantId)
                ->whereIn('signal_id', $signalIds)
                ->pluck('id');

            if (Schema::hasTable('hpbrain_case_signals')) {
                $caseIds = $caseIds->merge(DB::table('hpbrain_case_signals')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('signal_id', $signalIds)
                    ->pluck('case_id'));
            }

            $base['cases'] = $caseIds->filter()->unique()->count();
        }

        if (Schema::hasTable('hpbrain_decisions')) {
            $base['decisions'] = (int) DB::table('hpbrain_decisions')
                ->where('tenant_id', $tenantId)
                ->where('decided_by', $personId)
                ->count();
        }

        if (Schema::hasTable('hpbrain_eso_executions')) {
            $base['executions'] = (int) DB::table('hpbrain_eso_executions')
                ->where('tenant_id', $tenantId)
                ->where('executed_by', $personId)
                ->count();
        }

        return $base;
    }

    /**
     * D5: standing.
     *
     * @param  array<string, mixed>  $contrib
     * @param  array<string, mixed>  $capability
     * @param  array<string, mixed>  $mismatches
     * @param  array<string, mixed>  $person
     * @return array{band:string, score:float|null, deltaSinceRefresh:?float, reason:string}
     */
    private function standing(array $contrib, array $capability, array $mismatches, array $person): array
    {
        $components = $this->scoreComponents($contrib, $capability, $mismatches, $person);
        $measured = array_filter(
            $components,
            fn ($c) => is_array($c) && array_key_exists('valuePct', $c) && $c['valuePct'] !== null
        );
        $weights = config('scoring.person.components', []);

        $totalWeight = 0.0;
        $weightedSum = 0.0;
        $componentPoints = [];
        foreach ($measured as $key => $c) {
            $w = (float) ($weights[$key]['weight'] ?? 1.0);
            $totalWeight += $w;
            $weightedSum += $c['valuePct'] * $w;
            $componentPoints[$key] = ['valuePct' => $c['valuePct'], 'weight' => $w];
        }

        $mismatchPenalty = $this->mismatchPenalty($mismatches['count'] ?? 0);

        if ($totalWeight <= 0) {
            return [
                'band' => 'undetermined',
                'score' => null,
                'deltaSinceRefresh' => null,
                'reason' => 'Nothing this person is measured on is on file yet — see "What we can\'t see yet" below.',
                'components' => $componentPoints,
                'penalty' => null,
            ];
        }

        $score = ($weightedSum / $totalWeight) - $mismatchPenalty;
        $score = max(0.0, min(100.0, round($score, 1)));

        $band = $this->band($score);

        $reason = $this->standingReason($components, $person, $mismatches, $band);

        return [
            'band' => $band,
            'score' => $score,
            'deltaSinceRefresh' => null,
            'reason' => $reason,
            'components' => $componentPoints,
            'penalty' => $mismatchPenalty > 0 ? ['label' => 'Recent mismatch days', 'points' => $mismatchPenalty] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $contrib
     * @param  array<string, mixed>  $capability
     * @param  array<string, mixed>  $mismatches
     * @param  array<string, mixed>  $person
     * @return array<string, array{valuePct:?float, basis:string}>
     */
    private function scoreComponents(array $contrib, array $capability, array $mismatches, array $person): array
    {
        $presencePct = $contrib['presence']['attendancePct'] ?? null;
        $presence = $presencePct === null
            ? ['valuePct' => null, 'basis' => 'unmeasured — no attendance in the last 60 days']
            : ['valuePct' => (float) $presencePct, 'basis' => $presencePct . '% of attendance days present'];

        $weekly = $contrib['weeklyTrend'] ?? [];
        $variance = $this->normalizedVariance($weekly);
        $consistencyPct = $variance === null ? null : round((1.0 - $variance) * 100, 1);
        $consistency = $consistencyPct === null
            ? ['valuePct' => null, 'basis' => 'unmeasured — less than 3 weeks of handled volume']
            : ['valuePct' => $consistencyPct, 'basis' => 'week-to-week variance, 8-week window'];

        $capScore = $capability['score'] ?? null;
        $capPct = $capScore === null ? null : round(($capScore / 5) * 100, 1);
        $capabilityC = $capPct === null
            ? ['valuePct' => null, 'basis' => 'unmeasured — no KASBA assessment']
            : ['valuePct' => $capPct, 'basis' => 'latest KASBA overall / 5'];

        return [
            'presence' => $presence,
            'contribution_consistency' => $consistency,
            'capability' => $capabilityC,
        ];
    }

    /** @param array<int, int> $values */
    private function normalizedVariance(array $values): ?float
    {
        $nonZero = array_values(array_filter($values, fn ($v) => $v > 0));
        if (count($nonZero) < 3) {
            return null;
        }
        $mean = array_sum($values) / count($values);
        if ($mean <= 0) {
            return null;
        }
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += pow($v - $mean, 2);
        }
        $variance /= count($values);
        $stdev = sqrt($variance);
        $cv = $stdev / $mean;
        return max(0.0, min(1.0, $cv));
    }

    private function mismatchPenalty(int $mismatches): float
    {
        $per = (float) config('scoring.person.mismatch.per_day', 1.5);
        $cap = (float) config('scoring.person.mismatch.cap', 12.0);
        return min($cap, $mismatches * $per);
    }

    private function band(float $score): string
    {
        $b = config('scoring.person.bands', []);
        if ($score >= ($b['steady'] ?? 85)) {
            return 'steady';
        }
        if ($score >= ($b['watch'] ?? 70)) {
            return 'watch';
        }
        if ($score >= ($b['support'] ?? 55)) {
            return 'support';
        }
        return 'support';
    }

    private function standingReason(array $components, array $person, array $mismatches, string $band): string
    {
        $presence = $components['presence']['valuePct'] ?? null;
        $consistency = $components['contribution_consistency']['valuePct'] ?? null;
        $capability = $components['capability']['valuePct'] ?? null;

        $bits = [];
        if ($presence !== null) {
            $bits[] = "presence {$presence}%";
        }
        if ($consistency !== null) {
            $bits[] = "weekly consistency {$consistency}%";
        }
        if ($capability !== null) {
            $bits[] = "capability {$capability}%";
        }
        $measured = empty($bits) ? 'nothing measured yet' : implode(', ', $bits);

        if (($mismatches['count'] ?? 0) > 0) {
            $measured .= "; {$mismatches['count']} recent mismatch day(s)";
        }

        $roleNote = ($person['roleAssigned'] ?? false) ? '' : ' (role unassigned — volume not used as ranking)';

        return "{$band} band — {$measured}{$roleNote}.";
    }

    /**
     * D6: confidence.
     *
     * @param  array<string, mixed>  $contrib
     * @param  array<string, mixed>  $capability
     * @param  array<string, mixed>  $loop
     * @param  array<string, mixed>  $person
     * @return array{pct:?float, measurableDimensions:int, totalDimensions:int, undetermined:array<int, string>}
     */
    private function confidence(array $contrib, array $capability, array $loop, array $person): array
    {
        $dims = config('scoring.person.confidence_dimensions', []);
        $undetermined = [];

        $presence = $contrib['presence']['attendancePct'] ?? null;
        $measurable = [
            'presence' => $presence !== null,
            'contribution' => $contrib['handledTotal'] > 0,
            /*
              CONSISTENCY IS MEASURABLE ONLY WHEN THE VARIANCE IS.
              It used to key off handledTotal, which meant a person with plenty
              of handled records but fewer than three active weeks in the window
              was counted as a measured dimension in the confidence ring while
              the standing had already excluded it. The ring must count the same
              dimensions the score does, or the two disagree about what is known.
            */
            'consistency' => $this->normalizedVariance($contrib['weeklyTrend'] ?? []) !== null,
            'capability-level' => $capability['score'] !== null,
            'capability-trajectory' => $capability['trajectory'] !== 'UNDETERMINED' && $capability['score'] !== null,
            'role-relative' => $capability['vsRole'] !== 'UNDETERMINED',
            'loop-involvement' => ($loop['signals'] ?? 0) + ($loop['decisions'] ?? 0) + ($loop['executions'] ?? 0) > 0,
        ];

        $total = count($dims);
        $measured = 0;
        foreach ($dims as $d) {
            if (! empty($measurable[$d])) {
                $measured++;
            } else {
                $undetermined[] = $d;
            }
        }

        $pct = $total > 0 ? round(($measured / $total) * 100, 1) : 0.0;

        return [
            'pct' => $pct,
            'measurableDimensions' => $measured,
            'totalDimensions' => $total,
            'undetermined' => $undetermined,
        ];
    }

    /**
     * D7: since refresh.
     */
    private function sinceRefresh(?array $previous, array $current): array
    {
        if ($previous === null) {
            return [
                'supported' => false,
                'changes' => [],
                'reason' => 'first measured refresh — no earlier score to compare',
            ];
        }

        $changes = [];

        $prevRecords = (int) ($previous['recordCount'] ?? 0);
        $currRecords = (int) ($current['recordCount'] ?? 0);
        $delta = $currRecords - $prevRecords;
        if ($delta !== 0) {
            $changes[] = [
                'label' => 'Records attached',
                'detail' => ($delta > 0 ? '+' : '') . $delta . ' since last refresh',
                'direction' => $delta > 0 ? 'up' : 'down',
            ];
        }

        $prevMismatch = (int) ($previous['mismatchCount'] ?? 0);
        $currMismatch = (int) ($current['mismatchCount'] ?? 0);
        if ($prevMismatch !== $currMismatch) {
            $delta = $currMismatch - $prevMismatch;
            $changes[] = [
                'label' => 'Mismatch days',
                'detail' => ($delta > 0 ? '+' : '') . $delta . ' since last refresh',
                'direction' => $delta > 0 ? 'down' : 'up',
            ];
        }

        $prevHours = (int) ($previous['longHoursWeeks'] ?? 0);
        $currHours = (int) ($current['longHoursWeeks'] ?? 0);
        if ($prevHours !== $currHours) {
            $delta = $currHours - $prevHours;
            $changes[] = [
                'label' => 'Long-hours weeks',
                'detail' => ($delta > 0 ? '+' : '') . $delta . ' since last refresh',
                'direction' => $delta > 0 ? 'down' : 'up',
            ];
        }

        $prevAssessed = $previous['assessedAt'] ?? null;
        $currAssessed = $current['assessedAt'] ?? null;
        if ($prevAssessed !== $currAssessed && $currAssessed !== null) {
            $changes[] = [
                'label' => 'New assessment',
                'detail' => 'latest assessment dated ' . $currAssessed,
                'direction' => 'up',
            ];
        }

        if ($changes === []) {
            $changes[] = ['label' => 'No change', 'detail' => 'standing unchanged since last refresh', 'direction' => 'flat'];
        }

        return [
            'supported' => true,
            'changes' => array_slice($changes, 0, 4),
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $contrib
     * @param  array<string, mixed>  $capability
     * @param  array<string, mixed>  $mismatches
     * @param  array<string, mixed>  $person
     * @return array<int, array<string, mixed>>
     */
    private function blindSpots(array $contrib, array $capability, array $mismatches, array $person, array $loop = []): array
    {
        $spots = [];

        $presence = $contrib['presence']['attendancePct'] ?? null;
        if ($presence === null) {
            $spots[] = [
                'dimension' => 'Presence reliability',
                'reason' => 'No attendance records in the last 60 days.',
                'fixLabel' => 'Import an attendance dataset',
                'fixRoute' => '/settings/integrations',
            ];
        }

        if ($capability['score'] === null) {
            $spots[] = [
                'dimension' => 'Capability level',
                'reason' => 'No KASBA assessment has been recorded for this person.',
                'fixLabel' => 'Schedule an assessment',
                'fixRoute' => '/capabilities',
            ];
        }

        if (! ($person['roleAssigned'] ?? false)) {
            $spots[] = [
                'dimension' => 'Role-relative comparison',
                'reason' => 'No job role is assigned. Until a role is on file, this score cannot be compared to a role requirement.',
                'fixLabel' => 'Assign a role',
                'fixRoute' => '/people/' . ($person['id'] ?? '') . '/role',
            ];
        }

        if ($contrib['handledTotal'] === 0) {
            $spots[] = [
                'dimension' => 'Contribution',
                'reason' => 'No operational records have been attached to this person yet.',
                'fixLabel' => 'Open people → attach records',
                'fixRoute' => '/people/' . ($person['id'] ?? ''),
            ];
        }

        return $spots;
    }

    /**
     * @return array{components:array<int, array<string, mixed>>, penalty:array<string, mixed>|null, total:?float, note:string}
     */
    private function scoreExplain(array $standing, array $contrib, array $capability, array $mismatches, array $person): array
    {
        $components = [];
        $weights = config('scoring.person.components', []);

        $map = [
            'presence' => 'presence',
            'contribution_consistency' => 'contribution',
            'capability' => 'capability',
        ];

        $standingComponents = $standing['components'] ?? [];
        foreach ($standingComponents as $key => $c) {
            $label = $weights[$map[$key] ?? $key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
            $w = (float) ($weights[$map[$key] ?? $key]['weight'] ?? 1.0);
            $points = round(((float) $c['valuePct']) * ($w / array_sum(array_column($weights, 'weight'))), 1);
            $components[] = [
                'label' => $label,
                'valuePct' => (float) $c['valuePct'],
                'weight' => $w,
                'points' => $points,
                'basis' => $this->basisFor($key, $person),
            ];
        }

        return [
            'components' => $components,
            'penalty' => $standing['penalty'] ?? null,
            'total' => $standing['score'],
            'note' => 'Only measured things count. Unmeasurable dimensions are excluded and shown as blind spots, not as zero.',
        ];
    }

    private function basisFor(string $key, array $person): string
    {
        return match ($key) {
            'presence' => '% of attendance days present (last 60 days)',
            'contribution_consistency' => ! ($person['roleAssigned'] ?? false)
                ? '1 − normalized weekly variance (volume not used as ranking while role unassigned)'
                : '1 − normalized weekly variance (8-week window)',
            'capability' => 'latest KASBA overall / 5',
            default => '',
        };
    }

    private function recommendation(array $contrib, array $capability, array $mismatches, array $person, array $standing): array
    {
        $mismatchCount = (int) ($mismatches['count'] ?? 0);
        $longHours = (bool) ($contrib['presence']['longHoursFlag'] ?? false);
        $capMissing = $capability['score'] === null;
        $roleMissing = ! ($person['roleAssigned'] ?? false);

        if ($mismatchCount > 0) {
            return [
                'title' => 'Resolve data-source mismatches',
                'body' => "We found {$mismatchCount} day(s) where check-in and attendance records disagree. These are framed as data-quality issues (likely device sync or import mapping), not as misconduct. Review the affected dates and confirm the canonical attendance source.",
                'confidence' => 0.85,
                'rootCause' => 'DETERMINED',
                'meta' => 'Fix routes: /people/' . ($person['id'] ?? '') . '/records?mismatch=1',
                'createPlanRoute' => '/plans/create?person=' . ($person['id'] ?? '') . '&kind=mismatch_review',
            ];
        }

        if ($longHours) {
            $weeks = (int) ($contrib['presence']['longHoursWeeks'] ?? 0);
            return [
                'title' => 'Worth a workload check',
                'body' => "This person's recent working hours have been above the threshold for {$weeks} consecutive weeks. This is supportive copy, not a performance flag — a workload check can confirm whether capacity is sustainable.",
                'confidence' => 0.7,
                'rootCause' => 'DETERMINED',
                'meta' => 'Source: field_attendance hours column',
                'createPlanRoute' => '/plans/create?person=' . ($person['id'] ?? '') . '&kind=workload_check',
            ];
        }

        if ($capMissing) {
            return [
                'title' => 'Schedule a KASBA assessment',
                'body' => 'No capability has been measured for this person yet. Without an assessment, capability does not contribute to standing, and the standing band stays undetermined.',
                'confidence' => 0.6,
                'rootCause' => 'UNDETERMINED',
                'meta' => 'Unlock: schedule a KASBA assessment',
                'createPlanRoute' => null,
            ];
        }

        if ($roleMissing) {
            return [
                'title' => 'Assign a job role',
                'body' => 'With no role on file, contribution cannot be compared to a role requirement. Assign a role to enable role-relative scoring.',
                'confidence' => 0.6,
                'rootCause' => 'UNDETERMINED',
                'meta' => 'Unlock: assign a job role to this person',
                'createPlanRoute' => null,
            ];
        }

        return [
            'title' => 'Maintain current standing',
            'body' => 'All measured dimensions are in a healthy range. No action required — continue current cadence.',
            'confidence' => 0.5,
            'rootCause' => 'DETERMINED',
            'meta' => null,
            'createPlanRoute' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshotData(array $contrib, array $mismatches, array $capability): array
    {
        return [
            'recordCount' => (int) ($contrib['handledTotal'] ?? 0),
            'mismatchCount' => (int) ($mismatches['count'] ?? 0),
            'longHoursWeeks' => (int) ($contrib['presence']['longHoursWeeks'] ?? 0),
            'assessedAt' => $capability['assessedAt'] ?? null,
        ];
    }

    private function previousSnapshot(string $tenantId, string $personId): ?array
    {
        if (! Schema::hasTable('hpbrain_metric_snapshots')) {
            return null;
        }

        $columns = collect(Schema::getColumnListing('hpbrain_metric_snapshots'));
        $payloadCol = $columns->first(fn ($c) => in_array($c, ['payload', 'value'], true));
        if ($payloadCol === null) {
            return null;
        }

        $row = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('metric_key', 'person_intelligence:' . $personId)
            ->orderByDesc('snapshot_date')
            ->first([$payloadCol]);

        if (! $row) {
            return null;
        }

        $raw = $row->{$payloadCol};
        if ($payloadCol === 'value' && is_numeric($raw)) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeSnapshot(string $tenantId, string $personId, array $snapshot): void
    {
        if (! Schema::hasTable('hpbrain_metric_snapshots')) {
            return;
        }

        $columns = collect(Schema::getColumnListing('hpbrain_metric_snapshots'));
        $payload = json_encode($snapshot);
        $row = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenantId,
            'metric_key' => 'person_intelligence:' . $personId,
            'snapshot_date' => now()->toDateString(),
        ];
        if ($columns->contains('payload')) {
            $row['payload'] = $payload;
        }
        if ($columns->contains('value')) {
            $row['value'] = (float) ($snapshot['recordCount'] ?? 0);
        }
        if ($columns->contains('created_date')) {
            $row['created_date'] = now();
        }
        if ($columns->contains('created_at')) {
            $row['created_at'] = now();
        }
        if ($columns->contains('updated_at')) {
            $row['updated_at'] = now();
        }

        DB::table('hpbrain_metric_snapshots')->insert($row);
    }
}
