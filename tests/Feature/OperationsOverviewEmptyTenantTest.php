<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\Support\SeedsEntityMappings;
use Tests\TestCase;

/**
 * The Command Center overview for an organization that has imported nothing.
 *
 * Found by the browser walkthrough: the empty-state `totals` omitted `largestDataset`, which
 * the controller reads unconditionally, so the home screen's first request was a 500 for
 * every tenant before its first import. An empty organization is the first thing a new
 * customer sees, so it has its own test.
 */
final class OperationsOverviewEmptyTenantTest extends TestCase
{
    use BuildsBrainSchema;
    use BuildsErpFixture;
    use SeedsEntityMappings;

    protected function setUp(): void
    {
        parent::setUp();
        // The engine caches its result in the FILE store keyed on a data fingerprint, which
        // survives between runs; without this a previous run's answer would be served.
        Cache::store('file')->flush();
        $this->buildErpSchema();
        $this->seedErpFixture(4);
        $this->buildBrainSchema();
        $this->installEntityMappings(['4']);
    }

    /** @test */
    public function an_organization_with_no_operational_records_gets_a_200_not_a_500(): void
    {
        $token = Jwt::issueAccess(['id' => 'u-1', 'tenantId' => '4', 'role' => 'viewer']);

        $response = $this->getJson('/api/v1/operations/4/overview', ['Authorization' => "Bearer {$token}"]);

        $response->assertOk();
        $this->assertSame(0, $response->json('headline.operationalRecords.value'));
        $this->assertSame('no dataset ingested', $response->json('headline.datasets.detail'));
    }
}
