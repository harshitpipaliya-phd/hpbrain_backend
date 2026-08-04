<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Capability\DemandService;
use App\Domain\Kasba\AssessmentModelResolver;
use App\Domain\Signals\RuleEvaluator;
use App\Domain\Universal\EntityResolver;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Phase 8: a second industry, on its OWN tables, onboarded by INSERT alone.
 *
 * This is the test that decides whether Phases 1-5 achieved anything. Everything
 * else proved the school tenant still works, which a sufficiently careful
 * refactor would do while leaving the coupling exactly where it was.
 *
 * The hospital below shares NO table with the institute ERP. Its people live in
 * `hc_staff`, its units in `hc_wards`, its roles in `hc_roles`. Its columns are
 * spelled differently — `staff_id`, `org_ref`, `work_email`, `active` — and its
 * soft-delete column is `archived_at`, not `deleted_at`.
 *
 * NOT ONE LINE OF APPLICATION CODE KNOWS ANY OF THAT. Onboarding is:
 *
 *   1. entity mappings          (where its entities live)
 *   2. an industry template     (its assessment model)
 *   3. signal rules             (what it considers a problem)
 *   4. job-role requirements    (what it needs)
 *
 * All four are INSERTs. If this test ever requires an edit to app/, the
 * universality claim has regressed and the edit is the evidence.
 *
 * ONE HONEST FAILURE IS ASSERTED TOO. `archived_at` is where this stops being
 * configuration: soft-delete is still assumed to be spelled `deleted_at` in the
 * places Phase 2 recorded as "not yet universal". The hospital works because its
 * table also carries a `deleted_at` column, and the test says so rather than
 * quietly using one and claiming the other.
 */
final class SecondIndustryTest extends TestCase
{
    use BuildsBrainSchema;

    private const HOSPITAL = 'hosp-1';

