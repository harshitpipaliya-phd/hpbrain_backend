<?php

declare(strict_types=1);

namespace Tests\Unit\Universal;

use App\Domain\Universal\EntityResolver;
use App\Domain\Universal\UnsupportedEntityException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The resolver's contract, with particular attention to the ways it must FAIL.
 *
 * The happy path here is small and the failure paths are large, deliberately.
 * A resolver that returns the wrong table is far more dangerous than one that
 * returns nothing: the wrong table still produces rows, and rows render.
 */
final class EntityResolverTest extends TestCase
{
    use BuildsBrainSchema;

    private const SCHOOL = 'school-tenant';

    private const HOSPITAL = 'hospital-tenant';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function resolver(): EntityResolver
    {
        return new EntityResolver();
    }

    /** @param array<string, string> $fields universal field => source column */
    private function map(string $tenantId, string $entity, string $table, array $fields, bool $active = true): void
    {
        foreach ($fields as $universalField => $sourceColumn) {
            DB::table('hpbrain_entity_mappings')->insert([
                'id'               => "{$tenantId}-{$entity}-{$universalField}",
                'tenant_id'        => $tenantId,
                'source_system'    => 'erp',
                'source_entity'    => $table,
                'source_field'     => $sourceColumn,
                'universal_entity' => $entity,
                'universal_field'  => $universalField,
                'mapping_type'     => 'direct',
                'is_active'        => $active,
                'created_by'       => 'test',
                'created_date'     => '2026-08-04 00:00:00',
                'updated_date'     => '2026-08-04 00:00:00',
            ]);
        }
    }

    private function mapSchoolPerson(): void
    {
        $this->map(self::SCHOOL, 'Person', 'tbluser', [
            'id'        => 'id',
            'tenantKey' => 'sub_institute_id',
            'firstName' => 'first_name',
            'email'     => 'email',
            'unit'      => 'department_id',
        ]);
    }

    // ---- resolution ------------------------------------------------------

    /** @test */
    public function resolves_an_entity_to_its_source_table(): void
    {
        $this->mapSchoolPerson();

        $source = $this->resolver()->resolve(self::SCHOOL, 'Person');

        $this->assertSame('tbluser', $source->table);
        $this->assertSame('sub_institute_id', $source->tenantKey);
        $this->assertSame('id', $source->primaryKey);
        $this->assertSame('first_name', $source->field('firstName'));
        $this->assertSame('department_id', $source->field('unit'));
        $this->assertSame('tbluser.email', $source->qualified('email'));
    }

    /** @test */
    public function multiple_fields_map_to_one_entity(): void
    {
        // The case the original unique key made impossible: five rows sharing
        // (tenant, source_system, source_entity). If the constraint regresses,
        // this insert fails rather than the assertion.
        $this->mapSchoolPerson();

        $this->assertCount(5, $this->resolver()->resolve(self::SCHOOL, 'Person')->universalFields());
    }

    /** @test */
    public function has_distinguishes_mapped_from_unmapped_fields(): void
    {
        $this->mapSchoolPerson();
        $source = $this->resolver()->resolve(self::SCHOOL, 'Person');

        $this->assertTrue($source->has('email'));
        // No column behind it in this ERP — absent, not empty, not false.
        $this->assertFalse($source->has('joinedDate'));
    }

    /** @test */
    public function columns_skips_unmapped_fields_rather_than_nulling_them(): void
    {
        $this->mapSchoolPerson();
        $source = $this->resolver()->resolve(self::SCHOOL, 'Person');

        $this->assertSame(
            ['firstName' => 'first_name', 'email' => 'email'],
            $source->columns(['firstName', 'lastName', 'email', 'phone']),
        );
    }

    // ---- failing closed --------------------------------------------------

    /** @test */
    public function an_unmapped_entity_throws_and_never_falls_back_to_tbluser(): void
    {
        // A hospital tenant with nothing mapped. The dangerous outcome is not an
        // exception — it is silently reading the school's employees.
        try {
            $this->resolver()->resolve(self::HOSPITAL, 'Person');
            $this->fail('Expected UnsupportedEntityException for an unmapped entity.');
        } catch (UnsupportedEntityException $e) {
            $this->assertSame(self::HOSPITAL, $e->tenantId);
            $this->assertSame('Person', $e->entity);
            $this->assertStringNotContainsString('tbluser', $e->getMessage());
        }
    }

    /** @test */
    public function one_tenants_mapping_does_not_resolve_for_another(): void
    {
        $this->mapSchoolPerson();

        $this->assertTrue($this->resolver()->has(self::SCHOOL, 'Person'));
        $this->assertFalse($this->resolver()->has(self::HOSPITAL, 'Person'));

        $this->expectException(UnsupportedEntityException::class);
        $this->resolver()->resolve(self::HOSPITAL, 'Person');
    }

