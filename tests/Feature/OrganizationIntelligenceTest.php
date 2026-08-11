<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Intelligence\Confidence;
use App\Domain\Intelligence\IntelligenceEngine;
use App\Domain\Intelligence\OrganizationDataProfiler;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The organization intelligence engine.
 *
 * WHAT THESE TESTS ARE FOR. Not "does the endpoint return 200" — the honesty rules.
 * Every assertion below pins a behaviour that would be easy and tempting to break in
 * a way no type checker and no smoke test would catch:
 *
 *   - accuracy over zero outcomes is null, never 0.0
 *   - a confidence component with no input drops out and redistributes its weight
 *   - a loop dimension with no measurable input is null and leaves the composite
 *   - a flat series is reported as flat, and a real trend is not
 *   - severity is reach x consequence, reproducible from the published numbers
 *   - one organization's records never reach another's intelligence
 *
 * THE FIXTURE IS TWO ORGANIZATIONS WITH OPPOSITE SHAPES, because most of these rules
 * only fire on one of them. `rich` has operational records, decisions and a genuine
 * upward trend; `bare` has nothing but signals. A suite built on one tenant would
 * pass with the null-handling deleted.
 *
 * SQLITE, NOT MYSQL. phpunit.xml pins the suite to in-memory SQLite so no test can
 * touch the shared ERP database. That is why SqlDialect exists: without it this whole
 * engine would be untestable, which is the state the pre-existing analytics endpoints
 * are in.
 */
final class OrganizationIntelligenceTest extends TestCase
{
    use BuildsBrainSchema;

    private const RICH = 'tenant-rich';

