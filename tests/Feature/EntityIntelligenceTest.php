<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Intelligence\IntelligenceOutputContract;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * GET entity-intelligence/{tenantId}/{entityType}/{entityId} — the one door
 * into whatever intelligence an entity actually has.
 *
 * WHAT THIS PINS.
 *
 *   A DEPARTMENT AND A PERSON GET THE REAL COMPOSER'S OUTPUT, VERBATIM. This
 *   endpoint must not reshape or summarise DepartmentVerdict's or
 *   PersonIntelligenceService's payload — the response's `intelligence` key
 *   is byte-identical to what the entity's own dedicated endpoint returns.
 *
 *   'Department' AND 'OrganizationUnit' REACH THE SAME COMPOSER. The UI's
 *   name for the type and the resolver's universal name are not two
 *   features, they are one endpoint reachable by two spellings.
 *
 *   A TYPE WITH NO COMPOSER IS NOT A 404. Position is mapped for this
 *   tenant but has no DepartmentVerdict-style engine — the endpoint answers
 *   200 with `intelligenceAvailable: false` and the real facts ContextEngine
 *   can read, never a dead end and never an invented score.
 *
 *   A TYPE THIS TENANT DOES NOT MAP IS AN HONEST 404. Student has no ERP
 *   table in this healthcare fixture, and the response says so with
 *   ContextEngine's own reason token, not a second error vocabulary.
 *
 *   AN UNKNOWN TYPE STRING IS A 422, NOT A SILENT EMPTY ANSWER.
 */