    /** @test */
    public function an_unmapped_field_throws_rather_than_returning_null(): void
    {
        $this->mapSchoolPerson();
        $source = $this->resolver()->resolve(self::SCHOOL, 'Person');

        $this->expectException(UnsupportedEntityException::class);
        $this->expectExceptionMessageMatches('/joinedDate/');
        $source->field('joinedDate');
    }

    /** @test */
    public function inactive_mappings_are_ignored(): void
    {
        $this->map(self::SCHOOL, 'Person', 'tbluser', [
            'id' => 'id', 'tenantKey' => 'sub_institute_id',
        ], active: false);

        $this->assertFalse($this->resolver()->has(self::SCHOOL, 'Person'));
    }

    /** @test */
    public function an_entity_missing_its_id_binding_throws(): void
    {
        $this->map(self::SCHOOL, 'Person', 'tbluser', [
            'tenantKey' => 'sub_institute_id', 'email' => 'email',
        ]);

        $this->expectException(UnsupportedEntityException::class);
        $this->expectExceptionMessageMatches("/missing the required binding 'id'/");
        $this->resolver()->resolve(self::SCHOOL, 'Person');
    }

    /** @test */
    public function an_entity_missing_its_tenant_key_throws(): void
    {
        // The most important of these. Without a tenant key the resolver would
        // hand back a table with no way to scope reads to one tenant, and the
        // first query built from it would return every tenant's rows.
        $this->map(self::SCHOOL, 'Person', 'tbluser', [
            'id' => 'id', 'email' => 'email',
        ]);

        $this->expectException(UnsupportedEntityException::class);
        $this->expectExceptionMessageMatches("/missing the required binding 'tenantKey'/");
        $this->resolver()->resolve(self::SCHOOL, 'Person');
    }

    /** @test */
    public function two_source_tables_claiming_one_entity_is_ambiguous_and_throws(): void
    {
        $this->map(self::SCHOOL, 'Person', 'tbluser', [
            'id' => 'id', 'tenantKey' => 'sub_institute_id',
        ]);
        $this->map(self::SCHOOL, 'Person', 'hrms_staff', ['email' => 'email_address']);

        $this->expectException(UnsupportedEntityException::class);
        $this->expectExceptionMessageMatches('/more than one source table/');
        $this->resolver()->resolve(self::SCHOOL, 'Person');
    }

    // ---- caching ---------------------------------------------------------

