<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Regression for CaseController::attachEvidence(): the insert into
 * hpbrain_case_evidence used to run with no check that the case named in the
 * URL, or the evidence id in the body, actually belonged to the caller's
 * tenant. A caller could attach an evidence id lifted from another tenant to
 * one of their own cases, or attach evidence to a case id that was never
 * theirs — the join row's tenant_id column was set from the caller's own
 * tenant regardless, masking that neither side of the link was verified.
 */
final class CaseAttachEvidenceOwnershipTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-alpha';

    private const OTHER = 'tenant-beta';

    private const ACTOR = 'test-actor';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function signal(string $tenantId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'source' => 'erp.data_quality',
            'classification' => 'leadership', 'priority' => 'medium', 'severity' => 'medium',
            'confidence' => 1.0, 'status' => 'new', 'created_by' => 'system',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function evidence(string $tenantId, string $signalId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_evidence')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'signal_id' => $signalId,
            'source' => 'erp.hrms_departments', 'evidence_type' => 'observation',
            'content' => json_encode(['issue' => 'x']),
            'provenance' => json_encode(['source' => 'erp', 'ts' => '2026-08-01T00:00:00Z']),
            'confidence' => 0.9, 'hash' => hash('sha256', $id), 'status' => 'active',
            'created_by' => 'system', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function openCase(string $tenantId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_cases')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'signal_id' => null,
            'title' => 'A case', 'status' => 'open', 'created_by' => 'test',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** @return array<string, string> */
    private function auth(string $tenantId, string $role = 'analyst'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-'.$tenantId, 'tenantId' => $tenantId, 'role' => $role,
        ])];
    }

    public function test_evidence_from_another_tenant_cannot_be_attached_to_my_case(): void
    {
        $myCase = $this->openCase(self::TENANT);
        $foreignEvidence = $this->evidence(self::OTHER, $this->signal(self::OTHER));

        $this->postJson(
            '/api/v1/cases/'.self::TENANT.'/'.$myCase.'/evidence',
            ['evidenceId' => $foreignEvidence],
            $this->auth(self::TENANT)
        )->assertStatus(404)->assertJson(['error' => 'evidence_not_found']);

        self::assertDatabaseMissing('hpbrain_case_evidence', [
            'case_id' => $myCase, 'evidence_id' => $foreignEvidence,
        ]);
    }

    public function test_evidence_cannot_be_attached_to_another_tenants_case(): void
    {
        $foreignCase = $this->openCase(self::OTHER);
        $myEvidence = $this->evidence(self::TENANT, $this->signal(self::TENANT));

        // EnsureTenantScope refuses the URL's tenant segment before the
        // controller runs, so this proves the outer gate rather than the
        // controller's own check — attachEvidence's ownership check is the
        // second line of defence for the case a route could ever bypass it.
        $this->postJson(
            '/api/v1/cases/'.self::OTHER.'/'.$foreignCase.'/evidence',
            ['evidenceId' => $myEvidence],
            $this->auth(self::TENANT)
        )->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_attaching_to_an_unknown_case_id_within_my_own_tenant_404s(): void
    {
        $myEvidence = $this->evidence(self::TENANT, $this->signal(self::TENANT));

        $this->postJson(
            '/api/v1/cases/'.self::TENANT.'/'.Uuid::uuid4()->toString().'/evidence',
            ['evidenceId' => $myEvidence],
            $this->auth(self::TENANT)
        )->assertStatus(404)->assertJson(['error' => 'case_not_found']);
    }

    public function test_evidence_can_still_be_attached_to_my_own_case(): void
    {
        $myCase = $this->openCase(self::TENANT);
        $myEvidence = $this->evidence(self::TENANT, $this->signal(self::TENANT));

        $this->postJson(
            '/api/v1/cases/'.self::TENANT.'/'.$myCase.'/evidence',
            ['evidenceId' => $myEvidence],
            $this->auth(self::TENANT)
        )->assertStatus(200)->assertJson(['ok' => true]);

        self::assertDatabaseHas('hpbrain_case_evidence', [
            'tenant_id' => self::TENANT, 'case_id' => $myCase, 'evidence_id' => $myEvidence,
        ]);
    }
}