final class EntityIntelligenceTest extends TestCase
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

    private function fetch(string $path): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->auth())->getJson($path);
    }

    public function test_a_department_gets_the_real_composers_output_verbatim(): void
    {
        $direct = $this->fetch('/api/v1/departments/'.self::TENANT.'/2/intelligence')->assertOk()->json();

        $composed = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Department/2')
            ->assertOk()
            ->json();

        self::assertSame('OrganizationUnit', $composed['entityType']);
        self::assertSame('2', $composed['entityId']);
        self::assertSame('department', $composed['provider']);
        self::assertTrue($composed['intelligenceAvailable']);
        self::assertSame($direct, $composed['intelligence']);
    }

    public function test_the_universal_name_and_the_ui_alias_reach_the_same_composer(): void
    {
        $viaAlias = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Department/2')->assertOk()->json();
        $viaUniversal = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/OrganizationUnit/2')->assertOk()->json();

        self::assertSame($viaAlias, $viaUniversal);
    }

    public function test_a_person_gets_the_real_composers_output_verbatim(): void
    {
        $direct = $this->fetch('/api/v1/people/'.self::TENANT.'/2/intelligence')->assertOk()->json();

        $composed = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Person/2')
            ->assertOk()
            ->json();

        self::assertSame('Person', $composed['entityType']);
        self::assertSame('person', $composed['provider']);
        self::assertTrue($composed['intelligenceAvailable']);
        self::assertSame($direct, $composed['intelligence']);
    }

    public function test_a_mapped_type_with_no_composer_answers_with_real_facts_not_a_dead_end(): void
    {
        // Position (hrms_job_titles, id 1) is mapped for this tenant but has no
        // DepartmentVerdict-style engine.
        $body = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Position/1')
            ->assertOk()
            ->json();

        self::assertSame('Position', $body['entityType']);
        self::assertSame('context-only', $body['provider']);
        self::assertFalse($body['intelligenceAvailable']);
        self::assertNotEmpty($body['reason']);
        self::assertTrue($body['facts']['present']);
        self::assertArrayHasKey('signals', $body);
    }

    public function test_a_type_this_tenant_does_not_map_is_an_honest_404_not_a_manufactured_answer(): void
    {
        // This fixture is a healthcare ERP with no student table — Student is a
        // real universal entity, just not one this tenant has.
        $response = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Student/1')
            ->assertStatus(404)
            ->json();

        self::assertSame('entity_not_found', $response['error']);
        self::assertSame('entity_not_mapped_for_tenant', $response['reason']);
    }

    public function test_an_unrecognised_type_string_is_rejected_not_silently_emptied(): void
    {
        $response = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Signal/1')
            ->assertStatus(422)
            ->json();

        self::assertSame('unknown_entity_type', $response['error']);
        self::assertContains('Person', $response['supported']);
    }

    public function test_a_department_of_another_tenant_is_not_found_not_borrowed(): void
    {
        $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Department/999')
            ->assertStatus(404)
            ->assertJson(['error' => 'entity_not_found']);
    }

    /* ─────────────────── Phase B: the shared output contract ─────────────────── */

    public function test_a_department_composed_response_carries_a_valid_summary_alongside_the_verbatim_intelligence(): void
    {
        $body = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Department/2')->assertOk()->json();

        self::assertArrayHasKey('summary', $body, 'summary must be additive, never replacing intelligence.');
        self::assertTrue($body['summary']['valid'], 'A real composer payload must project to a schema-valid contract: '.json_encode($body['summary']['errors']));
        self::assertSame([], $body['summary']['errors']);

        $contract = $body['summary']['contract'];
        self::assertSame('OrganizationUnit', $contract['context']['type']);
        self::assertSame('2', $contract['context']['id']);
        self::assertNotEmpty($contract['title']);
        self::assertNotEmpty($contract['executiveSummary']);

        foreach ($contract['findings'] as $finding) {
            self::assertTrue(
                IntelligenceOutputContract::isValidFindingType($finding['type']),
                'Every projected finding type must be in the shared taxonomy: '.$finding['type'],
            );
        }
    }

    public function test_a_person_composed_response_carries_a_valid_summary(): void
    {
        $body = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Person/2')->assertOk()->json();

        self::assertArrayHasKey('summary', $body);
        self::assertTrue($body['summary']['valid'], json_encode($body['summary']['errors']));

        $contract = $body['summary']['contract'];
        self::assertSame('Person', $contract['context']['type']);
        self::assertSame('2', $contract['context']['id']);
    }

    /**
     * A signal against this department must reach the projected contract as a
     * finding carrying a real, checkable reference back to the signal row —
     * not a free-text restatement with nothing behind it.
     */
    public function test_a_signal_against_the_department_becomes_an_evidence_grounded_finding(): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => 'sig-dept-2', 'tenant_id' => self::TENANT, 'source' => 'erp.hrms_departments',
            'classification' => 'backlog', 'priority' => 'high', 'severity' => 'critical',
            'confidence' => 0.9, 'status' => 'new', 'department_id' => '2',
            'created_by' => 'system', 'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $body = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Department/2')->assertOk()->json();

        $riskFindings = array_values(array_filter(
            $body['summary']['contract']['findings'],
            fn (array $f): bool => $f['type'] === 'risk' && $f['evidence'] !== [],
        ));

        self::assertNotEmpty($riskFindings, 'An open critical signal must project to a risk finding with an evidence reference.');
        self::assertSame('signal', $riskFindings[0]['evidence'][0]['type']);
        self::assertSame('sig-dept-2', $riskFindings[0]['evidence'][0]['id']);
        self::assertSame(0.9, $riskFindings[0]['confidence']);
    }

    /**
     * A dimension the connected data cannot measure must surface as a
     * limitation, never as a finding asserting something about it.
     */
    public function test_unmeasurable_dimensions_become_limitations_not_findings(): void
    {
        $body = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Department/2')->assertOk()->json();
        $contract = $body['summary']['contract'];

        self::assertNotEmpty($contract['limitations'], 'This fixture has unmeasurable dimensions; the contract must say so.');

        $limitationDimensions = array_column($contract['limitations'], 'dimension');
        $findingStatements = array_column($contract['findings'], 'statement');

        foreach ($limitationDimensions as $dimension) {
            foreach ($findingStatements as $statement) {
                self::assertStringNotContainsString(
                    (string) $dimension,
                    (string) $statement,
                    'A dimension listed as unmeasurable must not also be asserted as a finding.'
                );
            }
        }
    }

    /**
     * The three intelligence contexts disagree on almost everything about how
     * they compute a score — this is the property that ties them together:
     * the same envelope shape, so a fourth screen can consume any of them
     * identically.
     */
    public function test_department_and_person_summaries_share_the_same_envelope_shape(): void
    {
        $department = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Department/2')
            ->assertOk()->json('summary.contract');
        $person = $this->fetch('/api/v1/entity-intelligence/'.self::TENANT.'/Person/2')
            ->assertOk()->json('summary.contract');

        $requiredKeys = ['title', 'executiveSummary', 'context', 'findings', 'dataCoverage', 'limitations', 'recommendations'];

        foreach ($requiredKeys as $key) {
            self::assertArrayHasKey($key, $department, "department contract missing {$key}");
            self::assertArrayHasKey($key, $person, "person contract missing {$key}");
        }
    }
}
