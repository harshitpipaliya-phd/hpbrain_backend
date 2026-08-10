<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Seeds the Lions demo organization so it can authenticate through the
 * existing ERP-backed login flow.
 */
final class DemoLionsSeeder extends Seeder
{
    private const TENANT_ID = '8';

    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s');

        // 1. ERP organization register -----------------------------------------
        DB::table('institute_detail')->updateOrInsert(
            ['sub_institute_id' => (int) self::TENANT_ID],
            [
                'organization_name'    => 'Lions',
                'organization_code'    => 'LIONS',
                'industry_type'        => 'Education',
                'organization_email'   => 'lions@gmail.com',
                'organization_ph_no'   => '',
                'organization_website' => '',
                'address'              => '',
                'registration_number' => '',
                'handler_name'         => 'Admin',
                'handler_mobile'       => '',
                'handler_email'        => 'lions@gmail.com',
                'total_emp'            => 1,
                'total_department'     => 0,
                'working_days'         => 5,
                'working_hours'        => 8,
                'created_by'           => null,
                'updated_by'           => null,
                'deleted_by'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
                'deleted_at'           => null,
            ]
        );

        // 2. ERP organization profile ------------------------------------------
        DB::table('org_details')->updateOrInsert(
            ['sub_institute_id' => (int) self::TENANT_ID],
            [
                'legal_name'   => 'Lions',
                'industry'     => 'Education',
                'mobile_no'    => '',
                'country_code' => '+91',
                'email'        => 'lions@gmail.com',
                'website'      => '',
                'created_by'   => null,
                'updated_by'   => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]
        );

        // 3. ERP person profile — "Organization Administrator" resolves to
        //    tenant_admin because AuthController::resolveRole() matches "admin".
        $profile = DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', (int) self::TENANT_ID)
            ->where('name', 'Organization Administrator')
            ->first();

        if ($profile) {
            $profileId = (int) $profile->id;
        } else {
            $profileId = DB::table('tbluserprofilemaster')->insertGetId([
                'parent_id'       => null,
                'name'            => 'Organization Administrator',
                'description'     => 'Organization Administrator',
                'sort_order'      => 1,
                'status'          => 1,
                'sub_institute_id'=> (int) self::TENANT_ID,
                'client_id'       => null,
                'deleted_at'      => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // 4. ERP person record -------------------------------------------------
        DB::table('tbluser')->updateOrInsert(
            ['email' => 'lions@gmail.com'],
            [
                'user_name'         => 'admin',
                'password'          => bcrypt('admin'),
                'first_name'        => 'Admin',
                'last_name'         => '',
                'email'             => 'lions@gmail.com',
                'mobile'            => '',
                'gender'            => '',
                'status'            => 1,
                'user_profile_id'   => $profileId,
                'sub_institute_id'  => (int) self::TENANT_ID,
                'client_id'         => null,
                'is_admin'          => 0,
                'portal_user'       => 0,
                'deleted_at'        => null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]
        );

        // 5. Brain tenant registry ---------------------------------------------
        DB::table('hpbrain_tenants')->updateOrInsert(
            ['id' => self::TENANT_ID],
            [
                'name'         => 'Lions',
                'region'       => 'default',
                'status'       => 'active',
                'created_date' => $now,
            ]
        );

        // 6. Brain organization ------------------------------------------------
        DB::table('hpbrain_organizations')->updateOrInsert(
            ['tenant_id' => self::TENANT_ID],
            [
                'id'          => (string) Uuid::uuid4(),
                'name'        => 'Lions',
                'org_code'    => 'LIONS',
                'industry'    => 'Education',
                'status'      => 'active',
                'created_by'  => 'system',
                'created_date'=> $now,
                'updated_date'=> $now,
            ]
        );

        // 7. Entity mappings (mirrors the shared-ERP tenant shape).
        $entities = [
            'Organization'        => ['institute_detail', [
                'id'        => 'sub_institute_id',
                'tenantKey' => 'sub_institute_id',
                'name'      => 'organization_name',
                'code'      => 'organization_code',
                'industry'  => 'industry_type',
                'deletedAt' => 'deleted_at',
            ]],
            'OrganizationProfile' => ['org_details', [
                'id'        => 'id',
                'tenantKey' => 'sub_institute_id',
                'legalName' => 'legal_name',
                'logo'      => 'logo',
            ]],
            'OrganizationUnit'    => ['hrms_departments', [
                'id'          => 'id',
                'tenantKey'   => 'sub_institute_id',
                'name'        => 'department',
                'description' => 'roles_responsibility',
                'parent'      => 'parent_id',
                'status'      => 'status',
                'deletedAt'   => 'deleted_at',
            ]],
            'Person'              => ['tbluser', [
                'id'          => 'id',
                'tenantKey'   => 'sub_institute_id',
                'externalRef' => 'employee_no',
                'firstName'   => 'first_name',
                'lastName'    => 'last_name',
                'email'       => 'email',
                'phone'       => 'mobile',
                'gender'      => 'gender',
                'unit'        => 'department_id',
                'position'    => 'jobtitle_id',
                'profile'     => 'user_profile_id',
                'status'      => 'status',
                'joinedDate'  => 'joined_date',
                'deletedAt'   => 'deleted_at',
            ]],
            'Position'            => ['hrms_job_titles', [
                'id'        => 'id',
                'tenantKey' => 'sub_institute_id',
                'title'     => 'title',
                'status'    => 'is_active',
            ]],
            'PersonProfile'       => ['tbluserprofilemaster', [
                'id'        => 'id',
                'tenantKey' => 'sub_institute_id',
                'name'      => 'name',
                'status'    => 'status',
            ]],
        ];

        foreach ($entities as $universalEntity => [$sourceTable, $fields]) {
            foreach ($fields as $universalField => $sourceColumn) {
                $existing = DB::table('hpbrain_entity_mappings')
                    ->where('tenant_id', self::TENANT_ID)
                    ->where('universal_entity', $universalEntity)
                    ->where('universal_field', $universalField)
                    ->value('id');

                if ($existing !== null) {
                    DB::table('hpbrain_entity_mappings')->where('id', $existing)->update([
                        'source_system'     => 'erp',
                        'source_entity'     => $sourceTable,
                        'source_field'      => $sourceColumn,
                        'mapping_type'      => 'direct',
                        'transform_expression' => null,
                        'lookup_table'      => null,
                        'is_active'         => 1,
                        'updated_date'      => $now,
                    ]);
                } else {
                    DB::table('hpbrain_entity_mappings')->insert([
                        'id'                => Uuid::uuid4()->toString(),
                        'tenant_id'         => self::TENANT_ID,
                        'source_system'     => 'erp',
                        'source_entity'     => $sourceTable,
                        'source_field'      => $sourceColumn,
                        'universal_entity'  => $universalEntity,
                        'universal_field'   => $universalField,
                        'mapping_type'      => 'direct',
                        'transform_expression' => null,
                        'lookup_table'      => null,
                        'is_active'         => 1,
                        'created_by'        => 'system',
                        'created_date'      => $now,
                        'updated_date'      => $now,
                    ]);
                }
            }
        }
    }
}
