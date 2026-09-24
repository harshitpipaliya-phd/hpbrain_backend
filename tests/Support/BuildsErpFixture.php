<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The institute ERP's tables, and one tenant's worth of data in them.
 *
 * Separate from BuildsBrainSchema because these are NOT Brain tables — they are
 * the source system the Brain reads through EntityResolver, and a second
 * industry will bring its own. Keeping them apart makes that distinction
 * structural rather than a matter of remembering.
 *
 * Column definitions are copied from the live MySQL schema. The fixture is
 * deliberately flawed in specific ways — one person with no department, one with
 * no profile, one with no email, one root department — because every data
 * quality figure and every signal rule in the system is a count of exactly those
 * conditions. A clean fixture would make all of them return zero and prove
 * nothing.
 */
trait BuildsErpFixture
{
    protected function buildErpSchema(): void
    {
        Schema::create('institute_detail', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('organization_name')->nullable();
            $t->string('organization_code')->nullable();
            $t->string('industry_type')->nullable();
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('org_details', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('legal_name')->nullable();
            $t->string('logo')->nullable();
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('hrms_departments', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('department');
            $t->text('roles_responsibility')->nullable();
            $t->integer('parent_id')->default(0);
            $t->integer('status')->default(1);
            $t->integer('is_calculated')->default(0);
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('tbluser', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('employee_no')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile')->nullable();
            $t->string('password')->nullable();
            $t->string('plain_password')->nullable();
            $t->integer('department_id')->nullable();
            $t->integer('jobtitle_id')->nullable();
            $t->integer('user_profile_id')->nullable();
            $t->date('joined_date')->nullable();
            $t->integer('status')->default(1);
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('tbluserprofilemaster', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('name');
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('hrms_job_titles', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('title');
            $t->text('description')->nullable();
            $t->integer('is_active')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });
    }

    /**
     * One organization, three departments, five active people.
     *
     * The flaws are load-bearing: person 3 has no department, person 4 has no
     * profile, person 5 has no email, and department 1 is a root (parent_id 0).
     */
    protected function seedErpFixture(int $tenantId = 4): void
    {
        DB::table('institute_detail')->insert([
            'sub_institute_id' => $tenantId, 'organization_name' => 'SIDS HealthCare',
            'organization_code' => 'SIDS', 'industry_type' => 'Healthcare',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00',
        ]);

        DB::table('org_details')->insert([
            'sub_institute_id' => $tenantId,
            'legal_name' => 'SIDS HealthCare Pvt Ltd', 'logo' => 'sids.png',
        ]);

        DB::table('hrms_departments')->insert([
            ['id' => 1, 'sub_institute_id' => $tenantId, 'department' => 'Nursing',
             'roles_responsibility' => 'Ward care', 'parent_id' => 0, 'status' => 1,
             'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'sub_institute_id' => $tenantId, 'department' => 'Surgery',
             'roles_responsibility' => null, 'parent_id' => 1, 'status' => 1,
             'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 3, 'sub_institute_id' => $tenantId, 'department' => 'Radiology',
             'roles_responsibility' => null, 'parent_id' => 1, 'status' => 1,
             'created_by' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $people = [
            [1, 'E1', 'Asha',  'Rao',   'asha@x.test',  1, 1],
            [2, 'E2', 'Bilal', 'Khan',  'bilal@x.test', 2, 1],
            [3, 'E3', 'Chen',  'Wu',    'chen@x.test',  0, 1],   // no department
            [4, 'E4', 'Dev',   'Patel', 'dev@x.test',   2, 0],   // no profile
            [5, 'E5', 'Eve',   'Silva', '',             3, 1],   // no email
        ];

        foreach ($people as [$id, $no, $first, $last, $email, $dept, $profile]) {
            DB::table('tbluser')->insert([
                'id' => $id, 'sub_institute_id' => $tenantId, 'employee_no' => $no,
                'first_name' => $first, 'last_name' => $last, 'email' => $email,
                'department_id' => $dept, 'user_profile_id' => $profile,
                'jobtitle_id' => 1, 'status' => 1,
            ]);
        }

        DB::table('tbluserprofilemaster')->insert([
            'sub_institute_id' => $tenantId, 'name' => 'Employee', 'status' => 1,
        ]);

        DB::table('hrms_job_titles')->insert([
            'id' => 1, 'sub_institute_id' => $tenantId, 'title' => 'Staff Nurse', 'is_active' => 1,
        ]);
    }
}