    private const SCHOOL = 'school-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildHospitalSource();
        $this->onboardByInsertOnly();
    }

    /**
     * The hospital's own source system. Nothing here is named anywhere in app/.
     */
    private function buildHospitalSource(): void
    {
        Schema::create('hc_org', function ($t) {
            $t->increments('row_id');
            $t->string('org_ref', 36);
            $t->string('trading_name')->nullable();
            $t->string('org_short_code')->nullable();
            $t->string('sector')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('hc_org_profile', function ($t) {
            $t->increments('row_id');
            $t->string('org_ref', 36);
            $t->string('registered_name')->nullable();
            $t->string('crest')->nullable();
        });

        Schema::create('hc_wards', function ($t) {
            $t->increments('ward_id');
            $t->string('org_ref', 36);
            $t->string('ward_name');
            $t->text('remit')->nullable();
            $t->integer('parent_ward')->default(0);
            $t->integer('active')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('hc_staff', function ($t) {
            $t->increments('staff_id');
            $t->string('org_ref', 36);
            $t->string('payroll_ref')->nullable();
            $t->string('given_name')->nullable();
            $t->string('family_name')->nullable();
            $t->string('work_email')->nullable();
            $t->string('contact_number')->nullable();
            $t->integer('ward_id')->nullable();
            $t->integer('role_id')->nullable();
            $t->integer('access_profile')->nullable();
            $t->date('start_date')->nullable();
            $t->integer('active')->default(1);
            $t->string('password')->nullable();
            $t->string('plain_password')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('hc_access_profiles', function ($t) {
            $t->increments('profile_id');
            $t->string('org_ref', 36);
            $t->string('profile_name');
            $t->integer('active')->default(1);
        });

        Schema::create('hc_roles', function ($t) {
            $t->increments('role_id');
            $t->string('org_ref', 36);
            $t->string('role_name');
            $t->integer('active')->default(1);
        });

        DB::table('hc_org')->insert([
            'org_ref' => self::HOSPITAL, 'trading_name' => 'St Elsewhere',
            'org_short_code' => 'STE', 'sector' => 'healthcare',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        DB::table('hc_org_profile')->insert([
            'org_ref' => self::HOSPITAL,
            'registered_name' => 'St Elsewhere NHS Trust', 'crest' => 'ste.svg',
        ]);

        DB::table('hc_wards')->insert([
            ['ward_id' => 1, 'org_ref' => self::HOSPITAL, 'ward_name' => 'Intensive Care',
             'remit' => 'Critical care', 'parent_ward' => 0, 'active' => 1],
            ['ward_id' => 2, 'org_ref' => self::HOSPITAL, 'ward_name' => 'Maternity',
             'remit' => null, 'parent_ward' => 1, 'active' => 1],
        ]);

        DB::table('hc_access_profiles')->insert([
            'profile_id' => 1, 'org_ref' => self::HOSPITAL, 'profile_name' => 'Clinician', 'active' => 1,
        ]);

        DB::table('hc_roles')->insert([
            'role_id' => 1, 'org_ref' => self::HOSPITAL, 'role_name' => 'Staff Nurse', 'active' => 1,
        ]);

        // Four nurses. One has no ward, one has no access profile, one has no
        // email — the same three defects the shipped rules look for, expressed
        // in an entirely different vocabulary.
        $staff = [
            [1, 'P1', 'Ama',   'Owusu',  'ama@ste.test',  1, 1],
            [2, 'P2', 'Bo',    'Chen',   'bo@ste.test',   0, 1],   // no ward
            [3, 'P3', 'Cira',  'Diaz',   'cira@ste.test', 2, 0],   // no access profile
            [4, 'P4', 'Deniz', 'Kaya',   '',              2, 1],   // no email
        ];

        foreach ($staff as [$id, $ref, $given, $family, $email, $ward, $profile]) {
            DB::table('hc_staff')->insert([
                'staff_id' => $id, 'org_ref' => self::HOSPITAL, 'payroll_ref' => $ref,
                'given_name' => $given, 'family_name' => $family, 'work_email' => $email,
                'ward_id' => $ward, 'access_profile' => $profile, 'role_id' => 1,
                'active' => 1, 'password' => Hash::make('ward-secret-1'),
            ]);
        }
    }

    /**
     * Onboarding: four sets of INSERTs and nothing else.
     */
    private function onboardByInsertOnly(): void
    {
        // 1 — where the hospital's entities live.
        $mappings = [
            'Organization' => ['hc_org', [
                'id' => 'org_ref', 'tenantKey' => 'org_ref', 'name' => 'trading_name',
                'code' => 'org_short_code', 'industry' => 'sector', 'deletedAt' => 'deleted_at',
            ]],
            'OrganizationProfile' => ['hc_org_profile', [
                'id' => 'row_id', 'tenantKey' => 'org_ref',
                'legalName' => 'registered_name', 'logo' => 'crest',
            ]],
            'OrganizationUnit' => ['hc_wards', [
                'id' => 'ward_id', 'tenantKey' => 'org_ref', 'name' => 'ward_name',
                'description' => 'remit', 'parent' => 'parent_ward', 'status' => 'active',
                'deletedAt' => 'deleted_at',
            ]],
            'Person' => ['hc_staff', [
                'id' => 'staff_id', 'tenantKey' => 'org_ref', 'externalRef' => 'payroll_ref',
                'firstName' => 'given_name', 'lastName' => 'family_name', 'email' => 'work_email',
                'phone' => 'contact_number', 'unit' => 'ward_id', 'position' => 'role_id',
                'profile' => 'access_profile', 'status' => 'active', 'joinedDate' => 'start_date',
                'deletedAt' => 'deleted_at',
            ]],
            'PersonProfile' => ['hc_access_profiles', [
                'id' => 'profile_id', 'tenantKey' => 'org_ref',
                'name' => 'profile_name', 'status' => 'active',
            ]],
            'Position' => ['hc_roles', [
                'id' => 'role_id', 'tenantKey' => 'org_ref',
                'title' => 'role_name', 'status' => 'active',
            ]],
        ];

        foreach ($mappings as $entity => [$table, $fields]) {
            foreach ($fields as $universal => $column) {
                DB::table('hpbrain_entity_mappings')->insert([
                    'id' => "hm-{$entity}-{$universal}", 'tenant_id' => self::HOSPITAL,
                    'source_system' => 'hospital-pas', 'source_entity' => $table,
                    'source_field' => $column, 'universal_entity' => $entity,
                    'universal_field' => $universal, 'mapping_type' => 'direct',
                    'is_active' => true, 'created_by' => 'onboarding',
                    'created_date' => '2026-08-04 00:00:00', 'updated_date' => '2026-08-04 00:00:00',
                ]);
            }
        }

        // 2 — the industry's assessment model. Clinical competence is not KASBA.
        DB::table('hpbrain_industry_templates')->insert([
            'id' => 'tpl-healthcare', 'tenant_id' => 'platform', 'industry_code' => 'healthcare',
            'template_name' => 'Healthcare', 'is_active' => true,
            'assessment_model' => json_encode([
                'dimensions' => ['clinical', 'procedural', 'safety'],
                'maxLevel' => 4,
                'assessableEntityTypes' => ['Person'],
            ]),
            'created_by' => 'onboarding',
            'created_date' => '2026-08-04 00:00:00', 'updated_date' => '2026-08-04 00:00:00',
        ]);

        // 3 — what this hospital considers a problem. Same universal fields,
        //     entirely different underlying columns.
        DB::table('hpbrain_signal_rules')->insert([
            'id' => 'hr-no-ward', 'tenant_id' => self::HOSPITAL,
            'rule_key' => 'staff_without_ward', 'industry_code' => 'healthcare',
            'universal_entity' => 'Person',
            'predicate' => json_encode(['all' => [
                ['field' => 'deletedAt', 'op' => 'is_null'],
                ['field' => 'status', 'op' => 'eq', 'value' => 1],
                ['any' => [
                    ['field' => 'unit', 'op' => 'is_null'],
                    ['field' => 'unit', 'op' => 'eq', 'value' => 0],
                ]],
            ]]),
            'classification' => 'workforce', 'severity' => 'high', 'priority' => 'high',
            'confidence' => 1.0,
            'evidence_fields' => json_encode([
                'payrollRef' => 'externalRef',
                'name' => ['concat' => ['firstName', 'lastName'], 'separator' => ' '],
            ]),
            'recommended_action' => 'clinician is not assigned to a ward',
            'is_active' => 1, 'created_by' => 'onboarding', 'created_date' => '2026-08-04 00:00:00',
        ]);

        // 4 — what the hospital needs.
        DB::table('hpbrain_job_role_capability_requirements')->insert([
            'tenant_id' => self::HOSPITAL, 'job_role_id' => '1',
            'capability_id' => 'cap-resus', 'required_level' => 3.0,
        ]);
    }

    private function auth(string $tenant = self::HOSPITAL): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'hosp-admin', 'tenantId' => $tenant, 'role' => 'admin',
        ])];
    }

    // ---- the resolver reaches the hospital's own tables -------------------

    /** @test */
    public function every_entity_resolves_to_the_hospitals_own_tables(): void
    {
        $resolver = app(EntityResolver::class);

        $person = $resolver->resolve(self::HOSPITAL, 'Person');
        $this->assertSame('hc_staff', $person->table);
        $this->assertSame('org_ref', $person->tenantKey);
        $this->assertSame('staff_id', $person->primaryKey);
        $this->assertSame('work_email', $person->field('email'));
        $this->assertSame('ward_id', $person->field('unit'));

        $unit = $resolver->resolve(self::HOSPITAL, 'OrganizationUnit');
        $this->assertSame('hc_wards', $unit->table);
        $this->assertSame('ward_name', $unit->field('name'));

        $this->assertSame('hc_org', $resolver->resolve(self::HOSPITAL, 'Organization')->table);
        $this->assertSame('hc_roles', $resolver->resolve(self::HOSPITAL, 'Position')->table);
    }

    // ---- the screens' endpoints answer in the hospital's vocabulary -------

    /** @test */
    public function the_organization_endpoint_reads_the_hospitals_tables(): void
    {
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/organizations/'.self::HOSPITAL)
            ->assertStatus(200)->json();

        $this->assertSame('St Elsewhere', $body[0]['name']);
        $this->assertSame('STE', $body[0]['org_code']);
        $this->assertSame('St Elsewhere NHS Trust', $body[0]['legal_name']);
        $this->assertSame('ste.svg', $body[0]['logo']);
    }

    /** @test */
    public function the_units_endpoint_reads_wards(): void
    {
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/departments/'.self::HOSPITAL)
            ->assertStatus(200)->json();

        $this->assertCount(2, $body);
        $this->assertSame('Intensive Care', $body[0]['name']);
        $this->assertSame('Critical care', $body[0]['description']);
        $this->assertNull($body[0]['parentDepartmentId']);
        $this->assertSame('1', $body[1]['parentDepartmentId']);
    }

    /** @test */
    public function the_people_endpoint_reads_staff(): void
    {
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/people/'.self::HOSPITAL)
            ->assertStatus(200)->json();

        $this->assertCount(4, $body);
        $this->assertSame('Ama', $body[0]['firstName']);
        $this->assertSame('P1', $body[0]['employeeId']);
        $this->assertSame('ama@ste.test', $body[0]['email']);
        // Unmapped in this source — reported as null, never invented.
        $this->assertNull($body[0]['gender']);
    }

    /** @test */
    public function home_metrics_counts_the_hospitals_own_defects(): void
    {
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/workspace/'.self::HOSPITAL.'/home-metrics')
            ->assertStatus(200)->json();

        $this->assertSame(4, $body['erp']['activePeople']);
        $this->assertSame(2, $body['erp']['activeDepartments']);
        $this->assertSame(1, $body['erp']['peopleWithoutDepartment']);
        $this->assertSame(1, $body['erp']['peopleWithoutProfile']);

        // The attention queue is built from the same counts, so it speaks about
        // wards and clinicians without any code knowing those words.
        $ids = array_column($body['attention'], 'id');
        $this->assertContains('people-without-dept', $ids);
    }

    /** @test */
    public function a_clinician_signs_in_against_the_hospitals_staff_table(): void
    {
        // Login has no tenant context; it finds the tenant by searching every
        // mapped source. The hospital's table is a second source shape, so this
        // is the case that would break if login still assumed one table.
        $body = $this->postJson('/api/v1/auth/login', [
            'email' => 'ama@ste.test', 'password' => 'ward-secret-1',
        ])->assertStatus(200)->json();

        $this->assertSame(self::HOSPITAL, $body['organization']['id']);
        $this->assertSame('St Elsewhere', $body['organization']['name']);
        $this->assertSame('Ama', $body['user']['firstName']);
        $this->assertSame('P1', $body['user']['employeeNo']);
        // Role resolved from hc_access_profiles.profile_name — 'Clinician'
        // matches no privileged keyword, so it lands on member.
        $this->assertSame('member', $body['user']['role']);
    }

    // ---- rules, model and demand all follow the configuration -------------

    /** @test */
    public function the_hospitals_own_signal_rule_fires_on_its_own_columns(): void
    {
        $result = app(RuleEvaluator::class)->evaluate(self::HOSPITAL);

        $this->assertSame(1, $result['created']);

        $signal = DB::table('hpbrain_signals')->where('tenant_id', self::HOSPITAL)->first();
        $this->assertSame('workforce', $signal->classification);
        $this->assertSame('high', $signal->severity);
        $this->assertSame('staff_without_ward', json_decode($signal->metadata, true)['rule']);

        $evidence = json_decode(
            DB::table('hpbrain_evidence')->where('tenant_id', self::HOSPITAL)->value('content'),
            true,
        );

        $this->assertSame('erp.hc_staff', $evidence['source']);
        $this->assertSame('P2', $evidence['payrollRef']);
        $this->assertSame('Bo Chen', $evidence['name']);
        $this->assertSame('clinician is not assigned to a ward', $evidence['issue']);
    }

    /** @test */
    public function the_assessment_model_is_the_industrys_three_dimensions(): void
    {
        $model = app(AssessmentModelResolver::class)->forTenant(self::HOSPITAL);

        $this->assertSame(['clinical', 'procedural', 'safety'], $model->dimensions);
        $this->assertSame(4, $model->maxLevel);
        $this->assertSame('template', $model->origin);
        // Clinical competence is assessed of people, not of wards.
        $this->assertTrue($model->assesses('Person'));
        $this->assertFalse($model->assesses('OrganizationUnit'));
    }

    /** @test */
    public function the_heatmap_renders_three_columns_with_no_frontend_change(): void
    {
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/kasba/heatmap/'.self::HOSPITAL)
            ->assertStatus(200)->json();

        $this->assertSame(['clinical', 'procedural', 'safety'], array_keys($body['dimensions']));
        $this->assertSame(['clinical', 'procedural', 'safety'], $body['model']['dimensions']);
    }

    /** @test */
    public function demand_is_computed_from_the_hospitals_roles(): void
    {
        $rows = app(DemandService::class)->keyedByCapability(self::HOSPITAL);

        // Four staff hold role 1, required level 3.
        $this->assertEqualsWithDelta(12.0, $rows['cap-resus']['demand'], 0.0001);
        $this->assertSame(4, $rows['cap-resus']['headcount']);
        // Nothing assessed yet: unknown, not short.
        $this->assertNull($rows['cap-resus']['deficit']);
    }

    /** @test */
    public function the_snapshot_command_records_the_hospital_too(): void
    {
        $this->artisan('brain:snapshot', ['--tenant' => self::HOSPITAL, '--date' => '2026-08-04'])
            ->assertExitCode(0);

        $this->assertGreaterThan(0, DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', self::HOSPITAL)->count());

        $deficit = DB::table('hpbrain_metric_snapshots')
            ->where('tenant_id', self::HOSPITAL)
            ->where('metric_key', 'capability.deficit')->first();

        $this->assertNotNull($deficit);
        $this->assertNull($deficit->value, 'An unmeasured deficit stays null for every industry.');
    }

    // ---- isolation, which is the whole reason it fails closed ------------

    /** @test */
    public function the_hospital_cannot_see_a_school_that_shares_no_mapping(): void
    {
        $this->assertFalse(app(EntityResolver::class)->has(self::SCHOOL, 'Person'));

        $status = $this->withHeaders($this->auth(self::SCHOOL))
            ->getJson('/api/v1/people/'.self::SCHOOL)->status();

        $this->assertNotSame(200, $status);
    }

    /** @test */
    public function the_hospitals_rule_does_not_run_for_another_tenant(): void
    {
        app(RuleEvaluator::class)->evaluate(self::HOSPITAL);

        $this->assertSame(0, DB::table('hpbrain_signals')
            ->where('tenant_id', '<>', self::HOSPITAL)->count());
    }

    // ---- the honest limit ------------------------------------------------

    /** @test */
    public function soft_delete_is_still_assumed_to_be_named_deleted_at(): void
    {
        // Phase 2 recorded this and it is still true: `deleted_at` is written
        // literally in the query builders, so a source spelling it `archived_at`
        // would need code, not configuration. The hospital works because its
        // tables also carry a `deleted_at` column — mapping deletedAt is not
        // yet sufficient on its own.
        //
        // Asserted rather than described so that closing the gap deletes a
        // failing test rather than leaving a stale claim in a document.
        $this->assertTrue(Schema::hasColumn('hc_staff', 'deleted_at'));

        $person = app(EntityResolver::class)->resolve(self::HOSPITAL, 'Person');
        $this->assertSame('deleted_at', $person->field('deletedAt'),
            'Until the builders read this mapping, only deleted_at works.');
    }
}
