<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * The rules that keep the department intelligence screen honest.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHAT THESE PIN, AND WHY EACH ONE IS A DEFECT WAITING TO HAPPEN
 *
 *   NULL IS NOT ZERO. Every measure that cannot be computed comes back null WITH
 *   a sentence. The moment one of them starts returning 0 instead, a unit whose
 *   work is not recorded becomes indistinguishable on screen from a unit with no
 *   open work — and only one of those is good news.
 *
 *   HEALTH EXCLUDES WHAT IT CANNOT MEASURE; CONFIDENCE COUNTS IT. These are two
 *   different numbers and the whole design depends on them never being blended.
 *   A unit with three strong measured dimensions and four unmeasurable ones is a
 *   good unit we know little about.
 *
 *   THE SCORE TABLE ADDS UP. value × weight summed equals the published total.
 *   A "how this is calculated" panel that does not reconcile is worse than none,
 *   because it looks checkable and is not.
 *
 *   OWNER ATTRIBUTION IS A SECOND BASIS, NOT A FALLBACK THAT OVERWRITES THE
 *   FIRST. Where the export states the owning unit, that wins. Where it does
 *   not, work reaches the unit through the person who handled it, and the
 *   dimension says which basis produced its number.
 *
 *   AN AMBIGUOUS NAME BELONGS TO NOBODY. Attribution has to be a function; a
 *   name on two rosters cannot decide which unit a record belongs to, and
 *   splitting it or giving it to the larger unit would both be inventions.
 */
