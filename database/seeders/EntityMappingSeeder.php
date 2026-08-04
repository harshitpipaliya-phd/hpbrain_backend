<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Writes the entity mappings that describe the institute ERP.
 *
 * Every column named below is copied from the table each of the 168 hardcoded
 * references currently reads, so the resolver returns byte-identical strings to
 * the literals it replaces. Phase 1 changes no behaviour; this seeder is the
 * reason it can claim that.
 *
 * IT SEEDS EVERY TENANT, NOT ONE. All existing tenants run on the same ERP
 * tables today — that is precisely the problem being fixed. Seeding a single
 * "school" tenant would leave the other five resolving nothing, and since the
 * resolver fails closed, Phase 2 would break them the moment it stopped naming
 * tables directly. The tenant list is read from institute_detail, which is the
 * same source OrganizationRepository::list() treats as the register of
 * organizations.
 *
 * UNMAPPED IS A RESULT. Four universal fields have no column behind them:
 * OrganizationUnit.head, and Position.unit / reportsTo / isVacant. hrms_
 * departments has no manager column at all, and hrms_job_titles has neither a
 * reporting line nor a vacancy flag. They are left out rather than pointed at a
 * plausible-looking substitute, so has() reports false and the UI can render
 * "never measured" instead of inventing a value. Mapping OrganizationUnit.head
 * to parent_id would be the tempting mistake — see the note in ORG_UNIT below.
 */
final class EntityMappingSeeder extends Seeder
{
    private const SOURCE_SYSTEM = 'erp';

    /**
     * universal entity => [source table, [universal field => source column]]
     *
     * 'tenantKey' and 'id' are the two reserved bindings EntityResolver requires
     * on every entity; see the class docblock on ResolvedSource.
     */
    private const ORGANIZATION = ['institute_detail', [
        // OrganizationRepository::list() selects `d.sub_institute_id as id` —
        // the organization's identity IS its tenant key in this ERP. Both rows
        // therefore name the same column, which is correct and not a duplicate.
        'id'        => 'sub_institute_id',
        'tenantKey' => 'sub_institute_id',
        'name'      => 'organization_name',
        'code'      => 'organization_code',
        'industry'  => 'industry_type',
    ]];

    private const ORG_UNIT = ['hrms_departments', [
        'id'          => 'id',
        'tenantKey'   => 'sub_institute_id',
        'name'        => 'department',
        'description' => 'roles_responsibility',
        'parent'      => 'parent_id',
        'status'      => 'status',
        // 'head' is deliberately absent. hrms_departments has no manager column
        // (verified against the live schema). The existing "Departments Without
        // Manager" rule tests parent_id IS NULL OR = 0, which detects ROOT
        // departments, not headless ones. Mapping head => parent_id here would
        // launder that conflation into the vocabulary layer and make it look
        // deliberate. The rule is left exactly as it is in Phase 1; the naming
        // is recorded in the progress log instead.
    ]];

    private const PERSON = ['tbluser', [
        'id'          => 'id',
        'tenantKey'   => 'sub_institute_id',
        'externalRef' => 'employee_no',
        'firstName'   => 'first_name',
        'lastName'    => 'last_name',
        'email'       => 'email',
        'phone'       => 'mobile',
        'unit'        => 'department_id',
        'position'    => 'jobtitle_id',
        'profile'     => 'user_profile_id',
        'status'      => 'status',
        'joinedDate'  => 'joined_date',
    ]];

    private const POSITION = ['hrms_job_titles', [
        'id'        => 'id',
        'tenantKey' => 'sub_institute_id',
        'title'     => 'title',
        'status'    => 'is_active',
        // 'unit', 'reportsTo' and 'isVacant' have no columns in this table.
    ]];

    public function run(): void
    {
        $entities = [
            'Organization'     => self::ORGANIZATION,
            'OrganizationUnit' => self::ORG_UNIT,
            'Person'           => self::PERSON,
            'Position'         => self::POSITION,
        ];

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $written = 0;

        foreach ($this->tenants() as $tenantId) {
            foreach ($entities as $universalEntity => [$sourceTable, $fields]) {
                foreach ($fields as $universalField => $sourceColumn) {
                    $written += $this->upsert(
                        $tenantId,
                        $universalEntity,
                        $universalField,
                        $sourceTable,
                        $sourceColumn,
                        $now,
                    );
                }
            }
        }

        $this->command?->info("EntityMappingSeeder: {$written} mapping rows written or refreshed.");
    }

    /**
     * Tenant ids, as strings, from the ERP's own register of organizations.
     *
     * sub_institute_id is a BIGINT there and a VARCHAR(36) on every hpbrain_
     * table, and tenant comparison happens as strings throughout the Brain.
     * Casting here rather than at each comparison keeps '4' from being written
     * as 4 and then failing a strict match later.
     *
     * @return array<int, string>
     */
    private function tenants(): array
    {
        return DB::table('institute_detail')
            ->whereNull('deleted_at')
            ->distinct()
            ->orderBy('sub_institute_id')
            ->pluck('sub_institute_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Idempotent by (tenant, universal entity, universal field) — the same key
     * the corrected unique index enforces. Seeders get re-run; this one must not
     * accumulate duplicates or fail on the second pass.
     */
    private function upsert(
        string $tenantId,
        string $universalEntity,
        string $universalField,
        string $sourceTable,
        string $sourceColumn,
        string $now,
    ): int {
        $existing = DB::table('hpbrain_entity_mappings')
            ->where('tenant_id', $tenantId)
            ->where('universal_entity', $universalEntity)
            ->where('universal_field', $universalField)
            ->value('id');

        $values = [
            'source_system'        => self::SOURCE_SYSTEM,
            'source_entity'        => $sourceTable,
            'source_field'         => $sourceColumn,
            'mapping_type'         => 'direct',
            'transform_expression' => null,
            'lookup_table'         => null,
            'is_active'            => 1,
            'updated_date'         => $now,
        ];

        if ($existing !== null) {
            DB::table('hpbrain_entity_mappings')->where('id', $existing)->update($values);

            return 1;
        }

        DB::table('hpbrain_entity_mappings')->insert($values + [
            'id'               => Uuid::uuid4()->toString(),
            'tenant_id'        => $tenantId,
            'universal_entity' => $universalEntity,
            'universal_field'  => $universalField,
            'created_by'       => 'system',
            'created_date'     => $now,
        ]);

        return 1;
    }
}
