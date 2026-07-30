<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

final class EsoExecutionTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-alpha';
    private const ACTOR  = 'user-manager';

    private string $decisionId;
    private string $esoId;
    private string $executionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();

        if (! Schema::hasTable('institute_detail')) {
            Schema::create('institute_detail', function ($t) {
                $t->string('sub_institute_id');
                $t->timestamp('deleted_at')->nullable();
            });
        }

        DB::table('institute_detail')->insert(['sub_institute_id' => self::TENANT]);

        $this->decisionId = Uuid::uuid4()->toString();
        $this->esoId      = Uuid::uuid4()->toString();

        DB::table('hpbrain_decisions')->insert([
            'id' => $this->decisionId, 'tenant_id' => self::TENANT,
            'decided_by' => 'user-analyst', 'rationale' => 'Cadence change.',
            'status' => 'approved', 'created_date' => '2026-07-20 09:00:00',
        ]);

        DB::table('hpbrain_eso_definitions')->insert([
            'id' => $this->esoId, 'tenant_id' => self::TENANT,
            'eso_code' => 'ESO-FEE-REMIND', 'name' => 'Targeted fee reminder',
        ]);

        DB::table('hpbrain_measurement_plans')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT,
            'decision_id' => $this->decisionId, 'baseline_metric' => 'collection rate',
            'created_by' => self::ACTOR, 'created_date' => '2026-07-21 09:00:00',
        ]);

        $this->executionId = $this->postJson('/api/v1/eso-executions', [
            'decisionId'      => $this->decisionId,
            'esoDefinitionId' => $this->esoId,
            'executorType'    => 'human',
        ], $this->auth())->json('id');
    }

    private function auth(string $role = 'manager'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ACTOR, 'tenantId' => self::TENANT, 'role' => $role,
        ])];
    }

    public function test_rollback_records_the_reason_and_marks_the_execution_rolled_back(): void
    {
        $url = '/api/v1/eso-executions/'.self::TENANT.'/'.$this->executionId.'/rollback';
        $response = $this->postJson($url, [
            'reason' => 'ERP rejected the reminder batch.',
        ], $this->auth());

        $response->assertStatus(200);

        $response->assertJson(['status' => 'rolled_back']);

        $row = DB::table('hpbrain_eso_executions')->where('id', $this->executionId)->first();
        self::assertSame('rolled_back', $row->status);
        self::assertSame('ERP rejected the reminder batch.', $row->rollback_reason);
    }

    public function test_rollback_is_refused_without_a_reason(): void
    {
        $this->postJson('/api/v1/eso-executions/'.self::TENANT.'/'.$this->executionId.'/rollback', [], $this->auth())
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }
}
