<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    private const LEGACY_ORGANIZATION = ['institute_detail', [
        // OrganizationRepository::list() selects `d.sub_institute_id as id` —
        // the organization's identity IS its tenant key in this ERP. Both rows
        // therefore name the same column, which is correct and not a duplicate.
        'id'        => 'sub_institute_id',
        'tenantKey' => 'sub_institute_id',
        'name'      => 'organization_name',
        'code'      => 'organization_code',
        'industry'  => 'industry_type',
        'deletedAt' => 'deleted_at',
    ]];

    private const ORG_UNIT = ['hrms_departments', [
        'id'          => 'id',
        'tenantKey'   => 'sub_institute_id',
        'name'        => 'department',
        'description' => 'roles_responsibility',
        'parent'      => 'parent_id',
        'status'      => 'status',
        'deletedAt'   => 'deleted_at',
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
        'gender'      => 'gender',
        'unit'        => 'department_id',
        'position'    => 'jobtitle_id',
        'profile'     => 'user_profile_id',
        'status'      => 'status',
        'joinedDate'  => 'joined_date',
        'deletedAt'   => 'deleted_at',
    ]];

    private const POSITION = ['hrms_job_titles', [
        'id'        => 'id',
        'tenantKey' => 'sub_institute_id',
        'title'     => 'title',
        'status'    => 'is_active',
        // 'unit', 'reportsTo' and 'isVacant' have no columns in this table.
    ]];

    /**
     * The two satellite tables the ERP hangs off the main ones.
     *
     * They are separate universal entities rather than lookup-typed fields on
     * Organization and Person because each is a row in its own right with its
     * own key and lifecycle — OrganizationRepository::create() inserts into both
     * org_details and tbluserprofilemaster directly. Folding them in as lookups
     * would describe a read path the code does not take and leave the writes
     * with nothing to resolve against.
     */
    private const LEGACY_ORGANIZATION_PROFILE = ['org_details', [
        'id'        => 'id',
        'tenantKey' => 'sub_institute_id',
        'legalName' => 'legal_name',
        'logo'      => 'logo',

        // THE REST OF THE ORGANISATION RECORD, which the ERP has always kept
        // here and the Brain simply never asked for. Leaving them unmapped is
        // what made the Organization screen show "Not recorded" beside eight
        // fields that were populated one table away, and what made the edit
        // form able to change three of them.
        //
        // Every column below is verified against the live org_details schema.
        // A deployment whose copy of the table is older is covered by
        // SourceSchema, which narrows a mapped set to the columns the table
        // actually carries before anything is selected or written.
        'email'              => 'email',
        'phone'              => 'mobile_no',
        'website'            => 'website',
        'address'            => 'registered_address',
        'registrationNumber' => 'cin',
        'taxId'              => 'gstin',
        'employeeCount'      => 'employee_count',
        'workWeek'           => 'work_week',
        // 'country', 'timezone' and 'currency' are deliberately absent.
        // org_details HAS a `country_code` column, and mapping it here was the
        // tempting mistake: it holds a dialling prefix ('+91'), so a Country
        // row rendered from it reads "Country: +91". A field the ERP does not
        // record is better unmapped — the screen then omits it — than filled
        // with a value that is confidently wrong.
    ]];

    private const SCHOOL_SETUP_ORGANIZATION = ['school_setup', [
        'id'        => 'Id',
        'tenantKey' => 'Id',
        'name'      => 'SchoolName',
        'code'      => 'ShortCode',
        'industry'  => 'institute_type',
    ]];

    private const SCHOOL_SETUP_ORGANIZATION_PROFILE = ['school_setup', [
        'id'        => 'Id',
        'tenantKey' => 'Id',
        'legalName' => 'SchoolName',
        'logo'      => 'Logo',

        // The school-shaped ERP keeps a smaller profile, and the difference is
        // the point: it has no registration number, no country and no tax id,
        // so those stay unmapped for this branch and the same screen renders
        // fewer fields without being told which ERP it is looking at.
        'email'         => 'Email',
        'phone'         => 'Mobile',
        'address'       => 'ReceiptAddress',
        'contactPerson' => 'ContactPerson',
    ]];

    private const PERSON_PROFILE = ['tbluserprofilemaster', [
        'id'        => 'id',
        'tenantKey' => 'sub_institute_id',
        'name'      => 'name',
        'status'    => 'status',
    ]];

    /**
     * The student roster, where the ERP keeps one.
     *
     * NOT EVERY ERP HAS ONE, and that is why this is a candidate rather than a
     * fixture. An HR-shaped ERP records staff and nothing else; a school ERP
     * records children in a table of their own. Where the table is absent the
     * entity is simply not mapped, `has()` reports false, and every student
     * surface stays honestly empty — the same answer it gives today.
     *
     * Identity is `enrollment_no`, because that is the number the academic and
     * fee exports also carry, and it is the only key the three sources share.
     */
    private const STUDENT = ['tblstudent', [
        'id'            => 'id',
        'tenantKey'     => 'sub_institute_id',
        'externalRef'   => 'enrollment_no',
        'firstName'     => 'first_name',
        'middleName'    => 'middle_name',
        'lastName'      => 'last_name',
        'gender'        => 'gender',
        'birthDate'     => 'dob',
        'email'         => 'email',
        'phone'         => 'mobile',
        'status'        => 'status',
        'uniqueId'      => 'uniqueid',
        'batch'         => 'studentbatch',
        'admissionYear' => 'admission_year',
    ]];

    /**
     * universal entity => candidate sources, best first, each paired with the
     * universal fields it must be able to bind before it may be chosen.
     *
     * THE CANDIDATE LIST IS HOW ONE CODEBASE FOLLOWS TWO ERPs. Neither branch
     * names a database. A candidate qualifies on the SHAPE of what is in front
     * of it — the table exists and carries the columns that make it that entity
     * — so the same seeder describes an HR-shaped ERP whose organizations live
     * in `institute_detail` and a school-shaped one whose organizations live in
     * `school_setup`, and it would describe a third without being edited again.
     *
     * ORDER IS SIGNIFICANT AND `institute_detail` IS FIRST ON PURPOSE. BOTH
     * ERPs have a `school_setup` table, and column lookup is case-insensitive,
     * so "does school_setup exist" is not a question that tells them apart —
     * asking it first re-points an installation that already works onto a table
     * whose ids are a different numbering altogether. Only one of the two has
     * an `institute_detail` carrying `organization_name`; requiring that column
     * is what keeps each ERP on its own register.
     *
     * @return array<string, array<int, array{table: string, fields: array<string, string>, required: array<int, string>}>>
     */
    private function sources(): array
    {
        $candidate = static fn (array $source, array $required, bool $optional = false): array => [
            'table' => $source[0],
            'fields' => $source[1],
            'required' => $required,
            'optional' => $optional,
        ];

        return [
            'Organization' => [
                $candidate(self::LEGACY_ORGANIZATION, ['id', 'tenantKey', 'name']),
                $candidate(self::SCHOOL_SETUP_ORGANIZATION, ['id', 'tenantKey', 'name']),
            ],
            'OrganizationUnit' => [
                $candidate(self::ORG_UNIT, ['id', 'tenantKey', 'name']),
            ],
            'Person' => [
                $candidate(self::PERSON, ['id', 'tenantKey', 'email', 'firstName']),
            ],
            'Position' => [
                $candidate(self::POSITION, ['id', 'tenantKey', 'title']),
            ],
            'OrganizationProfile' => [
                $candidate(self::LEGACY_ORGANIZATION_PROFILE, ['id', 'tenantKey', 'legalName']),
                $candidate(self::SCHOOL_SETUP_ORGANIZATION_PROFILE, ['id', 'tenantKey', 'legalName']),
            ],
            'PersonProfile' => [
                $candidate(self::PERSON_PROFILE, ['id', 'tenantKey', 'name']),
            ],
            'Student' => [
                // OPTIONAL: the only entity a source system may legitimately
                // not have. An HR-shaped ERP records staff and stops there, and
                // mapping it to a table that is not in front of us would turn
                // "this organization has no students" from an honest empty
                // screen into a SQL error.
                $candidate(self::STUDENT, ['id', 'tenantKey', 'externalRef'], optional: true),
            ],
        ];
    }

    /**
     * @param  array<int, string>|null  $tenantIds  explicit tenants to map, or
     *         null to read them from the ERP's own organization register.
     *         Phase 8 onboards a new tenant by naming it here, before it has any
     *         row in that register.
     */
    public function __construct(private readonly ?array $tenantIds = null)
    {
    }

    public function run(): void
    {
        $entities = $this->resolvedEntities();

        if (! isset($entities['Organization'])) {
            $this->command?->error('EntityMappingSeeder: no table in this database looks like an organization register; nothing was written.');

            return;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $written = 0;

        foreach ($entities as $universalEntity => [$sourceTable, $fields]) {
            $this->command?->line(sprintf('  %-20s -> %s (%d fields)', $universalEntity, $sourceTable, count($fields)));
        }

        foreach ($this->tenants() as $tenantId) {
            foreach ($entities as $universalEntity => [$sourceTable, $fields]) {
                $this->retireStaleMappings($tenantId, $universalEntity, $sourceTable, array_keys($fields), $now);

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
     * The source each universal entity actually has in THIS database.
     *
     * An entity with no qualifying candidate is left out entirely rather than
     * pointed at a table that is not there. That is the same fail-closed rule
     * EntityResolver enforces at read time: an unmapped entity makes `has()`
     * answer false and the screens above it render "not recorded", which is the
     * truth. A mapping to a missing table would instead surface as a SQL error
     * on a page that has nothing wrong with it.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    private function resolvedEntities(): array
    {
        $out = [];

        foreach ($this->sources() as $universalEntity => $candidates) {
            $chosen = $this->chooseSource($candidates);

            if ($chosen !== null) {
                $out[$universalEntity] = $chosen;
            }
        }

        return $out;
    }

    /**
     * Which candidate describes the ERP in front of us.
     *
     * A candidate QUALIFIES when its table is present and carries the columns
     * that identify it as that entity. The first qualifying candidate wins.
     *
     * WHEN NOTHING QUALIFIES THE FIRST CANDIDATE IS STILL USED, and that is not
     * a fallback in the sense EntityResolver forbids — the resolver's rule is
     * about READ time, where guessing a table means one tenant reading
     * another's rows. This is describe time, and the ERP tables need not be
     * reachable from the connection that writes the description: the seeder
     * runs against installations whose ERP lives in another schema, and the
     * suite runs with no ERP tables at all. If the table really is missing, the
     * resolver still fails closed on the first read, which is where the
     * question belongs.
     *
     * AN OPTIONAL ENTITY IS THE EXCEPTION. `Student` is the one entity a source
     * system may simply not have, so an unqualified candidate is dropped rather
     * than described — `has()` then answers false and the student surfaces stay
     * honestly empty instead of erroring.
     *
     * NOTHING IS NARROWED. A candidate's whole field list is written, including
     * the fields that particular deployment happens not to have a column for;
     * dropping them here would make the description depend on which connection
     * happened to run the seeder.
     *
     * @param  array<int, array{table: string, fields: array<string, string>, required: array<int, string>, optional: bool}>  $candidates
     * @return array{0: string, 1: array<string, string>}|null
     */
    private function chooseSource(array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if ($this->qualifies($candidate)) {
                return [$candidate['table'], $candidate['fields']];
            }
        }

        $first = $candidates[0] ?? null;

        if ($first === null || $first['optional']) {
            return null;
        }

        return [$first['table'], $first['fields']];
    }

    /**
     * @param  array{table: string, fields: array<string, string>, required: array<int, string>, optional: bool}  $candidate
     */
    private function qualifies(array $candidate): bool
    {
        if (! Schema::hasTable($candidate['table'])) {
            return false;
        }

        foreach ($candidate['required'] as $universalField) {
            $column = $candidate['fields'][$universalField] ?? null;

            if ($column === null || ! Schema::hasColumn($candidate['table'], $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tenant ids, as strings, from the ERP's own register of organizations.
     *
     * THE REGISTER IS WHICHEVER TABLE Organization RESOLVED TO, so this follows
     * the same candidate choice the mappings do rather than naming a second
     * table that could disagree with the first.
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
        if ($this->tenantIds !== null) {
            return array_values(array_map('strval', $this->tenantIds));
        }

        [$table, $fields] = $this->resolvedEntities()['Organization'] ?? [null, []];

        if ($table === null) {
            return [];
        }

        $idColumn = $fields['tenantKey'];

        // Soft deletion is honoured only where the register actually records
        // it. One ERP's organization table has a deleted_at column and the
        // other's does not, and filtering on a column that is not there would
        // fail the whole seed rather than seed every live organization.
        $deletedColumn = ($fields['deletedAt'] ?? null) !== null
            && Schema::hasColumn($table, $fields['deletedAt'])
                ? $fields['deletedAt']
                : null;

        return DB::table($table)
            ->when($deletedColumn !== null, fn ($query) => $query->whereNull($deletedColumn))
            ->distinct()
            ->orderBy($idColumn)
            ->pluck($idColumn)
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
        $existingRows = DB::table('hpbrain_entity_mappings')
            ->where('tenant_id', $tenantId)
            ->where('universal_entity', $universalEntity)
            ->where('universal_field', $universalField)
            ->where('is_active', 1)
            ->orderBy('created_date')
            ->get(['id']);

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

        if ($existingRows->isNotEmpty()) {
            $keeper = (string) $existingRows->first()->id;

            DB::table('hpbrain_entity_mappings')->where('id', $keeper)->update($values);

            $duplicates = $existingRows->skip(1)->pluck('id')->all();

            if ($duplicates !== []) {
                DB::table('hpbrain_entity_mappings')
                    ->whereIn('id', $duplicates)
                    ->update([
                        'is_active' => 0,
                        'updated_date' => $now,
                    ]);
            }

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

    /**
     * Remove active rows that belonged to a previous source shape for the same
     * universal entity. This is what lets the same seeder follow DB_DATABASE:
     * hp_erp can keep institute_detail/org_details, while development_erp can
     * move Organization onto school_setup without leaving both active.
     *
     * @param array<int, string> $universalFields
     */
    private function retireStaleMappings(
        string $tenantId,
        string $universalEntity,
        string $sourceTable,
        array $universalFields,
        string $now,
    ): void {
        DB::table('hpbrain_entity_mappings')
            ->where('tenant_id', $tenantId)
            ->where('universal_entity', $universalEntity)
            ->where('is_active', 1)
            ->where(function ($query) use ($sourceTable, $universalFields): void {
                $query->where('source_entity', '!=', $sourceTable)
                    ->orWhereNotIn('universal_field', $universalFields);
            })
            ->update([
                'is_active' => 0,
                'updated_date' => $now,
            ]);
    }
}
