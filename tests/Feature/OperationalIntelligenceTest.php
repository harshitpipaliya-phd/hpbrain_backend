<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\IntelligenceLoopMetrics;
use App\Domain\Operations\OperationalIntelligence;
use App\Domain\Operations\OperationalNarrator;
use App\Domain\Operations\OrganizationScorecard;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * The rules that keep derived operational intelligence honest.
 *
 * ═════════════════════════════════════════════════════════════════════════════
 * WHAT THESE PIN, AND WHY EACH ONE IS A REAL FAILURE MODE
 *
 * 1. AN UNMEASURABLE FIGURE IS NULL, NEVER ZERO. This is the whole premise. A
 *    completion rate of 0% and a completion rate that could not be computed
 *    render identically on a dashboard and mean opposite things: the first is a
 *    devastating finding about the business, the second is a statement about
 *    which columns an export happens to carry. Every assertion below that
 *    checks for `null` is guarding against a future change that "helpfully"
 *    defaults it to 0.
 *
 * 2. NOTHING IS FABRICATED. The engine must not create a record, impute a
 *    value, or fill a gap with a plausible number. The counts it publishes are
 *    asserted against the exact rows the fixture inserts.
 *
 * 3. UNRECOGNISED STATUSES LEAVE THE DENOMINATOR. A dataset whose statuses this
 *    engine cannot resolve must not drag the organization's completion rate
 *    down; it must be excluded and reported as excluded.
 *
 * 4. TENANT ISOLATION. Every aggregate is filtered to one organization. A test
 *    that only ever inserts one tenant's rows cannot catch a missing WHERE, so
 *    a second organization with deliberately different data is always present.
 *
 * 5. THE SCORE EXCLUDES WHAT IT CANNOT MEASURE. A dimension with no data leaves
 *    the weighted mean and appears under `unmeasured` with a reason — it never
 *    enters as a zero, which is the arithmetic that invents failing grades for
 *    young organizations.
 */
