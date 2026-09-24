<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use App\Domain\Organization\DepartmentProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * The rules that keep a department score honest.
 *
 * THE DEFECT THESE PIN, measured on Fiber Valley before the fix: every
 * department with no people scored exactly 50 / 100 and was labelled "Watch",
 * and the largest department scored exactly 100 / 100 "Excellent". Neither
 * number was random and neither was meaningful.
 *
 *   50  came from averaging two components at opposite extremes — staffing
 *       scored 0 because nobody is assigned, risk scored 100 because no open
 *       signal references a unit nobody works in — and publishing the mean of
 *       "nothing is here" and "nothing is wrong here" as a health grade.
 *
 *   100 came from scoring headcount against the median unit, so a department
 *       1.7x the median hit the clamp. Being large was being graded Excellent.
 *
 * The fix is a rule, not a constant: a dimension with no input LEAVES the
 * composite instead of entering it as a zero, and a composite with too little
 * left is not published at all. These tests fail if either rule is relaxed —
 * including via the `support` flags, which are what let the client tell "this
 * organization measures nothing of the kind" apart from "this department has
 * none of it".
 */
final class DepartmentIntelligenceMetricsTest extends TestCase
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
    }

    private function auth(string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    private function metrics(string $tenant = self::TENANT): array
    {
        return $this->withHeaders($this->auth($tenant))
            ->getJson("/api/v1/departments/{$tenant}/intelligence")
            ->assertOk()
            ->json();
    }

    /* ------------------------------------------------------------------ */

    public function test_it_reports_every_visible_department_including_the_unstaffed_ones(): void
    {
        $body = $this->metrics();

        // The fixture's three units: Nursing (1), Surgery (2), Radiology (3).
        // Integer keys, not strings: PHP coerces a numeric array key on decode.
        // The wire format is a JSON object — pinned by the controller's cast.
        $this->assertSame([1, 2, 3], array_keys($body['departments']));

        // Headcounts come from FoundationCounts, so they are the same numbers
        // the Organization overview publishes rather than a second count.
        $this->assertSame(1, $body['departments'][1]['people']);
        $this->assertSame(2, $body['departments'][2]['people']);
        $this->assertSame(1, $body['departments'][3]['people']);
    }

    /**
     * A PROBE THAT CANNOT RUN IS NULL, NOT ZERO.
     *
     * MEASURED ON LIVE DATA. Fiber Valley's roster maps no `position` field, so
     * the role probe never executes. The first version published
     * `peopleWithRole: 0` for all five of its departments, and the client
     * averaged that into record completeness — marking every department down for
     * a column the source system does not have. That is the same "absence scored
     * as failure" defect this class exists to prevent, one layer up.
     */
    public function test_a_completeness_probe_the_roster_cannot_answer_is_null_not_zero(): void
    {
        $body = $this->metrics();

        // The fixture roster maps email, employee_no and jobtitle_id, so all
        // three probes run and report real counts.
        $this->assertSame(['withRole', 'withContact', 'withReference'], $body['support']['completenessProbes']);
        $this->assertNotNull($body['departments'][2]['peopleWithContact']);

        // Person 5 has an empty email — '' is not a populated field.
        $this->assertSame(1, $body['departments'][3]['people']);
        $this->assertSame(0, $body['departments'][3]['peopleWithContact']);
    }

    /**
     * THE CENTRAL RULE. An organization that has never recorded a capability
     * assessment must not be told its departments score zero on capability.
     */
    public function test_support_is_false_for_every_family_of_data_the_organization_does_not_record(): void
    {
        $support = $this->metrics()['support'];

        foreach (['capability', 'signals', 'evidence', 'cases', 'decisions', 'activity'] as $family) {
            $this->assertFalse($support[$family], "{$family} must report no support on a tenant that records none of it");
        }
    }

    public function test_capability_support_turns_on_only_once_an_assessment_exists(): void
    {
        DB::table('hpbrain_capability_assignments')->insert([
            'id' => 'a1', 'tenant_id' => self::TENANT, 'capability_id' => 'c1',
            'target_type' => 'Person', 'target_id' => '1', 'assigned_by' => 'test',
        ]);

        // An assignment with no proficiency is an EXPECTATION, not an
        // assessment: support is on, but nothing is assessed yet.
        $body = $this->metrics();
        $this->assertTrue($body['support']['capability']);
        $this->assertSame(0, $body['departments'][1]['capabilityAssessedPeople']);
        $this->assertNull($body['departments'][1]['capabilityAverageLevel']);

        DB::table('hpbrain_capability_proficiency')->insert([
            'id' => 'p1', 'tenant_id' => self::TENANT, 'assignment_id' => 'a1',
            'knowledge_level' => 4, 'ability_level' => 2,
            'skill_level' => null, 'behaviour_level' => null, 'attitude_level' => null,
            'assessed_by' => 'test', 'assessed_date' => '2026-08-01 00:00:00',
        ]);

        $fresh = $this->metrics();

        // (4 + 2) / 2 = 3.0 — the three UNRATED dimensions are not averaged in
        // as zeros, which would have produced 1.2 and understated the person by
        // more than half.
        $this->assertSame(1, $fresh['departments'][1]['capabilityAssessedPeople']);
        $this->assertEqualsWithDelta(3.0, $fresh['departments'][1]['capabilityAverageLevel'], 0.001);
    }

    public function test_signals_evidence_and_cases_reach_the_department_through_the_signal(): void
    {
        DB::table('hpbrain_signals')->insert([
            ['id' => 's1', 'tenant_id' => self::TENANT, 'org_id' => self::TENANT, 'source' => 'test',
             'department_id' => '2', 'status' => 'new', 'severity' => 'high', 'created_by' => 'test'],
            ['id' => 's2', 'tenant_id' => self::TENANT, 'org_id' => self::TENANT, 'source' => 'test',
             'department_id' => '2', 'status' => 'resolved', 'severity' => 'low', 'created_by' => 'test'],
            // Attributed to no department: counted for the tenant, against no unit.
            ['id' => 's3', 'tenant_id' => self::TENANT, 'org_id' => self::TENANT, 'source' => 'test',
             'department_id' => null, 'status' => 'new', 'severity' => 'low', 'created_by' => 'test'],
        ]);

        DB::table('hpbrain_evidence')->insert([
            ['id' => 'e1', 'tenant_id' => self::TENANT, 'signal_id' => 's1', 'source' => 'test',
             'evidence_type' => 'metric', 'content' => '{}', 'provenance' => '{}',
             'hash' => 'h1', 'created_by' => 'test'],
            ['id' => 'e2', 'tenant_id' => self::TENANT, 'signal_id' => 's3', 'source' => 'test',
             'evidence_type' => 'metric', 'content' => '{}', 'provenance' => '{}',
             'hash' => 'h2', 'created_by' => 'test'],
        ]);

        DB::table('hpbrain_cases')->insert([
            ['id' => 'k1', 'tenant_id' => self::TENANT, 'signal_id' => 's1',
             'title' => 'Open case', 'status' => 'open', 'created_by' => 'test'],
            ['id' => 'k2', 'tenant_id' => self::TENANT, 'signal_id' => 's1',
             'title' => 'Done case', 'status' => 'closed', 'created_by' => 'test'],
        ]);

        $body = $this->metrics();
        $surgery = $body['departments'][2];

        $this->assertTrue($body['support']['signals']);
        $this->assertSame(2, $surgery['signalsTotal']);
        $this->assertSame(1, $surgery['signalsOpen']);
        $this->assertSame(1, $surgery['signalsOpenHigh']);
        $this->assertSame(1, $surgery['signalsResolved']);

        // Only the evidence whose signal names a department reaches one.
        $this->assertSame(1, $surgery['evidenceCount']);
        $this->assertSame(2, $body['tenant']['evidenceTotal']);

        $this->assertSame(2, $surgery['casesTotal']);
        $this->assertSame(1, $surgery['casesOpen']);

        // A department the signals never mention stays at zero rather than
        // inheriting the tenant's totals.
        $this->assertSame(0, $body['departments'][1]['signalsTotal']);
        $this->assertSame(0, $body['departments'][1]['evidenceCount']);
    }

    public function test_an_undecided_decision_is_not_counted_as_evidence_of_decision_quality(): void
    {
        DB::table('hpbrain_decisions')->insert([
            ['id' => 'd1', 'tenant_id' => self::TENANT, 'decided_by' => '2',
             'rationale' => 'test', 'status' => 'approved'],
            ['id' => 'd2', 'tenant_id' => self::TENANT, 'decided_by' => '2',
             'rationale' => 'test', 'status' => 'rejected'],
            ['id' => 'd3', 'tenant_id' => self::TENANT, 'decided_by' => '2',
             'rationale' => 'test', 'status' => 'proposed'],
        ]);

        $surgery = $this->metrics()['departments'][2];

        $this->assertSame(3, $surgery['decisionCount']);
        $this->assertSame(1, $surgery['decisionsApproved']);
        // Two decided, not three: a proposal still in flight is not evidence
        // either way, so an approval rate must be taken over decided ones.
        $this->assertSame(2, $surgery['decisionsWithOutcome']);
    }

    /**
     * TENANT ISOLATION. The path segment is decoration — scope comes from the
     * token — so asking for another organization's metrics returns your own.
     */
    public function test_it_never_returns_another_organizations_departments(): void
    {
        DB::table('hrms_departments')->insert([
            'id' => 900, 'sub_institute_id' => self::OTHER_TENANT, 'department' => 'Other Org Unit',
            'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
        ]);

        DB::table('hpbrain_signals')->insert([
            'id' => 'other-signal', 'tenant_id' => self::OTHER_TENANT, 'org_id' => self::OTHER_TENANT,
            'source' => 'test', 'department_id' => '900', 'status' => 'new',
            'severity' => 'critical', 'created_by' => 'test',
        ]);

        $body = $this->metrics();
        $this->assertArrayNotHasKey(900, $body['departments']);

        // Editing the tenant in the URL is refused outright rather than
        // quietly answered with your own organization — a stronger guarantee
        // than the scoping the service does for itself.
        $this->withHeaders($this->auth(self::TENANT))
            ->getJson('/api/v1/departments/'.self::OTHER_TENANT.'/intelligence')
            ->assertForbidden();
    }

    /**
     * PERFORMANCE, PINNED. The screen this replaces issued one twin request per
     * department; the point of the endpoint is that its cost does not grow with
     * the number of departments. Adding units must not add queries.
     */
    public function test_its_query_count_does_not_grow_with_the_number_of_departments(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withHeaders($this->auth())->getJson('/api/v1/departments/'.self::TENANT.'/intelligence')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        /*
          ONE THROWAWAY REQUEST FIRST, and the reason is measured rather than
          assumed. Laravel introspects the schema lazily and caches the result
          on the connection, so the first request of a test pays 63 queries and
          every one after it pays 21. Comparing request #1 with request #2 would
          have compared a cold cache with a warm one and reported a 3x
          regression that does not exist. Both measurements below are warm.
        */
        $count();

        $withThree = $count();

        $rows = [];
        for ($i = 300; $i < 320; $i++) {
            // created_at is required: DepartmentVisibilityScope hides units
            // recorded before the tenant's current cohort, and a NULL date is
            // never >= that cutoff.
            $rows[] = ['id' => $i, 'sub_institute_id' => self::TENANT, 'department' => "Unit {$i}",
                       'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
                       'created_by' => 1, 'created_at' => '2026-01-01 00:00:00',
                       'updated_at' => '2026-01-01 00:00:00'];
        }
        DB::table('hrms_departments')->insert($rows);

        $withTwentyThree = $count();

        $this->assertCount(23, $this->metrics()['departments']);
        $this->assertSame(
            $withThree,
            $withTwentyThree,
            'Adding 20 departments changed the query count, so the aggregation is per-department again.'
        );
    }

    /**
     * The roster endpoint must page in SQL. The Department page renders ten
     * people; it must not receive the organization's whole workforce to do it.
     */
    public function test_the_people_endpoint_pages_and_filters_on_the_server(): void
    {
        $all = $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::TENANT)
            ->assertOk()
            ->json();

        // No query string: the historical bare array, so existing callers are
        // untouched.
        $this->assertIsArray($all);
        $this->assertArrayNotHasKey('people', $all);

        $page = $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::TENANT.'?unitId=2&page=1&perPage=1')
            ->assertOk()
            ->json();

        // Surgery holds two active people; page one carries exactly one of them.
        $this->assertSame(2, $page['total']);
        $this->assertSame(2, $page['pages']);
        $this->assertCount(1, $page['people']);

        $second = $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::TENANT.'?unitId=2&page=2&perPage=1')
            ->assertOk()
            ->json();

        $this->assertCount(1, $second['people']);
        $this->assertNotSame($page['people'][0]['id'], $second['people'][0]['id']);

        // A page past the end clamps rather than returning an empty list with a
        // page number that never existed.
        $clamped = $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::TENANT.'?unitId=2&page=99&perPage=1')
            ->assertOk()
            ->json();

        $this->assertSame(2, $clamped['page']);
    }

    public function test_people_search_is_applied_in_sql_alongside_the_unit_filter(): void
    {
        $found = $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::TENANT.'?unitId=2&q=Bilal&page=1&perPage=20')
            ->assertOk()
            ->json();

        $this->assertSame(1, $found['total']);
        $this->assertSame('Bilal', $found['people'][0]['firstName']);

        // Someone real, but in another unit — the two filters are ANDed.
        $missing = $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::TENANT.'?unitId=3&q=Bilal&page=1&perPage=20')
            ->assertOk()
            ->json();

        $this->assertSame(0, $missing['total']);
    }

    /* ─────────────────── operational work reaches the unit ─────────────────── */

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function operationalRecord(string $tenant, string $dataset, array $overrides = []): void
    {
        static $n = 0;
        $n++;

        DB::table('hpbrain_operational_records')->insert(array_merge([
            'id' => 'op-'.$tenant.'-'.$n,
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

    private function seedUnitWorkload(string $tenant, string $label, int $completed, int $open): void
    {
        for ($i = 0; $i < $completed; $i++) {
            $this->operationalRecord($tenant, 'ward_round', ['status' => 'Completed', 'department_label' => $label]);
        }

        for ($i = 0; $i < $open; $i++) {
            $this->operationalRecord($tenant, 'ward_round', ['status' => 'Not Started', 'department_label' => $label]);
        }
    }

    /**
     * THE ONE PIECE OF NAME MATCHING IN THIS CLASS, pinned.
     *
     * The imported records name their owning unit as a string ("Nursing"); the
     * register keys units by id. Reconciling the two is the only inference this
     * class makes, and it is deliberately exact-on-the-normalised-whole-name.
     */
    public function test_operational_work_reaches_the_unit_the_source_named(): void
    {
        Cache::store('file')->flush();

        $this->seedUnitWorkload(self::TENANT, 'Nursing', completed: 80, open: 20);
        $this->seedUnitWorkload(self::TENANT, 'Surgery', completed: 10, open: 40);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/intelligence')
            ->assertOk()
            ->json();

        $this->assertTrue($body['support']['operational']);

        // Nursing is id 1, Surgery id 2 in the fixture.
        $this->assertSame(100, $body['departments']['1']['operationalRecords']);
        $this->assertSame(80, $body['departments']['1']['operationalCompleted']);
        $this->assertSame(0.8, $body['departments']['1']['operationalCompletionRate']);

        $this->assertSame(50, $body['departments']['2']['operationalRecords']);
        $this->assertSame(0.2, $body['departments']['2']['operationalCompletionRate']);

        // Radiology (id 3) is named by no record. That is a real zero about the
        // unit, not a missing measurement about the organization.
        $this->assertSame(0, $body['departments']['3']['operationalRecords'] ?? 0);
    }

    /**
     * ONLY MEASURABLE DIMENSIONS PARTICIPATE IN THE SCORE.
     *
     * The defect this replaces: a dimension the organization does not record was
     * averaged in as a zero, so a unit with a complete roster and no capability
     * module published a low grade that described the SOFTWARE rather than the
     * department. An unrecorded dimension must leave the mean entirely.
     */
    public function test_the_profile_scores_only_what_can_be_measured(): void
    {
        Cache::store('file')->flush();

        $this->seedUnitWorkload(self::TENANT, 'Nursing', completed: 90, open: 10);

        $profile = app(DepartmentProfile::class)->forDepartment(self::TENANT, '1');

        self::assertNotNull($profile);
        self::assertNotNull($profile['score']);
        self::assertSame(7, $profile['dimensionCount']);
        self::assertLessThan(7, $profile['measuredCount']);

        $measured = array_values(array_filter($profile['dimensions'], static fn ($d) => $d['score'] !== null));
        self::assertCount($profile['measuredCount'], $measured);

        // The composite is the weighted mean of the survivors, and nothing else.
        $weight = array_sum(array_column($measured, 'weight'));
        $sum = array_sum(array_map(static fn ($d) => $d['weight'] * $d['score'], $measured));
        self::assertSame((int) round($sum / $weight), $profile['score']);

        // Every dimension that could not be measured says so and carries no zero.
        foreach ($profile['dimensions'] as $d) {
            if ($d['score'] === null) {
                self::assertNotSame('', $d['basis']);
            }
        }
    }

    /**
     * A UNIT THAT IS NOT THIS TENANT'S IS A 404, NOT AN EMPTY PROFILE.
     *
     * "No such department for you" and "a department with nothing recorded" are
     * different answers, and a blank profile would make the second look like the
     * first to anyone probing ids.
     */
    public function test_the_profile_refuses_a_department_outside_the_tenant(): void
    {
        Cache::store('file')->flush();

        DB::table('hrms_departments')->insert([
            'id' => 980, 'sub_institute_id' => self::OTHER_TENANT, 'department' => 'Other Org Unit',
            'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
        ]);

        self::assertNull(app(DepartmentProfile::class)->forDepartment(self::TENANT, '980'));

        $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/980/profile')
            ->assertNotFound();
    }

    /**
     * THE PROFILE RANKS AGAINST PEERS, AND THE RANK IS REAL.
     *
     * Ranking is why this is composed on the server: a rank needs every peer's
     * metrics, which no single department's page could hold. Pinned because the
     * first implementation compared a string id against integer array keys and
     * silently reported "unranked" for every unit on the register.
     */
    public function test_the_profile_ranks_the_unit_against_its_peers(): void
    {
        Cache::store('file')->flush();

        $this->seedUnitWorkload(self::TENANT, 'Nursing', completed: 90, open: 10);

        $profile = app(DepartmentProfile::class)->forDepartment(self::TENANT, '1');

        self::assertNotNull($profile['position']['score']['rank']);
        self::assertGreaterThan(0, $profile['position']['score']['of']);
        self::assertLessThanOrEqual($profile['position']['score']['of'], $profile['position']['score']['rank']);

        // And the narrative is generated, not canned.
        self::assertNotEmpty($profile['narrative']);
        self::assertNotSame('', $profile['nextAction']['title']);
    }


    /**
     * THE SPLIT REGISTER IS REPORTED, NEVER MERGED.
     *
     * This ERP carries two rows for one real unit — the workforce on
     * "CST - FVCPL", the imported work booked against "CST" — so a department
     * with 111 people shows no work at all and its sibling shows work and
     * nobody. The pairing is published as an observation on the staffed row so
     * the reader can see the cause, and attribution is left exactly as the
     * source states it.
     */
    public function test_a_sibling_unit_carrying_the_work_is_named_but_not_absorbed(): void
    {
        Cache::store('file')->flush();

        DB::table('hrms_departments')->insert([
            'id' => 950, 'sub_institute_id' => self::TENANT, 'department' => 'Nursing - FVCPL',
            'parent_id' => 0, 'status' => 1, 'is_calculated' => 0,
            'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        // The work names "Nursing" — the row that has no people.
        $this->seedUnitWorkload(self::TENANT, 'Nursing', completed: 40, open: 10);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/intelligence')
            ->assertOk()
            ->json();

        // NOT MERGED: the records stay on the unit the source named.
        self::assertSame(50, $body['departments']['1']['operationalRecords']);
        self::assertNull($body['departments']['950']['operationalRecords']);

        // REPORTED: the staffed row names the sibling holding its work.
        $unclaimed = $body['departments']['950']['unclaimedWork'];
        self::assertNotNull($unclaimed);
        self::assertSame('Nursing', $unclaimed['label']);
        self::assertSame(50, $unclaimed['records']);
        self::assertSame(40, $unclaimed['completed']);

        // The unit that HAS the work is not told its work lives elsewhere.
        self::assertNull($body['departments']['1']['unclaimedWork']);
    }


    /**
     * A LABEL THAT MATCHES NO REGISTERED UNIT IS NOT PUSHED ONTO THE NEAREST ONE.
     *
     * The register holds "Nursing" and "Surgery"; a source that books work
     * against "Nursing Annexe" is naming a unit this organization has not
     * registered. Attributing it to Nursing because the name starts the same way
     * would be a guess presented as a fact, and no reader could audit it.
     */
    public function test_an_unregistered_unit_label_is_reported_not_absorbed(): void
    {
        Cache::store('file')->flush();

        $this->seedUnitWorkload(self::TENANT, 'Nursing', completed: 60, open: 0);
        $this->seedUnitWorkload(self::TENANT, 'Nursing Annexe', completed: 40, open: 0);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/intelligence')
            ->assertOk()
            ->json();

        $this->assertSame(60, $body['departments']['1']['operationalRecords']);
        $this->assertSame(100, $body['tenant']['operationalRecords']);
        $this->assertSame(60, $body['tenant']['operationalAttributed']);
        $this->assertSame(40, $body['tenant']['operationalUnattributed']);
    }

    /**
     * SUPPORT IS FALSE, AND EVERY FIGURE IS NULL, when the organization's imports
     * name no unit — never 0, which would mark every department down for a
     * column the source system does not carry.
     */
    public function test_operational_metrics_are_null_when_no_record_names_a_unit(): void
    {
        Cache::store('file')->flush();

        for ($i = 0; $i < 50; $i++) {
            $this->operationalRecord(self::TENANT, 'ward_round', ['status' => 'Completed', 'department_label' => null]);
        }

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::TENANT.'/intelligence')
            ->assertOk()
            ->json();

        $this->assertFalse($body['support']['operational']);
        $this->assertNotNull($body['tenant']['operationalReason']);
        $this->assertNull($body['departments']['1']['operationalRecords']);
        $this->assertNull($body['departments']['1']['operationalCompletionRate']);
    }
}
