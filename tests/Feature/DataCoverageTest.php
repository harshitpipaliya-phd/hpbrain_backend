<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Universal\EntityResolver;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * GET operations/{tenantId}/coverage — five separate, honestly-scoped
 * answers to "how much of this tenant's data does the Brain actually see".
 *
 * WHAT THIS PINS.
 *
 *   THE FIVE AXES NEVER COLLAPSE INTO ONE NUMBER. Source discovery, record
 *   processing, field completeness, data quality and freshness each carry
 *   their own supported/reason pair and their own percentage.
 *
 *   AN UNMAPPED ENTITY IS NAMED, NOT HIDDEN. This tenant's fixture leaves
 *   Student unmapped; the coverage report must say so by name.
 *
 *   NOTHING IS CLAIMED AS 100% WHEN A SOURCE HAS NEVER SYNCED, an import job
 *   has never run, or no operational record exists — each of those states
 *   is `supported: false` with a reason, never a fabricated percentage.
 */
final class DataCoverageTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;

    private const TENANT = '4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->buildErpSchema();
        $this->seedErpFixture();
        (new EntityMappingSeeder())->run();
        Cache::store('file')->flush();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    private function fetch(): array
    {
        return $this->withHeaders($this->auth())
            ->getJson('/api/v1/operations/'.self::TENANT.'/coverage')
            ->assertOk()
            ->json();
    }

    /** @param array<string, mixed> $overrides */
    private function record(string $dataset, array $overrides = []): void
    {
        static $n = 0;
        $n++;

        DB::table('hpbrain_operational_records')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => self::TENANT,
            'org_id' => self::TENANT,
            'dataset' => $dataset,
            'natural_key' => $dataset.'-'.$n,
            'row_hash' => str_repeat('0', 64),
            'occurred_at' => '2026-06-01 09:00:00',
            'created_date' => '2026-06-01 09:00:00',
            'updated_date' => '2026-06-01 09:00:00',
        ], $overrides));
    }

    public function test_an_organization_with_nothing_connected_reports_gaps_not_fabricated_coverage(): void
    {
        $body = $this->fetch();

        self::assertFalse($body['recordProcessing']['supported']);
        self::assertNotEmpty($body['recordProcessing']['reason']);
        self::assertFalse($body['fieldCompleteness']['supported']);
        self::assertFalse($body['dataQuality']['supported']);
        self::assertSame([], $body['sourceDiscovery']['configuredSources']);
    }

    public function test_source_discovery_names_the_unmapped_entity_rather_than_hiding_it(): void
    {
        $body = $this->fetch();

        self::assertContains('Student', $body['sourceDiscovery']['universalEntities']['unmappedEntities']);
        self::assertLessThan(
            count(EntityResolver::ENTITIES),
            $body['sourceDiscovery']['universalEntities']['mapped'],
        );
        self::assertLessThan(100.0, $body['sourceDiscovery']['universalEntities']['coveragePct']);
    }

    public function test_field_completeness_and_record_processing_are_reported_separately(): void
    {
        $this->record('job_order', [
            'status' => 'Completed', 'occurred_at' => '2026-06-01 09:00:00', 'closed_at' => '2026-06-01 12:00:00',
            'category' => 'Category A', 'department_label' => 'Field Operations', 'subject_ref' => 'subject-1',
        ]);
        $this->record('job_order', [
            'status' => 'Open', 'occurred_at' => '2026-06-02 09:00:00',
            'category' => 'Category A', 'department_label' => 'Field Operations', 'subject_ref' => 'subject-2',
        ]);

        DB::table('hpbrain_import_jobs')->insert([
            'id' => 'job-1', 'tenant_id' => self::TENANT, 'import_type' => 'csv', 'entity_type' => 'OperationalRecord',
            'status' => 'completed_with_errors', 'total_rows' => 10, 'processed_rows' => 8,
            'success_count' => 6, 'error_count' => 1, 'duplicate_count' => 1,
            'error_report' => json_encode([['message' => 'row 7: missing subject reference']]),
            'started_by' => 'test', 'completed_date' => '2026-06-01 10:00:00', 'created_date' => '2026-06-01 09:00:00',
        ]);

        $body = $this->fetch();

        // Record processing: submitted vs actually processed.
        self::assertTrue($body['recordProcessing']['supported']);
        self::assertSame(10, $body['recordProcessing']['totalRowsSubmitted']);
        self::assertSame(8, $body['recordProcessing']['rowsProcessed']);
        self::assertEquals(80.0, $body['recordProcessing']['processingCoveragePct']);
        self::assertSame('2026-06-01 10:00:00', $body['recordProcessing']['lastSuccessfulImportAt']);
        self::assertNotEmpty($body['recordProcessing']['exclusionSamples']);

        // Data quality: of what was processed, how much was clean.
        self::assertTrue($body['dataQuality']['supported']);
        self::assertEquals(75.0, $body['dataQuality']['cleanRatePct']); // 6 of 8
        self::assertEquals(12.5, $body['dataQuality']['errorRatePct']); // 1 of 8
        self::assertEquals(12.5, $body['dataQuality']['duplicateRatePct']); // 1 of 8

        // Field completeness: an entirely separate measure from either of the above.
        self::assertTrue($body['fieldCompleteness']['supported']);
        $dataset = collect($body['fieldCompleteness']['datasets'])->firstWhere('dataset', 'job_order');
        self::assertNotNull($dataset);
        self::assertSame(2, $dataset['records']);
        self::assertGreaterThan(0, $dataset['fieldsPresent']);
        self::assertContains('supervisor', $dataset['missingFields'], 'No supervisor column was ever populated in this fixture.');
    }

    public function test_a_stale_source_is_flagged_by_name(): void
    {
        DB::table('hpbrain_data_sources')->insert([
            'id' => 'src-1', 'tenant_id' => self::TENANT, 'source_key' => 'erp.hrms',
            'source_type' => 'database', 'display_name' => 'HRMS', 'is_active' => 1,
            'last_synced_at' => now()->subDays(30)->format('Y-m-d H:i:s'),
            'created_by' => 'test', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $body = $this->fetch();

        $source = collect($body['freshness']['configuredSources'])->firstWhere('sourceKey', 'erp.hrms');

        self::assertNotNull($source);
        self::assertTrue($source['isStale']);
        self::assertGreaterThanOrEqual(30, $source['daysSinceLastSync']);
    }

    public function test_a_recently_synced_source_is_not_flagged_stale(): void
    {
        DB::table('hpbrain_data_sources')->insert([
            'id' => 'src-2', 'tenant_id' => self::TENANT, 'source_key' => 'erp.hrms',
            'source_type' => 'database', 'display_name' => 'HRMS', 'is_active' => 1,
            'last_synced_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
            'created_by' => 'test', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $body = $this->fetch();

        $source = collect($body['freshness']['configuredSources'])->firstWhere('sourceKey', 'erp.hrms');

        self::assertFalse($source['isStale']);
    }

    public function test_coverage_for_one_tenant_never_reads_another_tenants_import_jobs_or_sources(): void
    {
        DB::table('hpbrain_import_jobs')->insert([
            'id' => 'job-foreign', 'tenant_id' => '9999', 'import_type' => 'csv', 'entity_type' => 'OperationalRecord',
            'status' => 'completed', 'total_rows' => 500, 'processed_rows' => 500, 'success_count' => 500,
            'started_by' => 'test', 'created_date' => '2026-06-01 09:00:00',
        ]);
        DB::table('hpbrain_data_sources')->insert([
            'id' => 'src-foreign', 'tenant_id' => '9999', 'source_key' => 'foreign.erp',
            'source_type' => 'database', 'display_name' => 'Foreign', 'is_active' => 1,
            'created_by' => 'test', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $body = $this->fetch();

        self::assertFalse($body['recordProcessing']['supported'], 'No import job of this tenant exists.');
        self::assertSame([], $body['sourceDiscovery']['configuredSources']);
    }

    public function test_the_endpoint_refuses_another_tenants_path(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/operations/9999/coverage')
            ->assertStatus(403)
            ->assertJson(['error' => 'tenant_mismatch']);
    }
}
