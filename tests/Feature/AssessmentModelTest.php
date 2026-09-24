<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kasba\AssessmentModel;
use App\Domain\Kasba\AssessmentModelResolver;
use App\Support\Jwt;
use Database\Seeders\EntityMappingSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\BuildsErpFixture;
use Tests\TestCase;

/**
 * Phase 4's gate: the assessment dimensions come from the tenant's industry.
 *
 * KASBA is a model of HUMAN capability. Five words that fit a nurse do not fit a
 * dialysis machine, whose dimensions are closer to Availability, Performance,
 * Quality and Compliance. Scoring an asset's "attitude" produces a number that
 * looks meaningful and is not — which is the failure this whole architecture
 * exists to prevent.
 *
 * The load-bearing assertion here is that a four-dimension tenant gets four
 * everywhere with NO frontend change, because the dimension list travels in the
 * response rather than living as a constant in the SPA.
 */
final class AssessmentModelTest extends TestCase
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
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    /** @param array<string, mixed> $model */
    private function template(string $industry, ?array $model, string $tenant = 'platform'): void
    {
        DB::table('hpbrain_industry_templates')->insert([
            'id'               => 'tpl-'.$tenant.'-'.$industry,
            'tenant_id'        => $tenant,
            'industry_code'    => $industry,
            'template_name'    => ucfirst($industry),
            'assessment_model' => $model === null ? null : json_encode($model),
            'is_active'        => true,
            'created_by'       => 'test',
            'created_date'     => '2026-08-04 00:00:00',
            'updated_date'     => '2026-08-04 00:00:00',
        ]);
    }

    private function resolver(): AssessmentModelResolver
    {
        return app(AssessmentModelResolver::class);
    }

    // ---- the school tenant is unchanged ----------------------------------

    /** @test */
    public function without_a_template_the_config_model_applies_unchanged(): void
    {
        $model = $this->resolver()->forTenant(self::TENANT);

        $this->assertSame(
            ['knowledge', 'ability', 'skill', 'behaviour', 'attitude'],
            $model->dimensions,
        );
        $this->assertSame(5, $model->maxLevel);
        // Surfaced so a screen can say whether an industry declared a model or
        // is on the default — a real difference an administrator should see.
        $this->assertSame('config', $model->origin);
    }

    /** @test */
    public function a_template_without_an_assessment_model_still_falls_back(): void
    {
        $this->template('Healthcare', null);

        $this->assertSame('config', $this->resolver()->forTenant(self::TENANT)->origin);
    }

    // ---- a different industry gets different dimensions ------------------

    /** @test */
    public function an_industry_template_replaces_the_dimension_list(): void
    {
        // The fixture organization's industry_type is 'Healthcare'.
        $this->template('Healthcare', [
            'dimensions'            => ['availability', 'performance', 'quality', 'compliance'],
            'maxLevel'              => 4,
            'assessableEntityTypes' => ['Person', 'Asset'],
        ]);

        $model = $this->resolver()->forTenant(self::TENANT);

        $this->assertSame(['availability', 'performance', 'quality', 'compliance'], $model->dimensions);
        $this->assertSame(4, $model->maxLevel);
        $this->assertSame('template', $model->origin);
        $this->assertTrue($model->assesses('Asset'));
        $this->assertFalse($model->assesses('OrganizationUnit'));
    }

    /** @test */
    public function a_tenant_template_overrides_the_platform_one(): void
    {
        $this->template('Healthcare', ['dimensions' => ['a', 'b']], 'platform');
        $this->template('Healthcare', ['dimensions' => ['x', 'y', 'z']], self::TENANT);

        $this->assertSame(['x', 'y', 'z'], $this->resolver()->forTenant(self::TENANT)->dimensions);
    }

    /** @test */
    public function a_template_for_another_industry_is_ignored(): void
    {
        $this->template('manufacturing', ['dimensions' => ['availability', 'performance']]);

        $this->assertSame('config', $this->resolver()->forTenant(self::TENANT)->origin);
    }

    // ---- the frontend needs no change ------------------------------------

    /** @test */
    public function a_four_dimension_tenant_gets_four_columns_from_the_heatmap(): void
    {
        // The gate. The dimension list travels in the response, so the SPA
        // renders four radar axes and four heatmap columns without knowing what
        // KASBA is.
        $this->template('Healthcare', [
            'dimensions' => ['availability', 'performance', 'quality', 'compliance'],
            'maxLevel'   => 4,
        ]);

        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/kasba/heatmap/'.self::TENANT)
            ->assertStatus(200)
            ->json();

        $this->assertSame(
            ['availability', 'performance', 'quality', 'compliance'],
            array_keys($body['dimensions']),
        );

        $this->assertSame(
            ['availability', 'performance', 'quality', 'compliance'],
            $body['model']['dimensions'],
        );
        $this->assertSame(4, $body['model']['maxLevel']);
        $this->assertSame('template', $body['model']['origin']);
    }

    /** @test */
    public function the_school_heatmap_still_reports_five(): void
    {
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/kasba/heatmap/'.self::TENANT)
            ->assertStatus(200)
            ->json();

        $this->assertSame(
            ['knowledge', 'ability', 'skill', 'behaviour', 'attitude'],
            array_keys($body['dimensions']),
        );
    }

    /** @test */
    public function an_unassessed_dimension_reports_null_not_zero(): void
    {
        // The rule that must never be softened. Zero asserts a measurement was
        // taken and found nothing; null says nothing was measured.
        $body = $this->withHeaders($this->auth())
            ->getJson('/api/v1/kasba/heatmap/'.self::TENANT)
            ->assertStatus(200)
            ->json();

        foreach ($body['dimensions'] as $dimension => $summary) {
            $this->assertSame(0, $summary['assessed'], $dimension);
            $this->assertNull($summary['average'], "{$dimension} average must be null, not 0.");
        }
    }

    // ---- the model refuses what it cannot render honestly -----------------

    /** @test */
    public function an_empty_dimension_list_is_refused(): void
    {
        // An empty model scores everything null and would read as a measurement
        // failure rather than a configuration one.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one dimension/');
        AssessmentModel::fromArray(['dimensions' => []], 'template');
    }

    /** @test */
    public function a_dimension_name_that_could_reach_a_query_is_refused(): void
    {
        // Dimension names become column prefixes ("{$d}_level"), so anything
        // outside a strict identifier shape is refused before it gets there.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/lower-case alphanumeric/');
        AssessmentModel::fromArray(['dimensions' => ['skill; DROP TABLE x']], 'template');
    }

    /** @test */
    public function level_columns_follow_the_dimensions(): void
    {
        $model = AssessmentModel::fromArray(
            ['dimensions' => ['availability', 'performance']],
            'template',
        );

        $this->assertSame(['availability_level', 'performance_level'], $model->levelColumns());
    }
}