    private const BARE = 'tenant-bare';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->seedRichTenant();
        $this->seedBareTenant();
    }

    private function auth(string $tenantId, string $role = 'admin'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-'.$tenantId, 'tenantId' => $tenantId, 'role' => $role,
        ])];
    }

    private function engine(): IntelligenceEngine
    {
        return app(IntelligenceEngine::class);
    }

    /* ─────────────────────────── fixtures ─────────────────────────── */

    /**
     * An organization with real operational data, decisions, and no outcomes.
     *
     * The `work_order` dataset is built with a DELIBERATE UPWARD TREND in monthly
     * volume (10, 20, 30 ... over twelve months) so the trend test has something that
     * must be detected, and a `complaint` dataset with FLAT volume so the same test
     * can assert the negative. Getting both from one fixture is the point: a detector
     * that reports everything as rising passes a rising-only test.
     */
    private function seedRichTenant(): void
    {
        // NO ENTITY MAPPING IS SEEDED, deliberately. The profiler discovers datasets
        // from hpbrain_operational_records itself, and EntityResolver fails closed on
        // the ERP entities — so this fixture also exercises the unmapped path, where
        // Organization, Person and unit counts come back `mapped: false` with null
        // counts rather than being read from some other tenant's tables.
        $rows = [];
        $now  = time();

        for ($month = 0; $month < 12; $month++) {
            // Twelve months back to one month back, so nothing lands in the current
            // partial month — which PatternDetector drops as still filling.
            $occurred = date('Y-m-15 09:00:00', strtotime('-'.(12 - $month).' months', $now));

            // Rising: 10 in the first month, 120 in the last.
            $volume = 10 * ($month + 1);

            for ($i = 0; $i < $volume; $i++) {
                $rows[] = [
                    'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::RICH,
                    'dataset' => 'work_order', 'natural_key' => "WO-{$month}-{$i}",
                    'occurred_at' => $occurred,
                    'closed_at' => date('Y-m-17 09:00:00', strtotime($occurred)),
                    'status' => 'Closed',
                    // Three categories, all recurring across every month, so all
                    // three qualify as patterns.
                    'category' => ['New Connection', 'Migration', 'Shifting'][$i % 3],
                    'sub_category' => 'Closed',
                    'zone' => 'Zone '.($i % 4),
                    'area' => 'Ring '.($i % 2),
                    'owner_name' => 'Owner '.($i % 5),
                    'supervisor_name' => 'Supervisor 1',
                    'subject_ref' => 'CUST-'.$i,
                    'metric_value' => 2 + ($i % 5),
                    'metric_unit' => 'days',
                    'row_hash' => hash('sha256', "WO-{$month}-{$i}"),
                    'created_date' => date('Y-m-d H:i:s', $now),
                    'updated_date' => date('Y-m-d H:i:s', $now),
                ];
            }

            // Flat: exactly 40 complaints every month.
            for ($i = 0; $i < 40; $i++) {
                $rows[] = [
                    'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::RICH,
                    'dataset' => 'complaint', 'natural_key' => "C-{$month}-{$i}",
                    'occurred_at' => $occurred,
                    'closed_at' => date('Y-m-16 09:00:00', strtotime($occurred)),
                    'status' => 'closed',
                    'category' => ['No connectivity', 'Speed issue'][$i % 2],
                    // Recorded on only a third of rows, with TWO values where it is
                    // recorded. Both halves matter: a third missing is the
                    // "sometimes recorded" condition the unrecorded-cause risk fires
                    // on, and more than one value is what stops the column being
                    // classified as invariant instead — a single-valued column is a
                    // different finding (no_variance) and the detector is right to
                    // separate them.
                    'sub_category' => $i % 3 === 0 ? ['Fiber Cut', 'Power Issue'][$i % 2] : null,
                    'zone' => 'Zone '.($i % 3),
                    'area' => 'Ring 1',
                    'owner_name' => 'Owner '.($i % 4),
                    'supervisor_name' => 'Supervisor 2',
                    'subject_ref' => 'SUB-'.$i,
                    'metric_value' => 5,
                    'metric_unit' => 'hours',
                    'row_hash' => hash('sha256', "C-{$month}-{$i}"),
                    'created_date' => date('Y-m-d H:i:s', $now),
                    'updated_date' => date('Y-m-d H:i:s', $now),
                ];
            }
        }

        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('hpbrain_operational_records')->insert($chunk);
        }

        // Signals with partial evidence coverage.
        for ($i = 0; $i < 10; $i++) {
            $signalId = 'signal-'.$i;

            DB::table('hpbrain_signals')->insert([
                'id' => $signalId, 'tenant_id' => self::RICH, 'source' => 'test',
                'classification' => 'operational', 'priority' => 'medium', 'severity' => 'low',
                'confidence' => 0.9, 'status' => 'new', 'created_by' => 'test',
                'created_date' => date('Y-m-d H:i:s', $now),
            ]);

            // Eight of ten corroborated, so signalsUncovered is 2.
            if ($i < 8) {
                DB::table('hpbrain_evidence')->insert([
                    'id' => 'evidence-'.$i, 'tenant_id' => self::RICH, 'signal_id' => $signalId,
                    'source' => 'test', 'evidence_type' => 'observation', 'content' => '{}',
                    'provenance' => '{"sourceType":"test"}', 'hash' => hash('sha256', 'evidence-'.$i),
                    // Two distinct confidences, so `discrimination` is measurable and
                    // the asserted-confidence risk does NOT fire.
                    'confidence' => $i % 2 === 0 ? 0.9 : 0.5,
                    'version' => 1, 'status' => 'active', 'created_by' => 'test',
                    'created_date' => date('Y-m-d H:i:s', $now),
                    'observed_date' => date('Y-m-d H:i:s', $now),
                ]);
            }
        }

        // Four recommendations, two of them evidenced; three decisions, no outcomes.
        for ($i = 0; $i < 4; $i++) {
            DB::table('hpbrain_recommendations')->insert([
                'id' => 'rec-'.$i, 'tenant_id' => self::RICH, 'category' => $i < 2 ? 'process' : 'staffing',
                'title' => 'Recommendation '.$i, 'priority' => 'high', 'confidence' => 0.7,
                'status' => 'pending', 'created_by' => 'analyst',
                'created_date' => date('Y-m-d H:i:s', strtotime('-10 days', $now)),
            ]);

            if ($i < 2) {
                DB::table('hpbrain_recommendation_evidence')->insert([
                    'tenant_id' => self::RICH, 'recommendation_id' => 'rec-'.$i,
                    'evidence_id' => 'evidence-'.$i, 'linked_date' => date('Y-m-d H:i:s', $now),
                ]);
            }
        }

        foreach ([['d-0', 'approved'], ['d-1', 'rejected'], ['d-2', 'pending']] as $index => [$id, $status]) {
            DB::table('hpbrain_decisions')->insert([
                'id' => $id, 'tenant_id' => self::RICH, 'recommendation_id' => 'rec-'.$index,
                'decided_by' => 'a-real-person', 'executor_type' => 'human',
                'rationale' => 'Considered and decided.', 'status' => $status, 'confidence' => 0.8,
                'created_date' => date('Y-m-d H:i:s', strtotime('-8 days', $now)),
            ]);
        }
    }

    /** An organization with signals and nothing else — every downstream stage blank. */
    private function seedBareTenant(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'bare-signal-1', 'tenant_id' => self::BARE, 'source' => 'test',
            'classification' => 'operational', 'priority' => 'low', 'severity' => 'low',
            'confidence' => 1.0, 'status' => 'new', 'created_by' => 'test',
            'created_date' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ─────────────────────────── honesty: accuracy ─────────────────────────── */

    /** @test */
    public function accuracy_over_zero_outcomes_is_null_and_never_zero(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);

        $accuracy = $result['decisions']['accuracy'];

        $this->assertFalse($accuracy['measurable']);
        // The regression this whole engine was written against: the previous screen
        // rendered Math.round(accuracy * 100) and published a confident "0%",
        // asserting that every decision the organization ever took was wrong.
        $this->assertNull($accuracy['value'], 'Accuracy must be null, not 0.0, when no outcome exists.');
        $this->assertNotSame(0, $accuracy['value']);
        $this->assertNotSame(0.0, $accuracy['value']);
        $this->assertContains('hpbrain_outcomes', $accuracy['gaps']);
        $this->assertStringContainsString('not zero', $accuracy['why']);
    }

    /** @test */
    public function accuracy_becomes_measurable_once_one_outcome_is_recorded(): void
    {
        $before = $this->engine()->forOrganization(self::RICH, true);
        $this->assertFalse($before['decisions']['accuracy']['measurable']);

        DB::table('hpbrain_outcomes')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::RICH, 'decision_id' => 'd-0',
            'result' => 'success', 'metrics' => '{}', 'kpis' => '{}', 'evidence_ids' => '[]',
            'confidence' => 0.8, 'created_by' => 'test',
            'created_date' => date('Y-m-d H:i:s'),
        ]);

        // Drop the scoped instances first, because OrganizationDataProfiler memoises
        // its loop-table counts for the lifetime of one request — that memo is what
        // keeps the fingerprint from costing twenty round trips per read. A real HTTP
        // request gets a fresh instance; a test doing both writes and reads in one
        // process has to ask for one.
        app()->forgetScopedInstances();

        // The fingerprint the cache is keyed on has to notice. If it does not, the
        // whole "intelligence is live" guarantee is false.
        $after = $this->engine()->forOrganization(self::RICH);

        $this->assertNotSame(
            $before['dataVersion'],
            $after['dataVersion'],
            'Recording an outcome must change the data fingerprint so the cache invalidates.',
        );
        $this->assertTrue($after['decisions']['accuracy']['measurable']);
        $this->assertSame(1.0, $after['decisions']['accuracy']['value']);
    }

    /* ─────────────────────────── honesty: confidence ─────────────────────────── */

    /** @test */
    public function a_confidence_component_with_no_input_redistributes_its_weight(): void
    {
        // Two components at equal weight, one measurable at 0.6.
        $confidence = Confidence::build()
            ->add('measurable', 0.5, 0.6, 'measured')
            ->add('absent', 0.5, null, 'no input exists');

        // 0.6, not 0.3: the missing half is removed from the denominator rather than
        // scored zero, which is the difference between "we looked and it was poor"
        // and "we have never looked".
        $this->assertSame(0.6, $confidence->value());
        $this->assertSame(['absent'], $confidence->unmeasured());
    }

    /** @test */
    public function a_confidence_with_no_measurable_component_is_null(): void
    {
        $confidence = Confidence::build()
            ->add('a', 0.5, null, 'no input')
            ->add('b', 0.5, null, 'no input');

        $this->assertNull($confidence->value());
        $this->assertSame('undetermined', Confidence::band(null));
    }

    /** @test */
    public function confidence_never_exceeds_one_even_if_a_caller_overshoots(): void
    {
        $confidence = Confidence::build()->add('overshoot', 1.0, 1.4, 'caller bug');

        $this->assertSame(1.0, $confidence->value());
    }

    /* ─────────────────────────── honesty: loop state ─────────────────────────── */

    /** @test */
    public function an_unmeasurable_loop_stage_is_null_and_leaves_the_composite(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);
        $state  = $result['state'];

        $learning = collect($state['dimensions'])->firstWhere('key', 'learning');

        $this->assertNotNull($learning);
        $this->assertNull($learning['score'], 'Learning must be undetermined when no outcome exists.');
        $this->assertSame('undetermined', $learning['stage']);
        $this->assertNotNull($learning['blocking']);

        // Its weight is removed from the composite rather than pulling it toward zero.
        $this->assertLessThan(1.0, $state['overall']['weightMeasured']);
        $this->assertGreaterThan(0, $state['overall']['dimensionsUnmeasured']);
        $this->assertStringContainsString('excluded rather than scored zero', $state['overall']['why']);
    }

    /** @test */
    public function an_organization_with_only_signals_reports_most_stages_as_undetermined(): void
    {
        $result = $this->engine()->forOrganization(self::BARE, true);
        $state  = $result['state'];

        $undetermined = collect($state['dimensions'])->whereNull('score')->pluck('key')->all();

        // Capability, decision and learning all have nothing to measure.
        $this->assertContains('capability', $undetermined);
        $this->assertContains('decision', $undetermined);
        $this->assertContains('learning', $undetermined);

        $this->assertSame(0, $result['knowledge']['state']['domains']);
        $this->assertNull($result['knowledge']['state']['meanConfidence']);
    }

    /* ─────────────────────────── knowledge ─────────────────────────── */

    /** @test */
    public function knowledge_domains_are_derived_from_operational_records(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);

        $domains = collect($result['knowledge']['domains']);

        $this->assertCount(2, $domains, 'One domain per ingested dataset.');

        $workOrder = $domains->firstWhere('key', 'dataset:work_order');
        $this->assertNotNull($workOrder);

        // Chosen by column order, not by entropy: `category` names the kind of work,
        // `zone` names where it happened.
        $this->assertSame('category', $workOrder['axis']);
        $this->assertSame(3, $workOrder['patterns'], 'Three categories, each recurring across every month.');
        $this->assertGreaterThan(0, $workOrder['reinforcement']);
        $this->assertNotNull($workOrder['confidence']['value']);
    }

    /** @test */
    public function a_value_that_does_not_recur_across_months_is_not_a_pattern(): void
    {
        // Two categories over four months. "Routine repair" appears in every month and
        // is a pattern. "Storm damage" appears fifty times in ONE month and is not —
        // it is an incident cluster, and volume alone must not promote it.
        //
        // The fixture has to span more than 45 days, because KnowledgeAnalyzer
        // exempts short-lived datasets from the recurrence bar (a genuinely new source
        // could otherwise never have patterns). A single-day fixture would take the
        // exemption and prove nothing.
        $rows = [];

        for ($month = 1; $month <= 4; $month++) {
            $occurred = date('Y-m-10 09:00:00', strtotime("-{$month} months"));

            for ($i = 0; $i < 8; $i++) {
                $rows[] = $this->recurrenceRow("R-{$month}-{$i}", $occurred, 'Routine repair');
            }

            if ($month === 2) {
                for ($i = 0; $i < 50; $i++) {
                    $rows[] = $this->recurrenceRow("S-{$i}", $occurred, 'Storm damage');
                }
            }
        }

        DB::table('hpbrain_operational_records')->insert($rows);

        $result = $this->engine()->forOrganization(self::RICH, true);
        $domain = collect($result['knowledge']['domains'])->firstWhere('key', 'dataset:recurrence');

        $this->assertNotNull($domain);
        $this->assertSame(82, $domain['records']);
        // Chosen by column order, and `category` is where the kind of work lives.
        $this->assertSame('category', $domain['axis']);

        // The whole distinction the pattern definition exists to make.
        $this->assertSame(1, $domain['patterns'], 'Only the value recurring across months is a pattern.');
        $this->assertSame(1, $domain['unsupportedValues'], 'The single-month cluster is counted as unsupported.');

        $values = array_column($domain['patternDetail'], 'value');
        $this->assertSame(['Routine repair'], $values);
        $this->assertNotContains('Storm damage', $values, '50 records in one month is an incident, not knowledge.');
    }

    /**
     * One row of the recurrence fixture.
     *
     * A single zone and area on purpose: leaving them varied would let `zone` qualify
     * as the primary axis if `category` were ever rejected, which would quietly turn
     * this into a test about geography.
     *
     * @return array<string, mixed>
     */
    private function recurrenceRow(string $key, string $occurred, string $category): array
    {
        return [
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::RICH,
            'dataset' => 'recurrence', 'natural_key' => $key, 'occurred_at' => $occurred,
            'closed_at' => $occurred, 'status' => 'closed', 'category' => $category,
            'zone' => 'Zone 1', 'area' => 'Ring 1', 'owner_name' => 'Owner 1',
            'row_hash' => hash('sha256', $key), 'created_date' => date('Y-m-d H:i:s'),
        ];
    }

    /** @test */
    public function a_column_that_is_never_recorded_is_reported_as_a_blind_spot(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);

        $spots = collect($result['knowledge']['blindSpots']);

        // work_order records no `sub_category` variation and complaint leaves it null
        // on two thirds of rows; both are checkable facts about columns.
        $this->assertTrue(
            $spots->contains(fn (array $s): bool => $s['kind'] === 'mostly_unrecorded' && $s['field'] === 'sub_category'),
            'A classifier recorded on a minority of rows must surface as a blind spot.',
        );

        foreach ($spots as $spot) {
            $this->assertArrayHasKey('detail', $spot);
            $this->assertArrayHasKey('records', $spot);
            $this->assertGreaterThan(0, $spot['records']);
        }
    }

    /* ─────────────────────────── movement ─────────────────────────── */

    /** @test */
    public function a_rising_series_is_detected_and_a_flat_one_is_not(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);
        $trends = collect($result['patterns']['trends']);

        $workOrderVolume = $trends->firstWhere('key', 'dataset:work_order:volume');
        $complaintVolume = $trends->firstWhere('key', 'dataset:complaint:volume');

        $this->assertNotNull($workOrderVolume);
        $this->assertNotNull($complaintVolume);

        // The fixture rises 10 -> 120 across twelve months, perfectly linearly.
        $this->assertSame('rising', $workOrderVolume['direction']);
        $this->assertGreaterThanOrEqual(2.0, abs($workOrderVolume['significance']));

        // The fixture holds complaints at exactly 40 a month. A detector that calls
        // this "rising" is manufacturing movement out of nothing.
        $this->assertSame('flat', $complaintVolume['direction']);
    }

    /** @test */
    public function trend_endpoints_come_from_the_fitted_line_not_the_first_and_last_month(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);
        $trend  = collect($result['patterns']['trends'])->firstWhere('key', 'dataset:work_order:volume');

        $this->assertArrayHasKey('fittedFirst', $trend);
        $this->assertArrayHasKey('fittedLast', $trend);
        $this->assertGreaterThan($trend['fittedFirst'], $trend['fittedLast']);
        // A perfect ramp is fully explained by a straight line.
        $this->assertGreaterThan(0.95, $trend['fitQuality']);
    }

    /* ─────────────────────────── risk and gaps ─────────────────────────── */

    /** @test */
    public function severity_is_reproducible_from_the_published_likelihood_and_impact(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);

        $checked = 0;

        foreach ($result['risks']['risks'] as $risk) {
            if ($risk['likelihood'] === null || $risk['impact'] === null || $risk['severitySource'] !== 'computed') {
                continue;
            }

            // The formula is published in the response; a reader must be able to
            // reproduce the number from the two figures beside it.
            $this->assertEqualsWithDelta(
                round($risk['likelihood'] * $risk['impact'] * 5, 2),
                $risk['severity'],
                0.011,
                'severity must equal likelihood x impact x 5 for '.$risk['id'],
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'The fixture must produce at least one scored risk.');
    }

    /** @test */
    public function every_derived_risk_is_marked_unregistered_and_unowned(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);

        $this->assertGreaterThan(0, $result['risks']['derived']);
        $this->assertSame(0, $result['risks']['registered']);

        foreach ($result['risks']['risks'] as $risk) {
            if ($risk['registered']) {
                continue;
            }

            $this->assertNull($risk['owner'], 'A derived risk has nothing written down to own.');
            $this->assertNotSame('', $risk['recommendedAction']);
            $this->assertNotEmpty($risk['evidence'], 'A risk with no evidence must not exist.');
        }
    }

    /** @test */
    public function no_risk_or_recommendation_claims_a_monetary_impact(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);

        foreach ($result['risks']['risks'] as $risk) {
            $this->assertContains($risk['impactKind'], ['magnitude_proxy', 'structural', 'assessed']);
            // This organization records no cost, revenue or penalty data, so a
            // currency figure anywhere here could only have been invented.
            // \p{Sc} with /u, not a literal character class. The first version wrote
            // [$£€] without the unicode modifier, so PCRE matched the LEAD BYTE of the
            // euro sign — which the em dash shares — and every sentence containing an
            // em dash "contained a currency symbol".
            $this->assertDoesNotMatchRegularExpression('/\p{Sc}|\bUSD\b|\bINR\b|\bROI\b/iu', $risk['impactBasis']);
        }

        foreach ($result['recommendations']['recommendations'] as $recommendation) {
            $this->assertContains($recommendation['benefit']['label'], ['Observed', 'Estimated', 'Projected', 'Unknown']);
            $this->assertDoesNotMatchRegularExpression('/\p{Sc}|\bUSD\b|\bINR\b|\bROI\b/iu', $recommendation['benefit']['statement']);
        }
    }

    /** @test */
    public function gap_severity_is_reproducible_from_reach_and_consequence(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);

        $this->assertNotEmpty($result['gaps']['gaps']);

        foreach ($result['gaps']['gaps'] as $gap) {
            $this->assertEqualsWithDelta(
                round($gap['reach'] * $gap['consequence'] * 5, 2),
                $gap['severity'],
                0.011,
                'gap severity must equal reach x consequence x 5 for '.$gap['id'],
            );

            // A gap that cannot say what would close it has not been thought through.
            $this->assertNotSame('', trim($gap['closedWhen']));
            $this->assertNotSame('', trim($gap['whyItMatters']));
        }
    }

    /** @test */
    public function unevidenced_recommendations_are_reported_as_an_invariant_violation(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);

        // The fixture links evidence to two of four recommendations.
        $unevidenced = $result['decisions']['quality']['unevidenced'];
        $this->assertSame(4, $unevidenced['total']);
        $this->assertSame(2, $unevidenced['unevidenced']);

        $gap = collect($result['gaps']['gaps'])->firstWhere('kind', 'unevidenced_conclusions');
        $this->assertNotNull($gap, 'Architecture Invariant 1 violations must surface as a gap.');

        // Half the recommendations are unevidenced, at the maximum consequence weight:
        // 0.5 x 1.0 x 5 = 2.5, which bands as high. Asserting the arithmetic rather
        // than a remembered word is what keeps this test honest if the weights move.
        $this->assertSame(0.5, $gap['reach']);
        $this->assertSame(1.0, $gap['consequence']);
        $this->assertSame(2.5, $gap['severity']);
        $this->assertSame('high', $gap['band']);
    }

    /* ─────────────────────────── recommendations ─────────────────────────── */

    /** @test */
    public function every_recommendation_traces_to_a_finding_with_evidence(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);
        $recommendations = $result['recommendations']['recommendations'];

        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $recommendation) {
            $this->assertContains($recommendation['source'], ['gap', 'risk', 'knowledge']);
            $this->assertNotSame('', trim($recommendation['sourceId']));
            $this->assertNotEmpty($recommendation['evidence'], $recommendation['id'].' has no evidence');
            $this->assertNotSame('', trim($recommendation['nextAction']));

            // Invariant 3: the execution type is always named even though nothing can
            // be bound, and the response says why it cannot.
            $this->assertContains($recommendation['esoType'], ['Assessment', 'Learning', 'Workflow', 'Communication']);
            $this->assertNull($recommendation['esoId']);
            $this->assertNotSame('', trim($recommendation['esoNote']));

            $this->assertNotEmpty($recommendation['provenance']['sources'], $recommendation['id'].' has no provenance');
            $this->assertNotSame('', trim((string) $recommendation['provenance']['computation']));
        }
    }

    /** @test */
    public function recommendations_are_ranked_by_priority_score_descending(): void
    {
        $result = $this->engine()->forOrganization(self::RICH, true);
        $recommendations = $result['recommendations']['recommendations'];

        $previous = PHP_FLOAT_MAX;

        foreach ($recommendations as $index => $recommendation) {
            $this->assertSame($index + 1, $recommendation['rank']);
            $this->assertLessThanOrEqual($previous, $recommendation['priorityScore']);
            $previous = $recommendation['priorityScore'];

            // priorityScore = (severity / 5) x confidence x tractability, published.
            $this->assertEqualsWithDelta(
                round(($recommendation['severity'] / 5) * ($recommendation['confidence']['value'] ?? 0.5) * $recommendation['tractability'], 4),
                $recommendation['priorityScore'],
                0.0002,
                $recommendation['id'],
            );
        }
    }

    /* ─────────────────────────── tenant isolation ─────────────────────────── */

    /** @test */
    public function one_organizations_records_never_reach_anothers_intelligence(): void
    {
        $rich = $this->engine()->forOrganization(self::RICH, true);
        $bare = $this->engine()->forOrganization(self::BARE, true);

        $this->assertGreaterThan(0, $rich['profile']['totals']['operationalRecords']);
        $this->assertSame(0, $bare['profile']['totals']['operationalRecords']);

        $this->assertNotSame($rich['dataVersion'], $bare['dataVersion']);
        $this->assertSame(0, $bare['decisions']['state']['decisions']);
        $this->assertSame(0, count($bare['knowledge']['domains']));

        // Every provenance filter names the tenant it was scoped to. A source with a
        // different tenant in its filter would be a leak that a row count could hide.
        foreach ($rich['knowledge']['domains'] as $domain) {
            foreach ($domain['provenance']['sources'] as $source) {
                if (isset($source['filter']['tenant_id'])) {
                    $this->assertSame(self::RICH, $source['filter']['tenant_id']);
                }
            }
        }
    }

    /** @test */
    public function the_api_refuses_a_token_for_another_organization(): void
    {
        $this->getJson('/api/v1/organization-intelligence/'.self::RICH.'/state', $this->auth(self::RICH))
            ->assertStatus(200);

        // A token for `bare` may not address `rich`, admin or not.
        $this->getJson('/api/v1/organization-intelligence/'.self::RICH.'/state', $this->auth(self::BARE))
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }

    /** @test */
    public function the_api_refuses_an_unauthenticated_request(): void
    {
        $this->getJson('/api/v1/organization-intelligence/'.self::RICH.'/state')
            ->assertStatus(401);
    }

    /* ─────────────────────────── endpoints ─────────────────────────── */

    /** @test */
    public function every_endpoint_returns_its_slice_with_the_data_version(): void
    {
        $endpoints = ['state', 'knowledge', 'decisions', 'risks', 'capability', 'gaps', 'recommendations', 'profile'];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson(
                '/api/v1/organization-intelligence/'.self::RICH.'/'.$endpoint,
                $this->auth(self::RICH),
            )->assertStatus(200);

            $body = $response->json();

            $this->assertSame(self::RICH, $body['tenantId'], $endpoint);
            $this->assertNotEmpty($body['dataVersion'], $endpoint);
            $this->assertArrayHasKey('computedAt', $body, $endpoint);
        }
    }

    /** @test */
    public function the_response_states_that_no_language_model_contributed(): void
    {
        $body = $this->getJson('/api/v1/organization-intelligence/'.self::RICH.'/knowledge', $this->auth(self::RICH))
            ->assertStatus(200)->json();

        $this->assertStringContainsString('No language model', $body['derivation']['llm']);
        $this->assertStringContainsString(self::RICH, $body['derivation']['scope']);
    }

    /* ─────────────────────────── caching ─────────────────────────── */

    /** @test */
    public function the_data_fingerprint_changes_when_the_organizations_records_change(): void
    {
        $profiler = app(OrganizationDataProfiler::class);

        $before = $profiler->dataVersion(self::RICH);

        DB::table('hpbrain_signals')->insert([
            'id' => 'new-signal', 'tenant_id' => self::RICH, 'source' => 'test',
            'classification' => 'operational', 'priority' => 'low', 'severity' => 'low',
            'confidence' => 1.0, 'status' => 'new', 'created_by' => 'test',
            'created_date' => date('Y-m-d H:i:s'),
        ]);

        // A fresh instance, because the profiler memoises loop counts for one request.
        app()->forgetScopedInstances();

        $this->assertNotSame($before, app(OrganizationDataProfiler::class)->dataVersion(self::RICH));
    }

    /** @test */
    public function a_change_to_one_organization_does_not_invalidate_anothers_fingerprint(): void
    {
        $profiler = app(OrganizationDataProfiler::class);
        $bareBefore = $profiler->dataVersion(self::BARE);

        DB::table('hpbrain_signals')->insert([
            'id' => 'rich-only-signal', 'tenant_id' => self::RICH, 'source' => 'test',
            'classification' => 'operational', 'priority' => 'low', 'severity' => 'low',
            'confidence' => 1.0, 'status' => 'new', 'created_by' => 'test',
            'created_date' => date('Y-m-d H:i:s'),
        ]);

        app()->forgetScopedInstances();

        $this->assertSame($bareBefore, app(OrganizationDataProfiler::class)->dataVersion(self::BARE));
    }
}
