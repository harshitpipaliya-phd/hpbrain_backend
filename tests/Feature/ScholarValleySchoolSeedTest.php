<?php

namespace Tests\Feature;

use App\Domain\School\FeeIntelligenceService;
use App\Domain\Operations\OrganizationScorecard;
use App\Domain\School\StudentProjectionBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScholarValleySchoolSeedTest extends TestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'hp_erp',
        ]);
        DB::purge('mysql');

        $setup = DB::connection('mysql')->table('school_setup')
            ->where('SchoolName', 'Scholar Valley International School')
            ->first();

        $this->assertNotNull($setup, 'Scholar Valley International School must exist in school_setup');
        $this->tenantId = (string) $setup->id;
    }

    public function test_tenant_identity_and_erp_staff_structure(): void
    {
        // 1. Tenant & School Setup
        $this->assertDatabaseHas('school_setup', [
            'id' => $this->tenantId,
            'SchoolName' => 'Scholar Valley International School',
        ]);

        // 2. Departments (8 school departments)
        $deptCount = DB::table('hrms_departments')
            ->where('sub_institute_id', $this->tenantId)
            ->count();
        $this->assertSame(8, $deptCount, 'Should have exactly 8 school departments');

        // 3. Staff Members (12 staff users)
        $staffCount = DB::table('tbluser')
            ->where('sub_institute_id', $this->tenantId)
            ->whereNull('deleted_at')
            ->count();
        $this->assertGreaterThanOrEqual(12, $staffCount, 'Should have at least 12 staff members');

        // 4. Principal user
        $this->assertDatabaseHas('tbluser', [
            'sub_institute_id' => $this->tenantId,
            'email' => 'evelyn.vance@scholarvalley.edu',
            'first_name' => 'Evelyn',
            'last_name' => 'Vance',
            'is_admin' => 1,
        ]);
    }

    public function test_strict_single_academic_year_boundary(): void
    {
        // Check that NO record in hpbrain_operational_records is outside 2025-06-01 to 2026-04-30
        $outOfBounds = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $this->tenantId)
            ->where(function ($q) {
                $q->where('occurred_at', '<', '2025-06-01 00:00:00')
                    ->orWhere('occurred_at', '>', '2026-04-30 23:59:59');
            })
            ->count();

        $this->assertSame(0, $outOfBounds, 'Strict Single Academic Year: zero records should exist outside 2025-06-01 to 2026-04-30');

        $totalRecords = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->assertGreaterThanOrEqual(4200, $totalRecords, 'Operational dataset should contain full year data');
    }

    public function test_student_read_model_projections(): void
    {
        $studentsCount = DB::table('hpbrain_students')
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->assertSame(140, $studentsCount, 'Should have exactly 140 projected students');

        // Check standards distribution
        $standards = DB::table('hpbrain_students')
            ->where('tenant_id', $this->tenantId)
            ->distinct()
            ->pluck('academic_standard')
            ->all();

        $this->assertContains('CBSE-3', $standards);
        $this->assertContains('CBSE-5', $standards);
        $this->assertContains('CBSE-8', $standards);
        $this->assertContains('CBSE-9', $standards);
        $this->assertContains('CBSE-10', $standards);
        $this->assertContains('CBSE-12', $standards);
    }

    public function test_fee_reconciliation_integrity(): void
    {
        $feeRecords = DB::table('hpbrain_operational_records')
            ->where('tenant_id', $this->tenantId)
            ->where('dataset', 'school_fee')
            ->get();

        $this->assertGreaterThan(500, $feeRecords->count());

        foreach ($feeRecords as $rec) {
            $payload = json_decode($rec->payload, true);
            $this->assertNotNull($payload, "Payload should be valid JSON for natural_key: {$rec->natural_key}");

            $due = (float) $payload['amount_due'];
            $concession = (float) $payload['concession_amount'];
            $net = (float) $payload['net_amount'];
            $paid = (float) $payload['amount_paid'];
            $outstanding = (float) $payload['outstanding_amount'];

            // Due - Concession = Net
            $this->assertEqualsWithDelta($net, $due - $concession, 0.01, "Net fee math error on invoice {$rec->natural_key}");

            // Net = Paid + Outstanding
            $this->assertEqualsWithDelta($net, $paid + $outstanding, 0.01, "Balance reconciliation error on invoice {$rec->natural_key}");
        }

        // Test fee intelligence service computation
        $feeIntelligence = app(FeeIntelligenceService::class)->forTenant($this->tenantId);
        $this->assertIsArray($feeIntelligence);
        $this->assertArrayHasKey('overview', $feeIntelligence);
        $this->assertGreaterThan(0, $feeIntelligence['overview']['totalNet'] ?? 0);
        $this->assertGreaterThan(0, $feeIntelligence['overview']['totalCollected'] ?? 0);
    }

    public function test_teacher_capability_register_and_kasba_longitudinal_growth(): void
    {
        $capCount = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $this->tenantId)
            ->count();
        $this->assertGreaterThanOrEqual(8, $capCount, 'Should have standard K-12 capabilities provisioned');

        // Check teacher assignment & longitudinal evaluations
        $teacher = DB::table('tbluser')
            ->where('sub_institute_id', $this->tenantId)
            ->where('email', 'rajesh.kulkarni@scholarvalley.edu')
            ->first();
        $this->assertNotNull($teacher);

        $assignments = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $this->tenantId)
            ->where('target_id', (string) $teacher->id)
            ->get();
        $this->assertNotEmpty($assignments);

        // Check evaluation progression on Differentiated Instruction
        $diffInstCap = DB::table('hpbrain_capabilities')
            ->where('tenant_id', $this->tenantId)
            ->where('capability_code', 'ED_INCLUSIVE_PRACTICE')
            ->first();
        $this->assertNotNull($diffInstCap);

        $diffAssignment = DB::table('hpbrain_capability_assignments')
            ->where('tenant_id', $this->tenantId)
            ->where('target_id', (string) $teacher->id)
            ->where('capability_id', $diffInstCap->id)
            ->first();
        $this->assertNotNull($diffAssignment);

        $evals = DB::table('hpbrain_capability_proficiency')
            ->where('tenant_id', $this->tenantId)
            ->where('assignment_id', $diffAssignment->id)
            ->orderBy('state_changed_date')
            ->get();

        $this->assertCount(2, $evals, 'Should have baseline July 2025 and reassessment March 2026 evaluations');

        $baseline = $evals[0];
        $progression = $evals[1];

        $this->assertSame('developing', $baseline->capability_state);
        $this->assertSame('mastered', $progression->capability_state);
        $this->assertGreaterThan((float) $baseline->skill_level, (float) $progression->skill_level);
    }

    public function test_closed_loop_intelligence_provenance(): void
    {
        // 1. Signal
        $signals = DB::table('hpbrain_signals')->where('tenant_id', $this->tenantId)->get();
        $this->assertGreaterThanOrEqual(3, $signals->count(), 'Should have at least 3 seeded intelligence signals');

        // 2. Evidence
        $evidence = DB::table('hpbrain_evidence')->where('tenant_id', $this->tenantId)->get();
        $this->assertNotEmpty($evidence);
        $firstEv = $evidence->first();
        $this->assertJson($firstEv->content);
        $this->assertJson($firstEv->provenance);

        // 3. Case & Case-Evidence link
        $cases = DB::table('hpbrain_cases')->where('tenant_id', $this->tenantId)->get();
        $this->assertGreaterThanOrEqual(3, $cases->count());

        $caseEvidence = DB::table('hpbrain_case_evidence')->where('tenant_id', $this->tenantId)->get();
        $this->assertNotEmpty($caseEvidence);

        // 4. Decision & ESO Execution
        $decisions = DB::table('hpbrain_decisions')->where('tenant_id', $this->tenantId)->get();
        $this->assertGreaterThanOrEqual(3, $decisions->count());

        $esoExecutions = DB::table('hpbrain_eso_executions')->where('tenant_id', $this->tenantId)->get();
        $this->assertNotEmpty($esoExecutions);

        // 5. Outcome & Learning
        $outcomes = DB::table('hpbrain_outcomes')->where('tenant_id', $this->tenantId)->get();
        $this->assertGreaterThanOrEqual(3, $outcomes->count());

        $learnings = DB::table('hpbrain_learnings')->where('tenant_id', $this->tenantId)->get();
        $this->assertNotEmpty($learnings);
    }

    public function test_scorecard_and_intelligence_caching_without_errors(): void
    {
        $scorecard = app(OrganizationScorecard::class)->forTenant($this->tenantId);

        $this->assertIsArray($scorecard);
        $this->assertArrayHasKey('overall', $scorecard);
        $this->assertGreaterThan(0, $scorecard['overall']);

        $this->assertArrayHasKey('dimensions', $scorecard);
        $this->assertNotEmpty($scorecard['dimensions']);
    }
}
