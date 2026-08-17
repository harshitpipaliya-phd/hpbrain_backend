<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cases\CaseSignalEvidence;
use App\Domain\Cases\CaseSignalLinker;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The case-wide evidence view.
 *
 * THE FIRST TEST IS THE IMPORTANT ONE AND IT IS AN EQUALITY, NOT A SHAPE CHECK.
 * This capability only earns its place if it changes nothing for the ordinary
 * case — one case, one signal — because that is every case in the installation
 * today. So it is asserted against the LITERAL query ExplainVerb and
 * RecommendVerb use, copied verbatim into the test rather than described, and
 * compared with ==== on the whole structure. A paraphrase would pass while the
 * two drifted; a copy will not.
 *
 * The fixture deliberately includes evidence at DIFFERENT confidences, so the
 * `orderByDesc('confidence')` both sides share is actually exercised, and a
 * NON-ACTIVE row, because neither verb filters on status and this must not
 * either — a view that quietly hid retracted evidence would show a case
 * standing on a narrower base than the one the verbs actually reason over.
 */
final class CaseSignalEvidenceTest extends TestCase
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

    private function service(): CaseSignalEvidence
    {
        return app(CaseSignalEvidence::class);
    }

    private function linker(): CaseSignalLinker
    {
        return app(CaseSignalLinker::class);
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

    private function evidence(string $tenantId, string $signalId, float $confidence, string $status = 'active'): string
    {
        $id = Uuid::uuid4()->toString();
        $content = json_encode(['issue' => 'observed at confidence '.$confidence]);

        DB::table('hpbrain_evidence')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'signal_id' => $signalId,
            'source' => 'erp.hrms_departments', 'evidence_type' => 'observation',
            'content' => $content, 'provenance' => json_encode(['source' => 'erp', 'ts' => '2026-08-01T00:00:00Z']),
            'confidence' => $confidence, 'hash' => hash('sha256', $id), 'status' => $status,
            'created_by' => 'system', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function openCase(string $tenantId, ?string $signalId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_cases')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'signal_id' => null,
            'title' => 'A case', 'status' => 'open', 'created_by' => 'test',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        if ($signalId !== null) {
            $this->linker()->linkPrimary($tenantId, $id, $signalId, self::ACTOR);
        }

        return $id;
    }

    /**
     * ExplainVerb::ground and RecommendVerb::ground, copied literally.
     *
     * If this ever stops matching the verbs, the first test below fails — which
     * is the point. It is not a helper for convenience; it is the reference.
     *
     * @return array<int, array<string, mixed>>
     */
    private function verbGroundingQuery(string $tenantId, string $signalId): array
    {
        return DB::table('hpbrain_evidence')
            ->where('tenant_id', $tenantId)
            ->where('signal_id', $signalId)
            ->orderByDesc('confidence')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'kind' => 'evidence', 'row' => (array) $r])
            ->all();
    }

    /** The aggregator's evidence with the added tags removed. */
    private function untagged(array $result): array
    {
        return array_map(
            fn (array $e): array => ['id' => $e['id'], 'kind' => $e['kind'], 'row' => $e['row']],
            $result['evidence']
        );
    }

    /* ─────────────── 1. zero behaviour change for the ordinary case ─────────────── */

    public function test_a_case_with_only_a_primary_signal_matches_the_verbs_own_query_exactly(): void
    {
        $signalId = $this->signal(self::TENANT);

        // Out of confidence order on purpose, so the shared ordering is exercised.
        $this->evidence(self::TENANT, $signalId, 0.4);
        $this->evidence(self::TENANT, $signalId, 0.95);
        $this->evidence(self::TENANT, $signalId, 0.7);
        // Neither verb filters on status, so neither may this.
        $this->evidence(self::TENANT, $signalId, 0.6, status: 'retracted');

        $caseId = $this->openCase(self::TENANT, $signalId);

        $result = $this->service()->forCase(self::TENANT, $caseId);

        self::assertNotNull($result);
        self::assertSame(4, $result['evidenceCount'], 'A retracted row is still grounding the verbs would see.');

        // THE ASSERTION THIS CAPABILITY LIVES OR DIES BY: identical rows, in
        // identical order, to what EXPLAIN and RECOMMEND read today.
        self::assertSame(
            $this->verbGroundingQuery(self::TENANT, $signalId),
            $this->untagged($result),
            'A single-signal case must return exactly what the verbs ground on.'
        );
    }

    public function test_the_tags_are_purely_additive(): void
    {
        $signalId = $this->signal(self::TENANT);
        $this->evidence(self::TENANT, $signalId, 0.9);

        $result = $this->service()->forCase(self::TENANT, $this->openCase(self::TENANT, $signalId));

        self::assertSame(
            ['id', 'kind', 'row', 'signalId', 'role'],
            array_keys($result['evidence'][0]),
            'The verb shape must survive intact with the tags appended, not merged into it.'
        );
        self::assertSame($signalId, $result['evidence'][0]['signalId']);
        self::assertSame(CaseSignalLinker::PRIMARY, $result['evidence'][0]['role']);
    }

    public function test_a_case_with_no_evidence_returns_an_empty_union_not_null(): void
    {
        $result = $this->service()->forCase(self::TENANT, $this->openCase(self::TENANT, $this->signal(self::TENANT)));

        self::assertNotNull($result);
        self::assertSame([], $result['evidence']);
        self::assertSame(0, $result['evidenceCount']);
        self::assertCount(1, $result['signals'], 'The signal is still linked; it simply has nothing under it.');
    }

    /* ─────────────── 2. the genuine union, tagged per source signal ─────────────── */

    public function test_related_signals_contribute_their_evidence_tagged_to_their_own_signal(): void
    {
        $primary = $this->signal(self::TENANT);
        $relatedA = $this->signal(self::TENANT);
        $relatedB = $this->signal(self::TENANT);

        $p1 = $this->evidence(self::TENANT, $primary, 0.9);
        $p2 = $this->evidence(self::TENANT, $primary, 0.5);
        $a1 = $this->evidence(self::TENANT, $relatedA, 0.8);
        $b1 = $this->evidence(self::TENANT, $relatedB, 0.6);
        $b2 = $this->evidence(self::TENANT, $relatedB, 0.99);

        $caseId = $this->openCase(self::TENANT, $primary);
        $this->linker()->linkRelated(self::TENANT, $caseId, $relatedA, self::ACTOR);
        $this->linker()->linkRelated(self::TENANT, $caseId, $relatedB, self::ACTOR);

        $result = $this->service()->forCase(self::TENANT, $caseId);

        self::assertSame(5, $result['evidenceCount'], 'Every linked signal contributes.');

        // Each row is attributed to the signal it actually came from.
        $bySignal = [];

        foreach ($result['evidence'] as $e) {
            $bySignal[$e['signalId']][] = $e['id'];
        }

        self::assertSame([$p1, $p2], $bySignal[$primary], 'Primary evidence, confidence order preserved.');
        self::assertSame([$a1], $bySignal[$relatedA]);
        self::assertSame([$b2, $b1], $bySignal[$relatedB], 'Each signal keeps its own confidence order.');

        // Roles are carried through, not inferred by the caller.
        $roles = [];

        foreach ($result['evidence'] as $e) {
            $roles[$e['signalId']] = $e['role'];
        }

        self::assertSame(CaseSignalLinker::PRIMARY, $roles[$primary]);
        self::assertSame(CaseSignalLinker::RELATED, $roles[$relatedA]);
        self::assertSame(CaseSignalLinker::RELATED, $roles[$relatedB]);

        // The primary's evidence comes first, and the summary agrees with the rows.
        self::assertSame($primary, $result['signals'][0]['signalId']);
        self::assertSame(2, $result['signals'][0]['evidenceCount']);
        self::assertSame($primary, $result['evidence'][0]['signalId']);

        self::assertSame(
            $result['evidenceCount'],
            array_sum(array_column($result['signals'], 'evidenceCount')),
            'The per-signal counts must reconcile with the union.'
        );

        // And the authoritative primary is published for cross-checking.
        self::assertSame($primary, $result['primarySignalId']);
    }

    public function test_the_primary_slice_of_a_union_still_matches_the_verbs_query(): void
    {
        $primary = $this->signal(self::TENANT);
        $related = $this->signal(self::TENANT);

        $this->evidence(self::TENANT, $primary, 0.3);
        $this->evidence(self::TENANT, $primary, 0.85);
        $this->evidence(self::TENANT, $related, 0.99);

        $caseId = $this->openCase(self::TENANT, $primary);
        $this->linker()->linkRelated(self::TENANT, $caseId, $related, self::ACTOR);

        $result = $this->service()->forCase(self::TENANT, $caseId);

        // Adding a related signal must not disturb what the verbs would see for
        // the primary — not its contents and not its order.
        $primarySlice = array_values(array_filter(
            $result['evidence'], fn (array $e): bool => $e['signalId'] === $primary
        ));

        self::assertSame(
            $this->verbGroundingQuery(self::TENANT, $primary),
            $this->untagged(['evidence' => $primarySlice]),
        );
    }

    /* ─────────────── 3. tenant isolation ─────────────── */

    public function test_another_tenants_case_is_not_readable(): void
    {
        $foreignSignal = $this->signal(self::OTHER);
        $this->evidence(self::OTHER, $foreignSignal, 0.9);
        $foreignCase = $this->openCase(self::OTHER, $foreignSignal);

        self::assertNull(
            $this->service()->forCase(self::TENANT, $foreignCase),
            'A case belonging to another tenant must be indistinguishable from one that does not exist.'
        );
    }

    public function test_evidence_is_filtered_by_tenant_not_only_by_signal(): void
    {
        $signalId = $this->signal(self::TENANT);
        $mine = $this->evidence(self::TENANT, $signalId, 0.9);

        // A row on the SAME signal id but stamped to another tenant. The foreign
        // key does not prevent this and neither does the signal filter — only
        // the tenant filter does.
        $this->evidence(self::OTHER, $signalId, 0.95);

        $result = $this->service()->forCase(self::TENANT, $this->openCase(self::TENANT, $signalId));

        self::assertSame(1, $result['evidenceCount']);
        self::assertSame($mine, $result['evidence'][0]['id']);
    }

    /* ─────────────── the endpoint ─────────────── */

    public function test_the_endpoint_returns_the_union_for_an_authorised_caller(): void
    {
        $primary = $this->signal(self::TENANT);
        $related = $this->signal(self::TENANT);
        $this->evidence(self::TENANT, $primary, 0.9);
        $this->evidence(self::TENANT, $related, 0.4);

        $caseId = $this->openCase(self::TENANT, $primary);
        $this->linker()->linkRelated(self::TENANT, $caseId, $related, self::ACTOR);

        $body = $this->getJson(
            '/api/v1/cases/'.self::TENANT.'/'.$caseId.'/signal-evidence', $this->auth(self::TENANT)
        )->assertStatus(200)->json();

        self::assertSame($caseId, $body['caseId']);
        self::assertSame(2, $body['evidenceCount']);
        self::assertSame($primary, $body['primarySignalId']);
        self::assertCount(2, $body['signals']);
    }

    public function test_the_endpoint_refuses_another_tenants_case(): void
    {
        $foreignCase = $this->openCase(self::OTHER, $this->signal(self::OTHER));

        // EnsureTenantScope answers first: the path names a tenant the token
        // does not carry.
        $this->getJson('/api/v1/cases/'.self::OTHER.'/'.$foreignCase.'/signal-evidence', $this->auth(self::TENANT))
            ->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_the_endpoint_is_404_for_an_unknown_case(): void
    {
        $this->getJson(
            '/api/v1/cases/'.self::TENANT.'/'.Uuid::uuid4()->toString().'/signal-evidence',
            $this->auth(self::TENANT)
        )->assertStatus(404)->assertJson(['error' => 'case_not_found']);
    }

    public function test_the_endpoint_refuses_an_unauthenticated_request(): void
    {
        $this->getJson('/api/v1/cases/'.self::TENANT.'/'.Uuid::uuid4()->toString().'/signal-evidence')
            ->assertStatus(401);
    }

    /** @return array<string, string> */
    private function auth(string $tenantId, string $role = 'analyst'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-'.$tenantId, 'tenantId' => $tenantId, 'role' => $role,
        ])];
    }
}
