<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The two RETRIEVE surfaces: Knowledge Library and Organizational Memory.
 *
 * THE FIXTURE IS BUILT TO CATCH THE LIES THESE SCREENS COULD TELL.
 *
 * It deliberately contains, for one tenant:
 *   - an outcome labelled "improved" whose metrics are all zero and which
 *     carries no evidence (the shape of every seeded outcome in production),
 *   - an outcome that genuinely moved and cites evidence,
 *   - a learning whose outcome is missing entirely,
 *   - a stale asset, a fresh asset, a seeded asset and an authored one,
 * and a second tenant holding knowledge and memory of its own, so isolation is
 * asserted rather than assumed.
 */
final class KnowledgeAndMemoryTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = '900';

    private const OTHER_TENANT = '901';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
        $this->seedKnowledge();
        $this->seedMemory();
    }

    /* ===================================================================== */
    /*  KNOWLEDGE LIBRARY */
    /* ===================================================================== */

    public function test_knowledge_summary_counts_only_this_tenants_assets(): void
    {
        $summary = $this->get('/api/v1/knowledge-library/'.self::TENANT.'/summary', $this->auth())
            ->assertStatus(200)
            ->json();

        $this->assertSame(4, $summary['total'], 'The other tenant holds assets that must not be counted here.');
        $this->assertSame(1, $summary['stale']);
        $this->assertSame(1, $summary['seeded']);
        $this->assertSame(3, $summary['observed']);
    }

    public function test_knowledge_facets_come_from_what_the_tenant_actually_holds(): void
    {
        $summary = $this->get('/api/v1/knowledge-library/'.self::TENANT.'/summary', $this->auth())->json();

        $categories = array_column($summary['categories'], 'value');

        // The screen this replaced hardcoded ten category names in the
        // component. The facets must be the tenant's own vocabulary.
        sort($categories);
        $this->assertSame(['policy', 'sop', 'template'], $categories);
        $this->assertNotContains('reasoning_model', $categories);
    }

    public function test_knowledge_grades_freshness_and_never_reports_a_zero_confidence(): void
    {
        $items = collect($this->list()['items'])->keyBy('title');

        $this->assertSame('STALE', $items['Ancient policy']['freshness']['state']);
        $this->assertSame('FRESH', $items['Onboarding SOP']['freshness']['state']);

        // A row with no confidence recorded must be UNDETERMINED, not 0%.
        $this->assertSame('UNDETERMINED', $items['Unscored template']['confidence']['state']);
        $this->assertNull($items['Unscored template']['confidence']['value']);
        $this->assertNotEmpty($items['Unscored template']['confidence']['basis']);
    }

    public function test_knowledge_marks_seeded_rows_rather_than_passing_them_off_as_experience(): void
    {
        $items = collect($this->list()['items'])->keyBy('title');

        $this->assertSame('SEEDED', $items['Seeded playbook']['provenance']['state']);
        $this->assertSame('OBSERVED', $items['Onboarding SOP']['provenance']['state']);
    }

    public function test_knowledge_search_and_filters_compose_and_run_in_sql(): void
    {
        $this->assertSame(1, $this->list(['q' => 'onboarding'])['total']);
        $this->assertSame(1, $this->list(['category' => 'policy'])['total']);
        $this->assertSame(1, $this->list(['freshness' => 'STALE'])['total']);

        // A search inside a filter must stay inside it.
        $this->assertSame(0, $this->list(['q' => 'onboarding', 'category' => 'policy'])['total']);
    }

    public function test_knowledge_pages_on_the_server(): void
    {
        $page = $this->list(['pageSize' => 2]);

        $this->assertCount(2, $page['items']);
        $this->assertSame(4, $page['total']);
        $this->assertSame(2, $page['pages']);
    }

    public function test_knowledge_detail_resolves_relationships_and_refuses_to_invent_usage(): void
    {
        $id = collect($this->list()['items'])->firstWhere('title', 'Onboarding SOP')['id'];

        $detail = $this->get('/api/v1/knowledge-library/'.self::TENANT.'/'.$id, $this->auth())
            ->assertStatus(200)
            ->json();

        $this->assertSame('Onboarding SOP', $detail['title']);

        // "Used in" has no join table behind it. It must report itself as
        // unsupported with a reason, never as an empty list that reads as
        // "this knowledge is never used".
        $this->assertFalse($detail['usedIn']['supported']);
        $this->assertNotEmpty($detail['usedIn']['reason']);
        $this->assertNotEmpty($detail['usedIn']['unlock']);
    }

    public function test_knowledge_from_another_tenant_is_not_readable(): void
    {
        $otherId = (string) DB::table('hpbrain_knowledge_assets')
            ->where('tenant_id', self::OTHER_TENANT)
            ->value('id');

        // Asking for the other tenant's asset by id, with this tenant's token,
        // must 404 — not return it because the path segment said so.
        $this->get('/api/v1/knowledge-library/'.self::TENANT.'/'.$otherId, $this->auth())
            ->assertStatus(404);
    }

    /* ===================================================================== */
    /*  ORGANIZATIONAL MEMORY */
    /* ===================================================================== */

    public function test_an_outcome_with_zero_metrics_is_undetermined_not_a_success(): void
    {
        $items = collect($this->memory()['items'])->keyBy('pattern');
        $hollow = $items['hollow-win'];

        $this->assertTrue($hollow['outcome']['present']);
        $this->assertSame('improved', $hollow['outcome']['result'], 'The stored result is preserved…');
        $this->assertSame(
            'UNDETERMINED',
            $hollow['outcome']['magnitude']['state'],
            '…but a change of zero across every metric was never measured, and must not read as an improvement.',
        );
        $this->assertStringContainsString('never measured', $hollow['outcome']['magnitude']['detail']);
    }

    public function test_a_measured_outcome_is_graded_measured_when_evidence_backs_it(): void
    {
        $items = collect($this->memory()['items'])->keyBy('pattern');
        $real = $items['real-win'];

        $this->assertSame('MEASURED', $real['outcome']['magnitude']['state']);
        $this->assertSame(-31.0, (float) $real['outcome']['magnitude']['changePercent']);
        $this->assertSame(1, $real['outcome']['evidenceCount']);
    }

    public function test_summary_counts_unmeasured_interventions_apart_from_successes(): void
    {
        $summary = $this->get('/api/v1/organizational-memory/'.self::TENANT.'/summary', $this->auth())
            ->assertStatus(200)
            ->json();

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['successfulInterventions'], 'Only the measured improvement counts.');
        $this->assertSame(2, $summary['unmeasuredInterventions'], 'The hollow win and the orphan are neither successes nor failures.');
        $this->assertSame(0, $summary['failedInterventions']);

        // Successes plus failures plus unmeasured must account for everything,
        // or some interventions have been quietly dropped from the counters.
        $this->assertSame(
            $summary['total'],
            $summary['successfulInterventions'] + $summary['failedInterventions'] + $summary['unmeasuredInterventions'],
        );
    }

    public function test_a_broken_chain_is_reported_as_broken(): void
    {
        $items = collect($this->memory()['items'])->keyBy('pattern');
        $orphan = $items['orphan-lesson'];

        $this->assertFalse($orphan['outcome']['present']);
        $this->assertNotEmpty($orphan['outcome']['reason']);
        $this->assertFalse($orphan['decision']['present']);
        $this->assertNotEmpty($orphan['decision']['reason']);
    }

    public function test_memory_detail_walks_evidence_execution_and_similar_memories(): void
    {
        $id = collect($this->memory()['items'])->firstWhere('pattern', 'real-win')['id'];

        $detail = $this->get('/api/v1/organizational-memory/'.self::TENANT.'/'.$id, $this->auth())
            ->assertStatus(200)
            ->json();

        $this->assertTrue($detail['evidence']['supported']);
        $this->assertCount(1, $detail['evidence']['items']);
        $this->assertSame('operational-records', $detail['evidence']['items'][0]['source']);

        $this->assertCount(1, $detail['executions']);
        $this->assertSame('completed', $detail['executions'][0]['status']);

        // Downstream influence has no column behind it and must say so rather
        // than inferring it from timing.
        $this->assertFalse($detail['influenced']['supported']);
        $this->assertNotEmpty($detail['influenced']['unlock']);
    }

    public function test_an_outcome_recorded_without_evidence_says_so(): void
    {
        $id = collect($this->memory()['items'])->firstWhere('pattern', 'hollow-win')['id'];

        $detail = $this->get('/api/v1/organizational-memory/'.self::TENANT.'/'.$id, $this->auth())->json();

        $this->assertFalse($detail['evidence']['supported']);
        $this->assertStringContainsString('without attaching any evidence', $detail['evidence']['reason']);
    }

    public function test_memory_is_scoped_to_the_tenant(): void
    {
        $feed = $this->memory();

        $this->assertSame(3, $feed['total'], "The other tenant's learnings must not appear here.");
        foreach ($feed['items'] as $item) {
            $this->assertNotSame('other-tenant-secret', $item['pattern']);
        }

        $otherId = (string) DB::table('hpbrain_learnings')
            ->where('tenant_id', self::OTHER_TENANT)
            ->value('id');

        $this->get('/api/v1/organizational-memory/'.self::TENANT.'/'.$otherId, $this->auth())
            ->assertStatus(404);
    }

    public function test_memory_search_and_paging_run_on_the_server(): void
    {
        $this->assertSame(1, $this->memory(['q' => 'orphan'])['total']);
        $this->assertSame(1, $this->memory(['pattern' => 'real-win'])['total']);

        $page = $this->memory(['pageSize' => 2]);
        $this->assertCount(2, $page['items']);
        $this->assertSame(2, $page['pages']);
    }

    /* ===================================================================== */
    /*  FIXTURE */
    /* ===================================================================== */

    /** @param array<string, mixed> $filters */
    private function list(array $filters = []): array
    {
        return $this->get('/api/v1/knowledge-library/'.self::TENANT.'?'.http_build_query($filters), $this->auth())
            ->assertStatus(200)
            ->json();
    }

    /** @param array<string, mixed> $filters */
    private function memory(array $filters = []): array
    {
        return $this->get('/api/v1/organizational-memory/'.self::TENANT.'?'.http_build_query($filters), $this->auth())
            ->assertStatus(200)
            ->json();
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => 'user-1', 'tenantId' => self::TENANT, 'role' => 'admin',
        ])];
    }

    private function seedKnowledge(): void
    {
        $rows = [
            $this->asset('k-fresh', self::TENANT, 'Onboarding SOP', 'sop', 'How a new joiner is brought onto the network team.', 0.9, 6, 'alice', now()->subDays(5)),
            $this->asset('k-stale', self::TENANT, 'Ancient policy', 'policy', 'The original escalation policy.', 0.7, 2, 'alice', now()->subDays(400)),
            $this->asset('k-unscored', self::TENANT, 'Unscored template', 'template', 'A blank report template.', 0.0, 0, 'bob', now()->subDays(10)),
            $this->asset('k-seeded', self::TENANT, 'Seeded playbook', 'sop', 'A demonstration playbook.', 0.8, 3, 'demo-seeder', now()->subDays(3)),
            $this->asset('k-other', self::OTHER_TENANT, 'Other tenant secret', 'policy', 'Must never be visible.', 0.9, 9, 'carol', now()->subDays(2)),
        ];

        DB::table('hpbrain_knowledge_assets')->insert($rows);
    }

    private function asset(string $id, string $tenant, string $title, string $category, string $content, float $confidence, int $reuse, string $by, \DateTimeInterface $updated): array
    {
        $row = [
            'id' => $id,
            'tenant_id' => $tenant,
            'title' => $title,
            'category' => $category,
            'content' => $content,
            'confidence' => $confidence,
            'reuse_count' => $reuse,
            'status' => 'published',
            'created_by' => $by,
            'created_date' => now()->subDays(500)->format('Y-m-d H:i:s'),
        ];

        foreach (['tags' => '["test"]', 'updated_date' => $updated->format('Y-m-d H:i:s'), 'department_id' => null, 'related_person_ids' => '[]', 'related_capability_ids' => '[]'] as $col => $value) {
            if (Schema::hasColumn('hpbrain_knowledge_assets', $col)) {
                $row[$col] = $value;
            }
        }

        return $row;
    }

    private function seedMemory(): void
    {
        DB::table('hpbrain_decisions')->insert([
            $this->decision('d-real', self::TENANT, 'Add two support members to Zone B.'),
            $this->decision('d-hollow', self::TENANT, 'Rebalance the workload.'),
        ]);

        DB::table('hpbrain_evidence')->insert([[
            'id' => 'e-1',
            'tenant_id' => self::TENANT,
            'source' => 'operational-records',
            'evidence_type' => 'aggregate',
            'content' => json_encode(['statement' => 'SLA breaches fell from 42 to 29 in the window.']),
            'provenance' => json_encode(['derivedFrom' => 'operational-records', 'method' => 'SQL aggregation']),
            'confidence' => 0.9,
            'hash' => hash('sha256', 'e-1'),
            'status' => 'grounded',
            'observed_date' => now()->subDays(9)->format('Y-m-d H:i:s'),
            'created_by' => 'pipeline',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]]);

        DB::table('hpbrain_outcomes')->insert([
            [
                'id' => 'o-real',
                'tenant_id' => self::TENANT,
                'decision_id' => 'd-real',
                'result' => 'improved',
                'metrics' => json_encode(['baseline' => 42, 'observed' => 29, 'unit' => 'breaches', 'changePercent' => -31]),
                'kpis' => json_encode(['sla_breaches' => 29]),
                'evidence_ids' => json_encode(['e-1']),
                'feedback' => 'Breach rate fell after the capacity change.',
                'confidence' => 0.88,
                'created_by' => 'pipeline',
                'created_date' => now()->subDays(9)->format('Y-m-d H:i:s'),
            ],
            [
                // The shape every seeded outcome in production has.
                'id' => 'o-hollow',
                'tenant_id' => self::TENANT,
                'decision_id' => 'd-hollow',
                'result' => 'improved',
                'metrics' => json_encode(['baseline' => 0, 'observed' => 0, 'unit' => 'records/person', 'changePercent' => 0]),
                'kpis' => json_encode(['records_per_person' => 0]),
                'evidence_ids' => json_encode([]),
                'feedback' => 'Load fell against the plan baseline.',
                'confidence' => 0.78,
                'created_by' => 'demo-seeder',
                'created_date' => now()->subDays(8)->format('Y-m-d H:i:s'),
            ],
        ]);

        DB::table('hpbrain_eso_executions')->insert([[
            'id' => 'x-1',
            'tenant_id' => self::TENANT,
            'eso_id' => 'eso-1',
            'eso_definition_id' => 'eso-1',
            'decision_id' => 'd-real',
            'input' => json_encode(['plan' => 'add-capacity']),
            'status' => 'completed',
            'executed_by' => 'ops',
            'executor_type' => 'human',
            'output' => json_encode(['result' => 'applied', 'note' => 'Two members added.']),
            'created_date' => now()->subDays(9)->format('Y-m-d H:i:s'),
        ]]);

        DB::table('hpbrain_learnings')->insert([
            $this->learning('l-real', self::TENANT, 'o-real', 'real-win', 'Capacity shortage predicted breaches better than ticket volume.', 0.87, 'pipeline'),
            $this->learning('l-hollow', self::TENANT, 'o-hollow', 'hollow-win', 'Redistributing the largest category reduced load.', 0.74, 'demo-seeder'),
            $this->learning('l-orphan', self::TENANT, 'missing-outcome', 'orphan-lesson', 'A lesson whose outcome row is gone.', 0.5, 'pipeline'),
            $this->learning('l-other', self::OTHER_TENANT, 'o-other', 'other-tenant-secret', 'Must never be visible.', 0.9, 'pipeline'),
        ]);
    }

    private function decision(string $id, string $tenant, string $rationale): array
    {
        $row = [
            'id' => $id,
            'tenant_id' => $tenant,
            'status' => 'approved',
            'rationale' => $rationale,
            'created_date' => now()->subDays(10)->format('Y-m-d H:i:s'),
        ];

        foreach (['confidence' => 0.8, 'explanation' => 'Reversible and measurable in one period.', 'decided_by' => 'ops-lead'] as $col => $value) {
            if (Schema::hasColumn('hpbrain_decisions', $col)) {
                $row[$col] = $value;
            }
        }

        return $row;
    }

    private function learning(string $id, string $tenant, string $outcomeId, string $pattern, string $description, float $confidence, string $by): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenant,
            'outcome_id' => $outcomeId,
            'pattern' => $pattern,
            'description' => $description,
            'domain' => 'operations',
            'confidence' => $confidence,
            'reusable' => 1,
            'created_by' => $by,
            'created_date' => now()->subDays(7)->format('Y-m-d H:i:s'),
        ];
    }
}
