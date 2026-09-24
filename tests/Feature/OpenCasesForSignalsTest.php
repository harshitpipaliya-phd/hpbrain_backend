<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cases\CaseSignalLinker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\BuildsBrainSchema;
use Tests\Support\SeedsEntityMappings;
use Tests\TestCase;

/**
 * brain:open-cases, running for real.
 *
 * WHY THIS EXISTS. Two things had never been executed against a database:
 *
 *   1. The command's WRITE path since CaseSignalLinker was wired into it. Every
 *      run against the real installation found zero qualifying signals — every
 *      rule-derived signal already had a case — so the only evidence the console
 *      path wrote a junction row at all was that it shares a class with the HTTP
 *      path that does. That is an argument, not a test.
 *
 *   2. The related link, in production logic. CaseSignalLinkerTest proves
 *      linkRelated works when called directly; nothing proved anything ever
 *      calls it, or calls it on the right signal.
 *
 * THE FIXTURE IS BUILT AROUND THE ONE SCENARIO THAT PRODUCES A SECOND SIGNAL FOR
 * ONE RULE. Detection refreshes rather than duplicates while a signal is
 * unresolved, so a rule cannot raise two live signals — a second only appears
 * once the first is resolved or dismissed. Every grouping test below therefore
 * resolves the first signal and raises a fresh one, which is the real
 * recurrence-after-resolution shape rather than a state the detectors cannot
 * reach.
 */
final class OpenCasesForSignalsTest extends TestCase
{
    use BuildsBrainSchema;
    use SeedsEntityMappings;

    private const TENANT = 'tenant-alpha';

    private const OTHER = 'tenant-beta';