final class OperationalIntelligenceTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';

    private const OTHER_TENANT = '9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();

        /*
          THE ENGINE CACHES AGAINST A FINGERPRINT OF THE DATA, in the FILE store
          rather than the array store the suite otherwise uses — the same choice
          IntelligenceEngine makes, because the cache has to survive a process.
          Two tests whose fixtures happen to produce the same fingerprint would
          otherwise share an answer. Flushed per test so each one computes.
        */
        Cache::store('file')->flush();
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(string $tenant, string $dataset, array $overrides = []): void
    {
        static $n = 0;
        $n++;

        DB::table('hpbrain_operational_records')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant,
            'org_id' => $tenant,
            'dataset' => $dataset,
            'natural_key' => $dataset.'-'.$n,
            'row_hash' => str_repeat('0', 64),
            'occurred_at' => '2026-06-01 09:00:00',
            'created_date' => '2026-06-01 09:00:00',
            'updated_date' => '2026-06-01 09:00:00',
        ], $overrides));
    }

    /**
     * A dataset large enough to clear the rate floor, with a stated split.
     */
    private function seedWorkload(string $tenant, string $dataset, int $completed, int $open, int $cancelled, ?string $department = null): void
    {
        for ($i = 0; $i < $completed; $i++) {
            $this->record($tenant, $dataset, [
                'status' => 'Completed',
                'department_label' => $department,
                'occurred_at' => '2026-06-01 09:00:00',
                'closed_at' => '2026-06-01 12:00:00',
                'subject_ref' => 'subject-'.($i % 10),
                'category' => 'Category A',
                'zone' => 'Zone North',
            ]);
        }

        for ($i = 0; $i < $open; $i++) {
            $this->record($tenant, $dataset, [
                'status' => 'Not Started',
                'department_label' => $department,
                'subject_ref' => 'subject-'.($i % 10),
                'category' => 'Category B',
                'zone' => 'Zone South',
            ]);
        }

        for ($i = 0; $i < $cancelled; $i++) {
            $this->record($tenant, $dataset, [
                'status' => 'Cancelled',
                'department_label' => $department,
                'subject_ref' => 'subject-cancel-'.$i,
                'category' => 'Category B',
            ]);
        }
    }

    private function engine(): OperationalIntelligence
    {
        return app(OperationalIntelligence::class);
    }

    /* ───────────────────────── nothing is fabricated ───────────────────────── */

    public function test_an_organization_with_no_records_reports_that_rather_than_zeroes(): void
    {
        $result = $this->engine()->forTenant(self::TENANT);

        self::assertFalse($result['available']);
        self::assertNotNull($result['reason'], 'An organization with nothing ingested must be told why, not shown a column of zeroes.');
        self::assertFalse($result['execution']['supported']);
        self::assertSame([], $result['datasets']);
    }

    public function test_counts_are_exactly_the_rows_inserted(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 60, open: 30, cancelled: 10);
        $this->seedWorkload(self::OTHER_TENANT, 'job_order', completed: 500, open: 500, cancelled: 500);

        $result = $this->engine()->forTenant(self::TENANT);

        self::assertSame(100, $result['totals']['records']);
        self::assertSame(1, $result['totals']['datasets']);
        self::assertSame(60, $result['execution']['completed']);
        self::assertSame(30, $result['execution']['open']);
        self::assertSame(10, $result['execution']['cancelled']);
        self::assertSame(0.6, $result['execution']['completionRate']);
    }

    public function test_no_aggregate_crosses_organizations(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 60, open: 30, cancelled: 10);
        $this->seedWorkload(self::OTHER_TENANT, 'complaint', completed: 5, open: 200, cancelled: 0);

        $mine = $this->engine()->forTenant(self::TENANT);
        $theirs = $this->engine()->forTenant(self::OTHER_TENANT);

        self::assertSame(100, $mine['totals']['records']);
        self::assertSame(205, $theirs['totals']['records']);
        self::assertSame(['Job Order'], array_column($mine['datasets'], 'label'));
        self::assertSame(['Complaint'], array_column($theirs['datasets'], 'label'));
        self::assertNotSame(
            $mine['execution']['completionRate'],
            $theirs['execution']['completionRate'],
            'Two organizations with different data must not produce the same rate — which is what a missing tenant filter looks like.',
        );
    }

    /* ─────────────────── unmeasurable is null, never zero ─────────────────── */

    public function test_a_dataset_with_no_status_publishes_null_not_a_zero_rate(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->record(self::TENANT, 'reference_table', ['status' => null]);
        }

        $result = $this->engine()->forTenant(self::TENANT);
        $dataset = $result['datasets'][0];

        self::assertSame(50, $dataset['records']);
        self::assertFalse($dataset['execution']['supported']);
        self::assertNull($dataset['execution']['completionRate']);
        self::assertStringContainsString('no status field', $dataset['execution']['reason']);
        self::assertFalse($result['execution']['supported']);
        self::assertNull($result['execution']['completionRate']);
    }

    public function test_statuses_the_engine_cannot_resolve_leave_the_denominator(): void
    {
        // 40 resolvable, 60 in a vocabulary the engine does not know.
        $this->seedWorkload(self::TENANT, 'job_order', completed: 30, open: 10, cancelled: 0);

        for ($i = 0; $i < 60; $i++) {
            $this->record(self::TENANT, 'job_order', ['status' => 'ST-'.$i]);
        }

        $result = $this->engine()->forTenant(self::TENANT);
        $dataset = $result['datasets'][0];

        self::assertSame(100, $dataset['records']);
        self::assertSame(40, $dataset['execution']['classified']);
        self::assertSame(60, $dataset['execution']['unclassified']);
        self::assertSame(
            0.75,
            $dataset['execution']['completionRate'],
            'The rate is 30/40 over what could be classified — not 30/100, which would report the lookup miss as a delivery failure.',
        );
        self::assertSame(0.4, $dataset['execution']['classifiedShare'], 'The share the rate speaks for must be published beside it.');
    }

    public function test_turnaround_is_unmeasurable_without_a_closing_timestamp(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->record(self::TENANT, 'job_order', ['status' => 'Open', 'closed_at' => null]);
        }

        $result = $this->engine()->forTenant(self::TENANT);

        self::assertFalse($result['responsiveness']['supported']);
        self::assertNull($result['responsiveness']['averageHours']);
        self::assertNotNull($result['responsiveness']['reason']);
    }

    /**
     * A closing timestamp equal to the opening one is a bookkeeping artefact,
     * not a zero-second resolution, and averaging it in drags every turnaround
     * figure towards nothing.
     */
    public function test_records_closed_at_the_instant_they_opened_are_not_measured_as_instant_work(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->record(self::TENANT, 'complaint', [
                'status' => 'Closed',
                'occurred_at' => '2026-06-01 09:00:00',
                'closed_at' => '2026-06-01 09:00:00',
            ]);
        }

        for ($i = 0; $i < 10; $i++) {
            $this->record(self::TENANT, 'complaint', [
                'status' => 'Closed',
                'occurred_at' => '2026-06-01 09:00:00',
                'closed_at' => '2026-06-01 13:00:00',
            ]);
        }

        $result = $this->engine()->forTenant(self::TENANT);

        self::assertSame(10, $result['responsiveness']['measured'], 'Only the rows that genuinely elapsed may be measured.');
        self::assertSame(4.0, $result['responsiveness']['averageHours']);
    }

    public function test_a_dataset_below_the_rate_floor_reports_its_counts_but_no_percentage(): void
    {
        $this->seedWorkload(self::TENANT, 'tiny_lookup', completed: 3, open: 1, cancelled: 0);

        $result = $this->engine()->forTenant(self::TENANT);
        $dataset = $result['datasets'][0];

        self::assertSame(4, $dataset['records']);
        self::assertNull($dataset['execution']['completionRate']);
        self::assertStringContainsString('below the threshold', $dataset['execution']['reason']);
    }

    /* ─────────────────────── department attribution ─────────────────────── */

    public function test_departments_come_from_the_label_the_source_wrote(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 60, open: 20, cancelled: 0, department: 'Field Operations');
        $this->seedWorkload(self::TENANT, 'complaint', completed: 10, open: 40, cancelled: 0, department: 'Help Desk');

        $result = $this->engine()->forTenant(self::TENANT);

        self::assertTrue($result['support']['department']);
        self::assertSame(2, $result['totals']['departmentsWithActivity']);

        $byLabel = array_column($result['departments'], null, 'label');

        self::assertSame(80, $byLabel['Field Operations']['records']);
        self::assertSame(60, $byLabel['Field Operations']['completed']);
        self::assertSame(0.75, $byLabel['Field Operations']['completionRate']);
        self::assertSame(1, $byLabel['Field Operations']['rank'], 'Ranking is by recorded volume.');
        self::assertSame(0.2, $byLabel['Help Desk']['completionRate']);
    }

    public function test_records_with_no_department_do_not_become_a_department(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 60, open: 20, cancelled: 0, department: null);

        $result = $this->engine()->forTenant(self::TENANT);

        self::assertSame([], $result['departments']);
        self::assertFalse($result['support']['department']);
        self::assertNotNull(
            $result['support']['reasons']['department'],
            'A department count of zero must carry the reason, or a reader cannot tell "no units" from "units not recorded".',
        );
    }

    /* ──────────────────────────── recurrence ──────────────────────────── */

    public function test_repeat_activity_counts_subjects_not_records(): void
    {
        // 10 distinct subjects across 60 completed rows, so every one repeats.
        $this->seedWorkload(self::TENANT, 'complaint', completed: 60, open: 0, cancelled: 0);

        $result = $this->engine()->forTenant(self::TENANT);

        self::assertTrue($result['service']['supported']);
        self::assertSame(10, $result['service']['subjects']);
        self::assertSame(10, $result['service']['repeatedSubjects']);
        self::assertSame(1.0, $result['service']['repeatRate']);
    }

    public function test_recurrence_is_unmeasurable_without_a_subject_reference(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->record(self::TENANT, 'job_order', ['status' => 'Open', 'subject_ref' => null]);
        }

        $result = $this->engine()->forTenant(self::TENANT);

        self::assertFalse($result['service']['supported']);
        self::assertNull($result['service']['repeatRate']);
        self::assertNotNull($result['service']['reason']);
    }

    /* ───────────────────────────── scorecard ───────────────────────────── */

    public function test_the_score_excludes_dimensions_it_cannot_measure_rather_than_scoring_them_zero(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 90, open: 10, cancelled: 0, department: 'Field Operations');

        $scorecard = app(OrganizationScorecard::class)->forTenant(self::TENANT);

        self::assertNotNull($scorecard['overall']);
        self::assertGreaterThan(0, $scorecard['measuredDimensions']);

        $unmeasured = array_column($scorecard['unmeasured'], null, 'key');

        self::assertArrayHasKey('capabilityCoverage', $unmeasured, 'This fixture records no capability assessment.');
        self::assertNull($unmeasured['capabilityCoverage']['score']);
        self::assertSame('Not measurable from connected sources.', $unmeasured['capabilityCoverage']['statement']);
        self::assertNotEmpty($unmeasured['capabilityCoverage']['nextStep']);

        foreach ($scorecard['dimensions'] as $dimension) {
            self::assertTrue($dimension['supported']);
            self::assertIsInt($dimension['score']);
            self::assertNotEmpty($dimension['formula'], 'Every scored dimension must publish the arithmetic behind it.');
        }
    }

    /**
     * The whole point of dropping unmeasurable dimensions: adding one that
     * cannot be measured must not move the headline number.
     */
    public function test_an_unmeasurable_dimension_does_not_drag_the_overall_score_down(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 100, open: 0, cancelled: 0, department: 'Field Operations');

        $scorecard = app(OrganizationScorecard::class)->forTenant(self::TENANT);

        self::assertGreaterThanOrEqual(
            70,
            $scorecard['overall'],
            'An organization completing everything it records must not be dragged under by dimensions nobody has connected data for.',
        );
    }

    /* ───────────────────────────── narrative ───────────────────────────── */

    public function test_every_narrative_finding_is_backed_by_a_measured_value(): void
    {
        $this->seedWorkload(self::TENANT, 'complaint', completed: 20, open: 80, cancelled: 0, department: 'Help Desk');

        $ops = $this->engine()->forTenant(self::TENANT);
        $loop = app(IntelligenceLoopMetrics::class)->forTenant(self::TENANT);
        $scorecard = app(OrganizationScorecard::class)->forTenant(self::TENANT);

        $findings = app(OperationalNarrator::class)->narrate($ops, $loop, $scorecard);

        self::assertNotEmpty($findings);

        foreach ($findings as $finding) {
            self::assertNotEmpty($finding['title']);
            self::assertNotEmpty($finding['whatHappened']);
            self::assertContains($finding['severity'], ['high', 'medium', 'low']);
        }

        $titles = implode(' | ', array_column($findings, 'title'));

        /*
          THE MEASURED RATE, IN THE TITLE. 20 completed of 100 classified is 20%,
          and the finding has to say so — a title reading "completion is low"
          would be an opinion this engine is not entitled to hold, and would not
          be checkable against the records screen.

          Written without a trailing zero because that is what the shared
          formatter produces for a round figure; the point of the assertion is
          that the NUMBER is present, not its decimal styling.
        */
        self::assertStringContainsString(
            '20% of classified work',
            $titles,
            'The completion finding must carry the measured rate, not a qualitative adjective.',
        );
    }

    public function test_narrative_says_nothing_when_there_is_nothing_measured(): void
    {
        $ops = $this->engine()->forTenant(self::TENANT);
        $loop = app(IntelligenceLoopMetrics::class)->forTenant(self::TENANT);
        $scorecard = app(OrganizationScorecard::class)->forTenant(self::TENANT);

        $findings = app(OperationalNarrator::class)->narrate($ops, $loop, $scorecard);

        foreach ($findings as $finding) {
            self::assertNotSame('scale', $finding['key'], 'There is no scale to describe when nothing was ingested.');
        }
    }

    /* ──────────────────────────── loop metrics ──────────────────────────── */

    public function test_an_empty_loop_stage_carries_the_reason_it_is_empty(): void
    {
        $loop = app(IntelligenceLoopMetrics::class)->forTenant(self::TENANT);

        $stages = array_column($loop['stages'], null, 'key');

        self::assertSame(0, $stages['decisions']['count']);
        self::assertNotEmpty(
            $stages['decisions']['message'],
            'A stage with no rows must explain itself; a bare 0 reads as a broken product rather than as a loop that has not been walked.',
        );
        self::assertContains($stages['decisions']['state'], ['ready', 'waiting', 'dormant']);
    }

    /* ─────────────────────────────── the API ─────────────────────────────── */

    public function test_the_overview_endpoint_publishes_the_headline_and_its_provenance(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 60, open: 40, cancelled: 0, department: 'Field Operations');

        $response = $this->withHeaders($this->auth())->getJson('/api/v1/operations/'.self::TENANT.'/overview');

        $response->assertOk();
        $response->assertJsonPath('headline.operationalRecords.value', 100);
        $response->assertJsonPath('execution.completionRate', 0.6);
        $response->assertJsonPath('headline.operationalHealth.value', 60);

        $body = $response->json();

        self::assertNotEmpty($body['derivation']['llm']);
        self::assertStringContainsString('No language model', $body['derivation']['llm']);
        self::assertStringContainsString('tenant_id = '.self::TENANT, $body['derivation']['scope']);
    }

    public function test_the_endpoints_answer_for_the_token_tenant_and_never_a_requested_one(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 60, open: 40, cancelled: 0);
        $this->seedWorkload(self::OTHER_TENANT, 'complaint', completed: 1, open: 999, cancelled: 0);

        // A token for TENANT asking for OTHER_TENANT's data must not receive it.
        $response = $this->withHeaders($this->auth(self::TENANT))
            ->getJson('/api/v1/operations/'.self::OTHER_TENANT.'/overview');

        self::assertContains(
            $response->status(),
            [403, 404],
            'A cross-tenant read must be refused outright rather than quietly answered with the caller\'s own data.',
        );
    }

    public function test_the_departments_endpoint_states_each_units_position_relative_to_the_organization(): void
    {
        $this->seedWorkload(self::TENANT, 'job_order', completed: 90, open: 10, cancelled: 0, department: 'Field Operations');
        $this->seedWorkload(self::TENANT, 'complaint', completed: 30, open: 70, cancelled: 0, department: 'Help Desk');

        $response = $this->withHeaders($this->auth())->getJson('/api/v1/operations/'.self::TENANT.'/departments');

        $response->assertOk();

        $departments = array_column($response->json('departments'), null, 'label');

        self::assertTrue($departments['Field Operations']['relative']['supported']);
        self::assertStringContainsString('Above the organization average', $departments['Field Operations']['relative']['statement']);
        self::assertStringContainsString('Below the organization average', $departments['Help Desk']['relative']['statement']);
    }

    /**
     * A MASTER TABLE IS NOT A WORKFLOW, AND ITS STATUS FIELD MUST NOT BECOME ONE.
     *
     * MEASURED ON LIVE DATA. The connected telecom operator holds a 23,895-row
     * `customer` register whose status values resolve cleanly against the
     * workflow vocabulary — and not one of them is terminal, because a customer
     * is never "completed". Folded into the organization's completion rate, that
     * register alone would have contributed twenty-four thousand records at 0%
     * complete and moved the headline figure by tens of points, describing
     * nothing whatsoever about how the organization delivers work.
     *
     * The gate is "has anything here ever finished", which no entity-status
     * column can pass and every real workflow does.
     */
    public function test_a_status_field_with_no_terminal_state_is_not_measured_as_a_workflow(): void
    {
        // A register: every row resolvable, none of it terminal.
        for ($i = 0; $i < 200; $i++) {
            $this->record(self::TENANT, 'customer', ['status' => $i % 2 === 0 ? 'Registered' : 'Pending']);
        }

        // A real workflow beside it, which must be unaffected.
        $this->seedWorkload(self::TENANT, 'job_order', completed: 80, open: 20, cancelled: 0);

        $result = $this->engine()->forTenant(self::TENANT);
        $byDataset = array_column($result['datasets'], null, 'dataset');

        self::assertFalse($byDataset['customer']['execution']['supported']);
        self::assertNull($byDataset['customer']['execution']['completionRate']);
        self::assertStringContainsString('describes what something IS', $byDataset['customer']['execution']['reason']);

        self::assertTrue($byDataset['job_order']['execution']['supported']);
        self::assertSame(
            0.8,
            $result['execution']['completionRate'],
            'The organization rate must come from the workflow alone — 80/100, not 80/300.',
        );
        self::assertSame(['Job Order'], $result['execution']['contributingDatasets']);
    }
}
