<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cases\CaseSignalLinker;
use App\Domain\Cases\RecommendationCaseContext;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * A recommendation, what it cited, and what its case knew besides.
 *
 * THE FIRST TEST IS THE ONE THAT MATTERS AND IT IS A BYTE EQUALITY. This
 * endpoint exists to put case-wide evidence in front of a human WITHOUT that
 * evidence contaminating the recommendation's own citation. The only way to
 * prove nothing was contaminated is to assert that `groundedOn` comes back
 * identical to the raw stored `dependencies` column — same values, same order,
 * duplicates and dangling ids included. Anything softer (a set comparison, a
 * count) would pass while the list was quietly cleaned up.
 *
 * The fixture therefore stores a deliberately AWKWARD dependencies array: out of
 * order, containing a duplicate, and containing an id that references no
 * evidence row at all. All three are things a well-meaning implementation would
 * be tempted to fix, and all three must survive.
 *
 * THE RECOMMENDATION FIXTURE MIRRORS RecommendVerb::persist, including
 * reasoning_step_id => null. That null is not incidental — it is why the case is
 * resolved through the cited evidence at all, and a fixture that populated it
 * would be testing a path the verb pipeline never produces.
 */
final class RecommendationCaseContextTest extends TestCase
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

    private function service(): RecommendationCaseContext
    {
        return app(RecommendationCaseContext::class);
    }

    private function linker(): CaseSignalLinker
    {
        return app(CaseSignalLinker::class);
    }

    private function signal(string $tenantId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'source' => 'import.complaint',
            'classification' => 'data_quality', 'priority' => 'high', 'severity' => 'medium',
            'confidence' => 1.0, 'status' => 'new', 'created_by' => 'system',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function evidence(string $tenantId, string $signalId, float $confidence = 0.8): string
    {
        $id = Uuid::uuid4()->toString();
        $content = json_encode(['issue' => 'observed']);

        DB::table('hpbrain_evidence')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'signal_id' => $signalId,
            'source' => 'import.complaint', 'evidence_type' => 'observation',
            'content' => $content, 'provenance' => json_encode(['source' => 'erp', 'ts' => '2026-08-01T00:00:00Z']),
            'confidence' => $confidence, 'hash' => hash('sha256', $id), 'status' => 'active',
            'created_by' => 'system', 'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function openCase(string $tenantId, string $primarySignalId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_cases')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'signal_id' => null,
            'title' => 'A case', 'status' => 'open', 'created_by' => 'test',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->linker()->linkPrimary($tenantId, $id, $primarySignalId, self::ACTOR);

        return $id;
    }

    /**
     * A recommendation shaped exactly as RecommendVerb::persist writes one.
     *
     * @param  array<int, string>  $evidenceRefs
     */
    private function recommendation(string $tenantId, array $evidenceRefs): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_recommendations')->insert([
            'id' => $id, 'tenant_id' => $tenantId,
            // The hardcoded null RecommendVerb writes — the reason this whole
            // resolution path exists.
            'reasoning_step_id' => null,
            'category' => 'investigate', 'title' => 'Review the closure process',
            'description' => 'Rationale from the model.', 'priority' => 'high',
            'confidence' => 0.95, 'dependencies' => json_encode($evidenceRefs),
            'status' => 'pending', 'created_by' => 'brain-reason-signals',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /* ─────────── 1. groundedOn is byte-identical to what was stored ─────────── */

    public function test_grounded_on_is_the_stored_dependencies_untouched(): void
    {
        $signalId = $this->signal(self::TENANT);
        $a = $this->evidence(self::TENANT, $signalId, 0.5);
        $b = $this->evidence(self::TENANT, $signalId, 0.9);
        $this->openCase(self::TENANT, $signalId);

        // Deliberately awkward: lowest-confidence first, a duplicate, and an id
        // that references nothing. Every one of these is something an
        // implementation might "helpfully" correct.
        $stored = [$a, $b, $a, 'evidence-that-does-not-exist'];

        $recommendationId = $this->recommendation(self::TENANT, $stored);

        $result = $this->service()->forRecommendation(self::TENANT, $recommendationId);

        self::assertNotNull($result);

        // THE ASSERTION. Same values, same order, duplicate kept, dangling id kept.
        self::assertSame($stored, $result['groundedOn']);

        // And identical to the raw column, decoded and nothing else — so this
        // stays true even if the fixture above is ever changed.
        self::assertSame(
            json_decode(
                (string) DB::table('hpbrain_recommendations')->where('id', $recommendationId)->value('dependencies'),
                true
            ),
            $result['groundedOn'],
            'groundedOn must be the stored citation, not a cleaned-up version of it.'
        );
    }

    public function test_the_case_is_resolved_through_the_cited_evidence(): void
    {
        $signalId = $this->signal(self::TENANT);
        $e = $this->evidence(self::TENANT, $signalId);
        $caseId = $this->openCase(self::TENANT, $signalId);

        $result = $this->service()->forRecommendation(self::TENANT, $this->recommendation(self::TENANT, [$e]));

        self::assertSame($caseId, $result['caseId']);
        self::assertSame([$signalId], $result['resolution']['signalIds']);
        self::assertStringContainsString('dependencies', $result['resolution']['via']);
        self::assertNull($result['resolution']['note']);
    }

    /* ─────────── 2. a clustered case: related evidence only in caseContext ─────────── */

    public function test_related_evidence_appears_only_in_case_context(): void
    {
        $primary = $this->signal(self::TENANT);
        $related = $this->signal(self::TENANT);

        $p1 = $this->evidence(self::TENANT, $primary, 0.9);
        $p2 = $this->evidence(self::TENANT, $primary, 0.6);
        $r1 = $this->evidence(self::TENANT, $related, 0.8);
        $r2 = $this->evidence(self::TENANT, $related, 0.4);

        $caseId = $this->openCase(self::TENANT, $primary);
        $this->linker()->linkRelated(self::TENANT, $caseId, $related, self::ACTOR);

        // The recommendation cited only the primary signal's evidence, which is
        // all RecommendVerb ever grounds on.
        $result = $this->service()->forRecommendation(
            self::TENANT, $this->recommendation(self::TENANT, [$p1, $p2])
        );

        self::assertSame($caseId, $result['caseId']);
        self::assertSame([$p1, $p2], $result['groundedOn']);

        $context = $result['caseContext'];

        self::assertSame($primary, $context['primarySignalId']);
        self::assertCount(1, $context['relatedSignals']);
        self::assertSame($related, $context['relatedSignals'][0]['signalId']);
        self::assertSame(CaseSignalLinker::RELATED, $context['relatedSignals'][0]['role']);

        // Exactly the related signal's evidence, and nothing from the primary.
        self::assertSame(2, $context['evidenceCount']);
        self::assertSame([$r1, $r2], array_column($context['evidence'], 'id'));

        foreach ($context['evidence'] as $row) {
            self::assertSame($related, $row['signalId']);
            self::assertSame(CaseSignalLinker::RELATED, $row['role']);
            self::assertNotContains($row['id'], $result['groundedOn'],
                'Case context must never restate what the recommendation cited.');
        }

        // The two halves do not overlap, in either direction.
        self::assertSame(
            [],
            array_intersect($result['groundedOn'], array_column($context['evidence'], 'id')),
        );
        self::assertSame(0, $context['citedFromRelated']);
    }

    /* ─────────── 3. today's ordinary shape: primary only ─────────── */

    public function test_a_primary_only_case_has_no_related_signals(): void
    {
        $signalId = $this->signal(self::TENANT);
        $e1 = $this->evidence(self::TENANT, $signalId, 0.9);
        $e2 = $this->evidence(self::TENANT, $signalId, 0.7);
        $caseId = $this->openCase(self::TENANT, $signalId);

        $result = $this->service()->forRecommendation(
            self::TENANT, $this->recommendation(self::TENANT, [$e1, $e2])
        );

        self::assertSame($caseId, $result['caseId']);
        self::assertSame([$e1, $e2], $result['groundedOn']);

        // The shape of every case in the installation today.
        self::assertSame([], $result['caseContext']['relatedSignals']);
        self::assertSame([], $result['caseContext']['evidence']);
        self::assertSame(0, $result['caseContext']['evidenceCount']);
        // The primary is still named, so a reader can see the case was resolved.
        self::assertSame($signalId, $result['caseContext']['primarySignalId']);
    }

    public function test_a_recommendation_whose_evidence_reaches_no_case_is_undetermined(): void
    {
        $signalId = $this->signal(self::TENANT);
        $e = $this->evidence(self::TENANT, $signalId);
        // No case opened for that signal.

        $result = $this->service()->forRecommendation(self::TENANT, $this->recommendation(self::TENANT, [$e]));

        self::assertNull($result['caseId']);
        self::assertStringContainsString('No case links', $result['resolution']['note']);
        // The citation itself is unaffected by the case being unreachable.
        self::assertSame([$e], $result['groundedOn']);
        self::assertSame([], $result['caseContext']['relatedSignals']);
    }

    public function test_evidence_spanning_two_cases_is_reported_as_undetermined(): void
    {
        $signalA = $this->signal(self::TENANT);
        $signalB = $this->signal(self::TENANT);
        $a = $this->evidence(self::TENANT, $signalA);
        $b = $this->evidence(self::TENANT, $signalB);

        $this->openCase(self::TENANT, $signalA);
        $this->openCase(self::TENANT, $signalB);

        $result = $this->service()->forRecommendation(self::TENANT, $this->recommendation(self::TENANT, [$a, $b]));

        // Two candidates, no confident pick.
        self::assertNull($result['caseId']);
        self::assertCount(2, $result['resolution']['candidateCaseIds']);
        self::assertStringContainsString('more than one case', $result['resolution']['note']);
    }

    /* ─────────── 4. tenant isolation ─────────── */

    public function test_another_tenants_recommendation_is_not_readable(): void
    {
        $signalId = $this->signal(self::OTHER);
        $e = $this->evidence(self::OTHER, $signalId);
        $this->openCase(self::OTHER, $signalId);

        $foreign = $this->recommendation(self::OTHER, [$e]);

        self::assertNull(
            $this->service()->forRecommendation(self::TENANT, $foreign),
            'A recommendation belonging to another tenant must be indistinguishable from one that does not exist.'
        );
    }

    public function test_the_case_is_never_resolved_across_tenants(): void
    {
        // A recommendation in TENANT citing an evidence id that only exists in
        // OTHER. The evidence lookup is tenant-filtered, so the trail dies here
        // rather than reaching the other organization's case.
        $foreignSignal = $this->signal(self::OTHER);
        $foreignEvidence = $this->evidence(self::OTHER, $foreignSignal);
        $foreignCase = $this->openCase(self::OTHER, $foreignSignal);

        $result = $this->service()->forRecommendation(
            self::TENANT, $this->recommendation(self::TENANT, [$foreignEvidence])
        );

        self::assertNull($result['caseId']);
        self::assertNotContains($foreignCase, $result['resolution']['candidateCaseIds']);
        self::assertSame([], $result['resolution']['signalIds']);
        self::assertSame([], $result['caseContext']['evidence']);
    }

    /* ─────────── the endpoint ─────────── */

    public function test_the_endpoint_returns_both_halves(): void
    {
        $primary = $this->signal(self::TENANT);
        $related = $this->signal(self::TENANT);
        $p1 = $this->evidence(self::TENANT, $primary, 0.9);
        $this->evidence(self::TENANT, $related, 0.5);

        $caseId = $this->openCase(self::TENANT, $primary);
        $this->linker()->linkRelated(self::TENANT, $caseId, $related, self::ACTOR);

        $recommendationId = $this->recommendation(self::TENANT, [$p1]);

        $body = $this->getJson(
            '/api/v1/recommendations/'.self::TENANT.'/'.$recommendationId.'/case-context',
            $this->auth(self::TENANT)
        )->assertStatus(200)->json();

        self::assertSame($recommendationId, $body['recommendationId']);
        self::assertSame($caseId, $body['caseId']);
        self::assertSame([$p1], $body['groundedOn']);
        self::assertSame(1, $body['caseContext']['evidenceCount']);
        self::assertCount(1, $body['caseContext']['relatedSignals']);
    }

    public function test_the_endpoint_refuses_another_tenants_path(): void
    {
        $this->getJson(
            '/api/v1/recommendations/'.self::OTHER.'/'.Uuid::uuid4()->toString().'/case-context',
            $this->auth(self::TENANT)
        )->assertStatus(403)->assertJson(['error' => 'tenant_mismatch']);
    }

    public function test_the_endpoint_is_404_for_an_unknown_recommendation(): void
    {
        $this->getJson(
            '/api/v1/recommendations/'.self::TENANT.'/'.Uuid::uuid4()->toString().'/case-context',
            $this->auth(self::TENANT)
        )->assertStatus(404)->assertJson(['error' => 'recommendation_not_found']);
    }

    public function test_the_endpoint_refuses_an_unauthenticated_request(): void
    {
        $this->getJson('/api/v1/recommendations/'.self::TENANT.'/'.Uuid::uuid4()->toString().'/case-context')
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