final class DepartmentVerdictTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();
        Cache::store('file')->flush();
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    /** @return array<string, mixed> */
    private function screen(string $department = '2', string $query = ''): array
    {
        return $this->withHeaders($this->auth())
            ->getJson("/api/v1/departments/".self::TENANT."/{$department}/intelligence{$query}")
            ->assertOk()
            ->json();
    }

    /**
     * Work handled by the fixture's Surgery pair (people 2 and 4), with NO
     * `department_label` on any row — the exact shape of Fiber Valley's 16,505
     * job orders, which name no department and belong to one all the same.
     */
    private function seedOwnerAttributedWork(): void
    {
        $rows = [];

        // 40 records: 30 closed, 4 cancelled, 6 open. Above the 30-record floor
        // the scoring engine needs before it will publish a rate at all.
        for ($i = 0; $i < 40; $i++) {
            $status = $i < 30 ? 'Closed' : ($i < 34 ? 'Cancel' : 'Open');

            $rows[] = [
                'id' => 'w'.$i,
                'tenant_id' => self::TENANT,
                'dataset' => 'job_order',
                'natural_key' => 'JOB/'.$i,
                'source_file' => 'surgery_jobs.csv',
                'status' => $status,
                'owner_name' => $i % 2 === 0 ? 'Bilal Khan' : 'Dev Patel',
                // Null on every row: this is attribution by owner, not by label.
                'department_label' => null,
                'occurred_at' => '2026-08-0'.(($i % 5) + 1).' 09:00:00',
                'closed_at' => $status === 'Open' ? null : '2026-08-0'.(($i % 5) + 3).' 09:00:00',
                'subject_ref' => 'SUB-'.($i % 8),
                'row_hash' => hash('sha256', 'w'.$i),
            ];
        }

        DB::table('hpbrain_operational_records')->insert($rows);
    }

    /* ------------------------------------------------------------------ */

    public function test_it_returns_the_whole_screen_for_a_unit_of_this_tenant(): void
    {
        $body = $this->screen();

        foreach ([
            'department', 'health', 'confidence', 'sinceRefresh', 'tiles', 'state',
            'performance', 'workload', 'activity', 'people', 'contribution',
            'capabilities', 'signals', 'flow', 'blindSpots', 'scoreExplain', 'recommendation',
        ] as $section) {
            $this->assertArrayHasKey($section, $body, "the screen must publish its {$section} section");
        }

        $this->assertSame('2', $body['department']['id']);
        $this->assertSame('Surgery', $body['department']['name']);
        $this->assertSame(2, $body['department']['headcount']);
    }

    public function test_a_unit_of_another_tenant_is_a_404_not_an_empty_screen(): void
    {
        // "No such department for you" and "a department with nothing recorded"
        // are different answers, and the second one is a finding.
        $this->withHeaders($this->auth('9'))
            ->getJson('/api/v1/departments/9/2/intelligence')
            ->assertNotFound();
    }

    /**
     * THE CENTRAL RULE, ASSERTED ON THE WIRE.
     */
    public function test_an_unmeasurable_dimension_is_excluded_from_health_and_counted_against_confidence(): void
    {
        $body = $this->screen();

        $excluded = $body['scoreExplain']['excluded'];
        $this->assertNotEmpty($excluded, 'the fixture records no operational work, so dimensions must be excluded');

        // Not one of them is scored — as zero or otherwise.
        foreach ($body['blindSpots'] as $spot) {
            $this->assertFalse($spot['scoredAsZero']);
            $this->assertNotEmpty($spot['reason'], 'a blind spot without a reason is a shrug');
        }

        // Health is the mean of the SURVIVORS. If the excluded dimensions were
        // entering as zeros the score could not exceed the survivors' share.
        $components = $body['scoreExplain']['components'];
        $this->assertNotEmpty($components);

        $this->assertSame(
            count($components),
            $body['confidence']['measurableDimensions'],
            'confidence must count exactly the dimensions health was built from',
        );

        $this->assertLessThan(
            $body['confidence']['totalDimensions'],
            $body['confidence']['measurableDimensions'],
            'this fixture cannot measure everything, so confidence must be below 100%',
        );

        // Confidence fell because of the gap; health did not.
        $this->assertLessThan(100, $body['confidence']['pct']);
    }

    /**
     * A PANEL THE READER CAN CHECK. If this ever stops reconciling, the panel is
     * explaining a formula the server is not using.
     */
    public function test_the_score_table_adds_up_to_the_published_total(): void
    {
        $explain = $this->screen()['scoreExplain'];

        $sum = 0.0;

        foreach ($explain['components'] as $component) {
            // The weight shown is the share of the SURVIVING weight, which is
            // what the engine divides by — printing the raw model weight beside
            // points computed from the re-based one is how this stops adding up.
            $this->assertEqualsWithDelta(
                $component['valuePct'] * $component['weight'],
                $component['points'],
                0.15,
                "{$component['key']}: points must be value × weight",
            );

            $sum += $component['points'];
        }

        $this->assertEqualsWithDelta($explain['total'], $sum, 1.0, 'the components must sum to the total');

        // And the weights published are a share of one, so nothing is missing
        // from the table the total was built from.
        $this->assertEqualsWithDelta(
            1.0,
            array_sum(array_column($explain['components'], 'weight')),
            0.01,
        );
    }

    /**
     * EVERY MEASURE THAT CANNOT BE COMPUTED CARRIES ITS REASON.
     */
    public function test_no_measure_publishes_a_zero_in_place_of_an_absence(): void
    {
        $body = $this->screen();

        foreach ([...$body['performance'], ...$body['workload']] as $measure) {
            if ($measure['value'] === null) {
                $this->assertFalse($measure['measurable']);
                $this->assertNotEmpty($measure['hint'], "{$measure['key']} is unmeasurable and must say why");
            }
        }

        foreach ($body['tiles'] as $tile) {
            if ($tile['value'] === null) {
                $this->assertNotEmpty($tile['reason'], "the {$tile['key']} tile must say why it is empty");
            } else {
                $this->assertNull($tile['reason'], 'a measured tile must not also carry a reason');
            }
        }
    }

    /**
     * SLA IS THE ONE FIGURE THIS SCREEN REFUSES TO PRODUCE.
     *
     * Nothing connected records a resolution target — not a column, not a config,
     * not an import profile. A percentage against a threshold this code invented
     * would be the most convincing false number on the page, because it looks
     * exactly like the real thing.
     */
    public function test_resolution_against_target_is_never_computed_without_a_recorded_target(): void
    {
        $this->seedOwnerAttributedWork();

        $performance = collect($this->screen()['performance'])->keyBy('key');

        $this->assertNull($performance['sla']['value']);
        $this->assertFalse($performance['sla']['measurable']);
        $this->assertStringContainsString('target', $performance['sla']['hint']);

        // Time-to-close IS measured, and is what the panel offers instead.
        $this->assertNotNull($performance['turnaround']['value']);
    }

    /**
     * OWNER ATTRIBUTION MAKES INVISIBLE WORK VISIBLE — under its own name.
     */
    public function test_work_naming_no_department_reaches_the_unit_through_its_people(): void
    {
        $before = $this->screen();
        $this->assertNull(
            collect($before['workload'])->keyBy('key')['backlog']['value'],
            'with no records at all there is no backlog to report',
        );

        Cache::store('file')->flush();
        $this->seedOwnerAttributedWork();

        $after = $this->screen();
        $workload = collect($after['workload'])->keyBy('key');
        $performance = collect($after['performance'])->keyBy('key');

        // 6 open, 30 completed and 4 cancelled, from rows whose department_label
        // is null on every one of them.
        $this->assertSame(6, $workload['backlog']['value']);
        $this->assertEqualsWithDelta(30 / 40, $performance['completion']['value'], 0.001);

        // And the score says which basis produced it, so the two can never be
        // mistaken for each other.
        $operational = collect($after['scoreExplain']['components'])->firstWhere('key', 'operational');
        $this->assertNotNull($operational, 'operational performance is now measurable');
        $this->assertSame('owner', $operational['attribution']);
        $this->assertStringContainsString('Attributed by owner', $operational['basis']);
    }

    /**
     * A LABEL, WHERE THE EXPORT STATES ONE, IS NOT OVERWRITTEN BY OWNERSHIP.
     */
    public function test_the_stated_owning_unit_wins_over_owner_attribution(): void
    {
        $this->seedOwnerAttributedWork();

        // The same people, but this batch says which unit it belongs to — and
        // says a DIFFERENT completion rate, so the two bases are separable.
        $rows = [];

        for ($i = 0; $i < 40; $i++) {
            $rows[] = [
                'id' => 'l'.$i,
                'tenant_id' => self::TENANT,
                'dataset' => 'job_order',
                'natural_key' => 'LAB/'.$i,
                'source_file' => 'surgery_jobs.csv',
                'status' => 'Closed',
                'owner_name' => 'Bilal Khan',
                'department_label' => 'Surgery',
                'occurred_at' => '2026-08-01 09:00:00',
                'closed_at' => '2026-08-02 09:00:00',
                'row_hash' => hash('sha256', 'l'.$i),
            ];
        }

        DB::table('hpbrain_operational_records')->insert($rows);
        Cache::store('file')->flush();

        $operational = collect($this->screen()['scoreExplain']['components'])->firstWhere('key', 'operational');

        $this->assertNotNull($operational);
        $this->assertSame('label', $operational['attribution'], 'a stated owning unit must win');
        $this->assertStringNotContainsString('Attributed by owner', $operational['basis']);
    }

    /**
     * ATTRIBUTION HAS TO BE A FUNCTION.
     */
    public function test_a_name_on_two_rosters_is_attributed_to_neither(): void
    {
        // Person 5 sits in Radiology (3). Give Nursing (1) somebody with the
        // same full name, and the records naming it can no longer decide.
        DB::table('tbluser')->insert([
            'id' => 6, 'sub_institute_id' => self::TENANT, 'employee_no' => 'E6',
            'first_name' => 'Eve', 'last_name' => 'Silva', 'email' => 'eve2@x.test',
            'department_id' => 1, 'user_profile_id' => 1, 'jobtitle_id' => 1, 'status' => 1,
        ]);

        $rows = [];

        for ($i = 0; $i < 40; $i++) {
            $rows[] = [
                'id' => 'x'.$i,
                'tenant_id' => self::TENANT,
                'dataset' => 'job_order',
                'natural_key' => 'AMB/'.$i,
                'source_file' => 'ambiguous.csv',
                'status' => 'Closed',
                'owner_name' => 'Eve Silva',
                'department_label' => null,
                'occurred_at' => '2026-08-01 09:00:00',
                'closed_at' => '2026-08-02 09:00:00',
                'row_hash' => hash('sha256', 'x'.$i),
            ];
        }

        DB::table('hpbrain_operational_records')->insert($rows);
        Cache::store('file')->flush();

        foreach (['1', '3'] as $unit) {
            $workload = collect($this->screen($unit)['workload'])->keyBy('key');

            $this->assertNull(
                $workload['backlog']['value'],
                "unit {$unit} must not claim work whose owner two rosters both name",
            );
        }
    }

    /**
     * MOVEMENT NEEDS TWO MEASUREMENTS, AND SAYS SO UNTIL IT HAS THEM.
     */
    public function test_the_delta_is_absent_rather_than_zero_before_there_is_history(): void
    {
        $body = $this->screen();

        $this->assertFalse($body['sinceRefresh']['supported']);
        $this->assertNull($body['sinceRefresh']['delta']);
        $this->assertNull($body['health']['deltaSinceRefresh']);
        $this->assertNotEmpty($body['sinceRefresh']['reason']);

        // One earlier measurement, deliberately different from today's.
        DB::table('hpbrain_metric_snapshots')->insert([
            'id' => 's-old', 'tenant_id' => self::TENANT, 'snapshot_date' => '2026-08-01',
            'metric_key' => 'department.health', 'dimension_key' => '2', 'value' => 40,
        ]);
        DB::table('hpbrain_metric_snapshots')->insert([
            'id' => 's-new', 'tenant_id' => self::TENANT, 'snapshot_date' => '2026-08-02',
            'metric_key' => 'department.health', 'dimension_key' => '2', 'value' => 50,
        ]);

        $withHistory = $this->screen();

        $this->assertTrue($withHistory['sinceRefresh']['supported']);
        $this->assertSame(40, $withHistory['sinceRefresh']['previousScore']);
        $this->assertSame(
            $withHistory['health']['score'] - 40,
            $withHistory['health']['deltaSinceRefresh'],
            'the delta must be measured against the previous snapshot, not the latest',
        );
    }

    /**
     * THE ROSTER PAGES ON THE SERVER.
     */
    public function test_the_roster_is_paged_by_the_query_not_by_the_browser(): void
    {
        $first = $this->screen('2', '?page=1&pageSize=1')['people'];

        $this->assertSame(2, $first['total']);
        $this->assertSame(1, $first['page']);
        $this->assertSame(2, $first['pages']);
        $this->assertCount(1, $first['items']);
        $this->assertSame(1, $first['from']);
        $this->assertSame(1, $first['to']);

        $second = $this->screen('2', '?page=2&pageSize=1')['people'];

        $this->assertCount(1, $second['items']);
        $this->assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);

        // A page past the end is clamped, not empty: a reader who lands on a
        // stale link sees the last page rather than an empty roster that reads
        // as "nobody works here".
        $this->assertSame(2, $this->screen('2', '?page=9&pageSize=1')['people']['page']);
    }

    /**
     * NO PER-PERSON VERDICT, AND THE REASON TRAVELS WITH THE LIST.
     */
    public function test_a_person_no_import_names_is_unmeasured_rather_than_zero(): void
    {
        $people = $this->screen()['people'];

        $this->assertNotEmpty($people['verdictNote']);

        foreach ($people['items'] as $person) {
            $this->assertFalse($person['linked'], 'the fixture has no operational records');
            $this->assertNull($person['handled'], 'unmeasured work must be null, never 0');
            $this->assertNotEmpty($person['reason']);
        }
    }

    /**
     * A ROOT CAUSE IS ONLY DETERMINED WHERE SOMETHING RECORDED ESTABLISHES IT.
     */
    public function test_the_recommendation_publishes_an_undetermined_root_cause_with_what_is_missing(): void
    {
        $recommendation = $this->screen()['recommendation'];

        $this->assertSame('UNDETERMINED', $recommendation['rootCause']);
        $this->assertNotEmpty($recommendation['rootCauseMissing']);

        $gate = $recommendation['sufficiencyGate'];
        $this->assertLessThan($gate['total'], $gate['answered']);
        $this->assertCount($gate['total'], $gate['questions']);

        // Cross-unit flow is unrecorded on every connected source, so the one
        // question that would settle the cause is always unanswered.
        $blocked = collect($gate['questions'])->firstWhere('question', 'Who or what is holding it up?');
        $this->assertNotNull($blocked);
        $this->assertFalse($blocked['answered']);

        $this->assertFalse($this->screen()['flow']['supported']);
    }
}