    /** @test */
    public function mappings_are_cached_per_tenant_within_a_request(): void
    {
        $this->mapSchoolPerson();
        $resolver = $this->resolver();

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'hpbrain_entity_mappings')) {
                $queries++;
            }
        });

        $resolver->resolve(self::SCHOOL, 'Person');
        $resolver->resolve(self::SCHOOL, 'Person');
        $resolver->has(self::SCHOOL, 'Organization');

        $this->assertSame(1, $queries, 'Resolving repeatedly should load a tenant once.');
    }

    /** @test */
    public function each_tenant_costs_its_own_load(): void
    {
        $this->mapSchoolPerson();
        $this->map(self::HOSPITAL, 'Person', 'hospital_staff', [
            'id' => 'staff_id', 'tenantKey' => 'org_id',
        ]);

        $resolver = $this->resolver();

        $this->assertSame('tbluser', $resolver->resolve(self::SCHOOL, 'Person')->table);
        $this->assertSame('hospital_staff', $resolver->resolve(self::HOSPITAL, 'Person')->table);
        $this->assertSame('org_id', $resolver->resolve(self::HOSPITAL, 'Person')->tenantKey);
    }

    /** @test */
    public function flush_makes_a_new_mapping_visible_within_the_same_request(): void
    {
        $resolver = $this->resolver();
        $this->assertFalse($resolver->has(self::SCHOOL, 'Person'));

        $this->mapSchoolPerson();
        $this->assertFalse($resolver->has(self::SCHOOL, 'Person'), 'Cached miss should persist until flushed.');

        $resolver->flush(self::SCHOOL);
        $this->assertTrue($resolver->has(self::SCHOOL, 'Person'));
    }

    /** @test */
    public function the_container_binding_is_scoped_not_shared_across_requests(): void
    {
        // scoped() resolves to the same instance within a request — the cache
        // would be pointless otherwise.
        $this->assertSame(app(EntityResolver::class), app(EntityResolver::class));
    }

    // ---- mapping types ---------------------------------------------------

    /** @test */
    public function transform_expressions_are_decoded_as_json_not_treated_as_sql(): void
    {
        DB::table('hpbrain_entity_mappings')->insert([
            'id'                   => 'm-transform',
            'tenant_id'            => self::SCHOOL,
            'source_system'        => 'erp',
            'source_entity'        => 'tbluser',
            'source_field'         => 'first_name',
            'universal_entity'     => 'Person',
            'universal_field'      => 'fullName',
            'mapping_type'         => 'transform',
            'transform_expression' => '{"op":"concat","fields":["first_name","last_name"],"separator":" "}',
            'is_active'            => true,
            'created_by'           => 'test',
            'created_date'         => '2026-08-04 00:00:00',
            'updated_date'         => '2026-08-04 00:00:00',
        ]);
        $this->map(self::SCHOOL, 'Person', 'tbluser', ['id' => 'id', 'tenantKey' => 'sub_institute_id']);

        $mapping = $this->resolver()->resolve(self::SCHOOL, 'Person')->mapping('fullName');

        $this->assertSame('transform', $mapping['type']);
        $this->assertIsArray($mapping['expression']);
        $this->assertSame('concat', $mapping['expression']['op']);
        $this->assertFalse($this->resolver()->resolve(self::SCHOOL, 'Person')->isDirect('fullName'));
    }

    /** @test */
    public function lookup_mappings_carry_their_lookup_table(): void
    {
        DB::table('hpbrain_entity_mappings')->insert([
            'id'               => 'm-lookup',
            'tenant_id'        => self::SCHOOL,
            'source_system'    => 'erp',
            'source_entity'    => 'tbluser',
            'source_field'     => 'jobtitle_id',
            'universal_entity' => 'Person',
            'universal_field'  => 'position',
            'mapping_type'     => 'lookup',
            'lookup_table'     => 'hrms_job_titles',
            'is_active'        => true,
            'created_by'       => 'test',
            'created_date'     => '2026-08-04 00:00:00',
            'updated_date'     => '2026-08-04 00:00:00',
        ]);
        $this->map(self::SCHOOL, 'Person', 'tbluser', ['id' => 'id', 'tenantKey' => 'sub_institute_id']);

        $mapping = $this->resolver()->resolve(self::SCHOOL, 'Person')->mapping('position');

        $this->assertSame('lookup', $mapping['type']);
        $this->assertSame('hrms_job_titles', $mapping['lookupTable']);
        $this->assertSame('jobtitle_id', $mapping['column']);
    }

    /** @test */
    public function an_unparseable_transform_expression_is_preserved_not_nulled(): void
    {
        DB::table('hpbrain_entity_mappings')->insert([
            'id'                   => 'm-bad',
            'tenant_id'            => self::SCHOOL,
            'source_system'        => 'erp',
            'source_entity'        => 'tbluser',
            'source_field'         => 'first_name',
            'universal_entity'     => 'Person',
            'universal_field'      => 'oddity',
            'mapping_type'         => 'transform',
            'transform_expression' => 'UPPER(first_name)',
            'is_active'            => true,
            'created_by'           => 'test',
            'created_date'         => '2026-08-04 00:00:00',
            'updated_date'         => '2026-08-04 00:00:00',
        ]);
        $this->map(self::SCHOOL, 'Person', 'tbluser', ['id' => 'id', 'tenantKey' => 'sub_institute_id']);

        // Kept verbatim so the misconfiguration is visible. It is NOT executed:
        // the resolver hands back a value, never a fragment of a query.
        $this->assertSame(
            'UPPER(first_name)',
            $this->resolver()->resolve(self::SCHOOL, 'Person')->mapping('oddity')['expression'],
        );
    }

    /**
     * A database with no mappings TABLE is a different failure from a tenant with
     * no mapping ROWS, and has to read like one.
     *
     * This is not hypothetical. The live ERP database carries an older Brain
     * schema and has never had these migrations applied, so the first real
     * request against it failed with a bare 'Base table or view not found:
     * hp_erp.hpbrain_entity_mappings' — accurate, and useless. It names a table
     * but not the reason, and it arrives from login, which makes a skipped
     * deployment step look like the application is broken.
     *
     * @test
     */
    public function a_missing_mappings_table_says_the_schema_was_never_migrated(): void
    {
        $this->mapSchoolPerson();
        DB::statement('DROP TABLE hpbrain_entity_mappings');

        foreach ([
            'resolve'         => fn () => $this->resolver()->resolve(self::SCHOOL, 'Person'),
            'everyTenantWith' => fn () => $this->resolver()->everyTenantWith('Person'),
        ] as $entryPoint => $call) {
            try {
                $call();
                $this->fail("{$entryPoint}() returned instead of throwing with the table absent.");
            } catch (UnsupportedEntityException $e) {
                $this->assertStringContainsString('does not exist', $e->getMessage(), $entryPoint);
                $this->assertStringContainsString('php artisan migrate', $e->getMessage(), $entryPoint);

                // The original driver error is kept, so the SQLSTATE is still in
                // the log for anyone who needs to see it.
                $this->assertNotNull($e->getPrevious(), $entryPoint);
            }
        }
    }

    /**
     * Only a missing table is reinterpreted.
     *
     * A tenant that simply has no rows must still get the ordinary "not mapped"
     * error, or a configuration mistake would be reported as a missing migration
     * and send whoever reads it to run a deployment step that will not help.
     *
     * @test
     */
    public function an_empty_table_is_not_reported_as_a_missing_one(): void
    {
        $this->expectException(UnsupportedEntityException::class);
        $this->expectExceptionMessage('No active entity mapping');

        $this->resolver()->resolve(self::SCHOOL, 'Person');
    }
}