    private const RULE = 'departments_without_manager';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
        // The command enumerates tenants through EntityResolver::everyTenantWith,
        // so a tenant with no mapping is invisible to it — correctly, and for the
        // same reason a real unmapped tenant would be.
        $this->installEntityMappings([self::TENANT, self::OTHER]);
    }

    /* ─────────────────────────── fixture helpers ─────────────────────────── */

    /**
     * A rule-derived signal with evidence — the shape that qualifies.
     *
     * @param  array<string, mixed>  $scope  related_entity_type/_id, department_id, org_id
     */
    private function signal(
        string $tenantId,
        string $ruleKey = self::RULE,
        array $scope = ['org_id' => 'org-1'],
        string $status = 'new',
        bool $withEvidence = true,
    ): string {
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_signals')->insert($scope + [
            'id' => $id, 'tenant_id' => $tenantId, 'source' => 'erp.data_quality',
            'classification' => 'leadership', 'rule_key' => $ruleKey,
            'priority' => 'medium', 'severity' => 'medium', 'confidence' => 1.0,
            'status' => $status, 'created_by' => 'system',
            'metadata' => json_encode(['rule' => $ruleKey, 'affectedCount' => 3]),
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);

        if ($withEvidence) {
            $content = json_encode(['issue' => 'no manager assigned']);

            DB::table('hpbrain_evidence')->insert([
                'id' => Uuid::uuid4()->toString(), 'tenant_id' => $tenantId, 'signal_id' => $id,
                'source' => 'erp.hrms_departments', 'evidence_type' => 'observation',
                'content' => $content, 'provenance' => json_encode(['source' => 'erp', 'ts' => '2026-08-01T00:00:00Z']),
                'confidence' => 1.0, 'hash' => hash('sha256', $content), 'status' => 'active',
                'created_by' => 'system', 'created_date' => now()->format('Y-m-d H:i:s'),
            ]);
        }

        return $id;
    }

    private function openCases(string $tenantId = self::TENANT): int
    {
        return Artisan::call('brain:open-cases', ['--tenant' => $tenantId]);
    }

    /** Mark a signal resolved, the precondition for its rule raising a fresh one. */
    private function resolve(string $signalId): void
    {
        DB::table('hpbrain_signals')->where('id', $signalId)->update(['status' => 'resolved']);
    }

    /** @return array<int, object> */
    private function links(string $caseId): array
    {
        return DB::table('hpbrain_case_signals')->where('case_id', $caseId)
            ->orderBy('role')->get()->all();
    }

    private function caseCount(string $tenantId = self::TENANT): int
    {
        return DB::table('hpbrain_cases')->where('tenant_id', $tenantId)->count();
    }

    private function onlyCaseId(string $tenantId = self::TENANT): string
    {
        return (string) DB::table('hpbrain_cases')->where('tenant_id', $tenantId)->value('id');
    }

    /* ─────────────── the console write path, finally executed ─────────────── */

    public function test_a_qualifying_signal_opens_a_case_with_both_the_column_and_the_junction(): void
    {
        $signalId = $this->signal(self::TENANT);

        self::assertSame(0, $this->openCases());

        self::assertSame(1, $this->caseCount());

        $caseId = $this->onlyCaseId();

        // The authoritative copy, which ExplainVerb reads.
        self::assertSame(
            $signalId,
            DB::table('hpbrain_cases')->where('id', $caseId)->value('signal_id'),
            'brain:open-cases must still set hpbrain_cases.signal_id.'
        );

        // And the junction row the linker exists to keep in step with it. This
        // is the assertion the console path had never made.
        $links = $this->links($caseId);

        self::assertCount(1, $links);
        self::assertSame($signalId, $links[0]->signal_id);
        self::assertSame(CaseSignalLinker::PRIMARY, $links[0]->role);
        self::assertSame(self::TENANT, $links[0]->tenant_id);
        self::assertSame('brain-open-cases', $links[0]->linked_by);
    }

    public function test_a_signal_without_evidence_opens_nothing(): void
    {
        $this->signal(self::TENANT, withEvidence: false);

        self::assertSame(0, $this->openCases());
        self::assertSame(0, $this->caseCount());
        self::assertSame(0, DB::table('hpbrain_case_signals')->count());
    }

    public function test_running_twice_opens_nothing_the_second_time(): void
    {
        $this->signal(self::TENANT);

        $this->openCases();
        $this->openCases();

        self::assertSame(1, $this->caseCount());
        self::assertCount(1, $this->links($this->onlyCaseId()));
    }

    /* ─────────────── the related link, in production logic ─────────────── */

    /**
     * THE TEST THIS STEP EXISTS FOR.
     *
     * One case, two signals: the one it was opened for, and the recurrence that
     * followed. Before this change the recurrence opened a second case beside a
     * still-open one and nothing recorded they were the same problem.
     */
    public function test_a_recurrence_of_the_same_problem_attaches_to_the_open_case(): void
    {
        $first = $this->signal(self::TENANT, scope: ['org_id' => 'org-1']);

        $this->openCases();

        $caseId = $this->onlyCaseId();

        // The detectors only raise a second signal for one rule after the first
        // has been resolved or dismissed — so that is what happens here.
        $this->resolve($first);

        $recurrence = $this->signal(self::TENANT, scope: ['org_id' => 'org-1']);

        $this->openCases();

        // NO second case.
        self::assertSame(1, $this->caseCount(), 'A recurrence must join the open case, not open a rival.');

        $links = $this->links($caseId);

        self::assertCount(2, $links);

        $byRole = collect($links)->pluck('signal_id', 'role')->all();

        self::assertSame($first, $byRole[CaseSignalLinker::PRIMARY]);
        self::assertSame($recurrence, $byRole[CaseSignalLinker::RELATED]);

        // The primary is untouched: a case that silently changed what it was
        // about would invalidate every hypothesis already reasoned from it.
        self::assertSame(
            $first,
            DB::table('hpbrain_cases')->where('id', $caseId)->value('signal_id'),
            'Attaching a related signal must never repoint hpbrain_cases.signal_id.'
        );
    }

    public function test_an_attached_signal_is_not_attached_again_on_the_next_run(): void
    {
        $first = $this->signal(self::TENANT);
        $this->openCases();
        $this->resolve($first);
        $this->signal(self::TENANT);

        $this->openCases();
        $this->openCases();

        self::assertCount(2, $this->links($this->onlyCaseId()), 'A re-run must not restate the attachment.');
    }

    /* ─────────────── what must NOT group ─────────────── */

    public function test_a_different_affected_party_gets_its_own_case(): void
    {
        $first = $this->signal(self::TENANT, scope: ['org_id' => 'org-1']);
        $this->openCases();
        $this->resolve($first);

        // Same rule, different organization.
        $this->signal(self::TENANT, scope: ['org_id' => 'org-2']);
        $this->openCases();

        self::assertSame(2, $this->caseCount(), 'A different affected party is a different problem.');
    }

    public function test_a_different_rule_gets_its_own_case(): void
    {
        $this->signal(self::TENANT, ruleKey: self::RULE, scope: ['org_id' => 'org-1']);
        $this->openCases();

        // Same party, different condition — and no resolve() needed, because
        // two different rules can hold live signals simultaneously.
        $this->signal(self::TENANT, ruleKey: 'people_without_department', scope: ['org_id' => 'org-1']);
        $this->openCases();

        self::assertSame(2, $this->caseCount(), 'A different rule is a different problem.');
    }

    /**
     * The absence checks in applyScope, which are the subtle half of the match.
     *
     * A finding about one person and an organization-wide finding are about
     * different parties even inside the same organization, because the ladder
     * stops at the first answer it finds.
     */
    public function test_an_entity_scoped_signal_does_not_group_with_an_organization_scoped_one(): void
    {
        $first = $this->signal(self::TENANT, scope: [
            'related_entity_type' => 'Person', 'related_entity_id' => 'p-1', 'org_id' => 'org-1',
        ]);
        $this->openCases();
        $this->resolve($first);

        // Same rule, same organization, but scoped to the organization rather
        // than to the person.
        $this->signal(self::TENANT, scope: ['org_id' => 'org-1']);
        $this->openCases();

        self::assertSame(2, $this->caseCount(), 'Entity scope and organization scope are different parties.');
    }

    public function test_a_signal_with_no_affected_party_never_groups(): void
    {
        $first = $this->signal(self::TENANT, scope: []);
        $this->openCases();
        $this->resolve($first);

        $this->signal(self::TENANT, scope: []);
        $this->openCases();

        // Two unknowns are not a match. Grouping them would merge unrelated
        // findings on a shared rule name alone.
        self::assertSame(2, $this->caseCount());
    }

    public function test_a_resolved_case_does_not_attract_the_recurrence(): void
    {
        $first = $this->signal(self::TENANT);
        $this->openCases();

        DB::table('hpbrain_cases')->where('id', $this->onlyCaseId())->update(['status' => 'resolved']);
        $this->resolve($first);

        $this->signal(self::TENANT);
        $this->openCases();

        // A finished investigation must not be reopened by the back door.
        self::assertSame(2, $this->caseCount());
    }

    public function test_an_investigating_case_still_attracts_the_recurrence(): void
    {
        $first = $this->signal(self::TENANT);
        $this->openCases();

        $caseId = $this->onlyCaseId();
        DB::table('hpbrain_cases')->where('id', $caseId)->update(['status' => 'investigating']);
        $this->resolve($first);

        $this->signal(self::TENANT);
        $this->openCases();

        self::assertSame(1, $this->caseCount(), 'A case being worked is exactly the one to join.');
        self::assertCount(2, $this->links($caseId));
    }

    /* ─────────────── tenant isolation ─────────────── */

    public function test_grouping_never_crosses_tenants(): void
    {
        // Identical rule and identical org id, in two different tenants.
        $first = $this->signal(self::TENANT, scope: ['org_id' => 'org-shared']);
        $this->openCases(self::TENANT);
        $this->resolve($first);

        $this->signal(self::OTHER, scope: ['org_id' => 'org-shared']);
        $this->openCases(self::OTHER);

        self::assertSame(1, $this->caseCount(self::TENANT));
        self::assertSame(1, $this->caseCount(self::OTHER));

        // Each case holds only its own tenant's signal.
        foreach ([self::TENANT, self::OTHER] as $tenantId) {
            $links = $this->links($this->onlyCaseId($tenantId));

            self::assertCount(1, $links, "Tenant {$tenantId} must not have absorbed the other's signal.");
            self::assertSame($tenantId, $links[0]->tenant_id);
        }
    }
}
