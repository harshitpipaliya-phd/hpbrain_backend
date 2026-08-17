<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Cases\CaseSignalLinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * The single writer for a case's signal relationships, in isolation.
 *
 * WHAT THESE TESTS ARE FOR. Not "does it insert a row" — the two properties that
 * make a second copy of the primary link safe to have at all:
 *
 *   1. BOTH COPIES MOVE TOGETHER OR NEITHER DOES. hpbrain_cases.signal_id and
 *      the role='primary' junction row are two records of one fact, and nothing
 *      in the database enforces they agree. If the write is not atomic they will
 *      eventually disagree, and a disagreeing primary means ExplainVerb reasons
 *      about one signal while every aggregate view reports another.
 *   2. THE RELATED PATH NEVER TOUCHES THE COLUMN. That column is what seven
 *      existing readers believe the case is about. A related signal that
 *      overwrote it would silently redirect all of them.
 *
 * The atomicity test is a real rollback, not a mocked one — see
 * the_primary_write_is_atomic() for how the failure is forced.
 */
final class CaseSignalLinkerTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-one';

    private const OTHER = 'tenant-two';

    private const CASE_ID = 'case-0000-0000-0000-000000000001';

    private const SIGNAL_A = 'sig-a0000-0000-0000-000000000001';

    private const SIGNAL_B = 'sig-b0000-0000-0000-000000000002';

    private const ACTOR = 'test-actor';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();

        $this->makeSignal(self::SIGNAL_A, self::TENANT);
        $this->makeSignal(self::SIGNAL_B, self::TENANT);
        $this->makeCase(self::CASE_ID, self::TENANT, null);
    }

    private function linker(): CaseSignalLinker
    {
        return app(CaseSignalLinker::class);
    }

    private function makeSignal(string $id, string $tenantId): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'source' => 'test',
            'classification' => 'operational', 'priority' => 'medium', 'severity' => 'low',
            'confidence' => 0.9, 'status' => 'new', 'created_by' => 'test',
            'created_date' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function makeCase(string $id, string $tenantId, ?string $signalId): void
    {
        DB::table('hpbrain_cases')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'signal_id' => $signalId,
            'title' => 'A case', 'status' => 'open', 'created_by' => 'test',
            'created_date' => now()->format('Y-m-d H:i:s'),
            'updated_date' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function columnSignal(string $caseId = self::CASE_ID): ?string
    {
        return DB::table('hpbrain_cases')->where('id', $caseId)->value('signal_id');
    }

    /** @return array<int, object> */
    private function links(string $caseId = self::CASE_ID): array
    {
        return DB::table('hpbrain_case_signals')->where('case_id', $caseId)->get()->all();
    }

    /* ─────────────────────────── the primary path ─────────────────────────── */

    public function test_linking_a_primary_writes_the_column_and_the_junction_together(): void
    {
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);

        // The authoritative copy — what ExplainVerb reads.
        self::assertSame(self::SIGNAL_A, $this->columnSignal());

        $links = $this->links();

        self::assertCount(1, $links);
        self::assertSame(self::SIGNAL_A, $links[0]->signal_id);
        self::assertSame(CaseSignalLinker::PRIMARY, $links[0]->role);
        self::assertSame(self::TENANT, $links[0]->tenant_id);
        // Attribution, so an auto-linked signal is distinguishable from one a
        // person attached.
        self::assertSame(self::ACTOR, $links[0]->linked_by);
    }

    public function test_relinking_the_same_primary_is_idempotent(): void
    {
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, 'someone-else');

        self::assertSame(self::SIGNAL_A, $this->columnSignal());
        self::assertCount(1, $this->links(), 'A repeated primary link must not stack a second row.');
        // The original attribution survives: the link was made once, by the
        // first caller, and a no-op must not rewrite who made it.
        self::assertSame(self::ACTOR, $this->links()[0]->linked_by);
    }

    public function test_repointing_a_primary_to_a_different_signal_is_refused(): void
    {
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);

        try {
            $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_B, self::ACTOR);
            self::fail('Repointing a case to a different primary signal must be refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('case_already_has_primary_signal', $e->getMessage());
        }

        // The refusal has to leave everything exactly as it was: what a case is
        // about is the fact every hypothesis on it was reasoned from.
        self::assertSame(self::SIGNAL_A, $this->columnSignal());
        self::assertCount(1, $this->links());
    }

    /**
     * A REAL ROLLBACK, NOT A MOCKED ONE.
     *
     * The junction table is dropped after the preconditions would pass, so the
     * insert inside the transaction throws while the column update ahead of it
     * has already succeeded. That is the exact failure shape the transaction
     * exists for, and the only way to observe it without stubbing the very
     * database call under test.
     *
     * It also proves the ordering: the column is updated FIRST, so if the
     * transaction did not roll back, the assertion below would find SIGNAL_A
     * written to a case whose junction row was never created — a silent
     * disagreement between the two copies, which is the whole failure mode this
     * class exists to prevent.
     */
    public function test_the_primary_write_is_atomic(): void
    {
        self::assertNull($this->columnSignal(), 'Precondition: the case starts with no signal.');

        Schema::drop('hpbrain_case_signals');

        try {
            $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);
            self::fail('The junction insert must fail once its table is gone.');
        } catch (\Throwable $e) {
            self::assertStringContainsStringIgnoringCase('hpbrain_case_signals', $e->getMessage());
        }

        // THE ASSERTION THIS TEST EXISTS FOR. The column update ran first and
        // must have been rolled back with the failed insert.
        self::assertNull(
            $this->columnSignal(),
            'hpbrain_cases.signal_id must roll back when the junction write fails.'
        );
    }

    /* ─────────────────────────── the related path ─────────────────────────── */

    public function test_linking_a_related_signal_never_touches_the_column(): void
    {
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);
        $this->linker()->linkRelated(self::TENANT, self::CASE_ID, self::SIGNAL_B, self::ACTOR);

        // The seven existing readers still see the case as being about A.
        self::assertSame(self::SIGNAL_A, $this->columnSignal());

        $roles = collect($this->links())->pluck('role', 'signal_id')->all();

        self::assertSame(CaseSignalLinker::PRIMARY, $roles[self::SIGNAL_A]);
        self::assertSame(CaseSignalLinker::RELATED, $roles[self::SIGNAL_B]);
    }

    public function test_a_related_signal_can_be_attached_before_any_primary_exists(): void
    {
        // A case whose primary is not yet decided can still accumulate signals;
        // the column stays null and says so.
        $this->linker()->linkRelated(self::TENANT, self::CASE_ID, self::SIGNAL_B, self::ACTOR);

        self::assertNull($this->columnSignal());
        self::assertCount(1, $this->links());
        self::assertSame(CaseSignalLinker::RELATED, $this->links()[0]->role);
    }

    public function test_relinking_the_same_related_signal_is_idempotent(): void
    {
        $this->linker()->linkRelated(self::TENANT, self::CASE_ID, self::SIGNAL_B, self::ACTOR);
        $this->linker()->linkRelated(self::TENANT, self::CASE_ID, self::SIGNAL_B, self::ACTOR);

        self::assertCount(1, $this->links());
    }

    public function test_a_signal_cannot_be_both_primary_and_related(): void
    {
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);

        try {
            $this->linker()->linkRelated(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);
            self::fail('A signal that is the primary must not also be linked as related.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('signal_is_already_primary', $e->getMessage());
        }

        self::assertCount(1, $this->links());
        self::assertSame(CaseSignalLinker::PRIMARY, $this->links()[0]->role);
    }

    public function test_a_related_link_does_not_promote_itself_on_a_second_call(): void
    {
        $this->linker()->linkRelated(self::TENANT, self::CASE_ID, self::SIGNAL_B, self::ACTOR);

        // Now try to make the SAME signal the primary. The column is free, so
        // this is allowed — but the existing junction row's role must not be
        // rewritten behind the caller's back by insertOrIgnore.
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_B, self::ACTOR);

        self::assertSame(self::SIGNAL_B, $this->columnSignal());
        self::assertCount(1, $this->links());
        self::assertSame(
            CaseSignalLinker::RELATED,
            $this->links()[0]->role,
            'insertOrIgnore must not silently flip an existing link\'s role; promotion is a separate operation.'
        );
    }

    /* ─────────────────────────── tenant safety ─────────────────────────── */

    public function test_a_signal_from_another_tenant_is_refused(): void
    {
        $foreign = 'sig-foreign-0000-0000-000000000003';
        $this->makeSignal($foreign, self::OTHER);

        // Both foreign keys would be satisfied — the signal row genuinely
        // exists. Only the tenant check stops this.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/signal_not_found_for_tenant/');

        $this->linker()->linkRelated(self::TENANT, self::CASE_ID, $foreign, self::ACTOR);
    }

    public function test_a_case_from_another_tenant_is_refused(): void
    {
        $foreignCase = 'case-foreign-0000-0000-00000000004';
        $this->makeCase($foreignCase, self::OTHER, null);

        try {
            $this->linker()->linkPrimary(self::TENANT, $foreignCase, self::SIGNAL_A, self::ACTOR);
            self::fail('A case belonging to another tenant must be refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('case_not_found_for_tenant', $e->getMessage());
        }

        self::assertNull($this->columnSignal($foreignCase), 'Nothing may be written to the other tenant\'s case.');
        self::assertCount(0, $this->links($foreignCase));
    }

    public function test_an_unknown_signal_is_refused_before_anything_is_written(): void
    {
        try {
            $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, 'sig-does-not-exist', self::ACTOR);
            self::fail('An unknown signal must be refused.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('signal_not_found_for_tenant', $e->getMessage());
        }

        self::assertNull($this->columnSignal());
        self::assertCount(0, $this->links());
    }

    /* ─────────────────────────── reading back ─────────────────────────── */

    public function test_signals_for_returns_the_primary_first(): void
    {
        // Related written FIRST, so ordering by insertion would put it on top
        // and the assertion below would fail.
        $this->linker()->linkRelated(self::TENANT, self::CASE_ID, self::SIGNAL_B, self::ACTOR);
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);

        $signals = $this->linker()->signalsFor(self::TENANT, self::CASE_ID);

        self::assertCount(2, $signals);
        self::assertSame(self::SIGNAL_A, $signals[0]['signalId']);
        self::assertSame(CaseSignalLinker::PRIMARY, $signals[0]['role']);
        self::assertSame(self::SIGNAL_B, $signals[1]['signalId']);
        self::assertSame(CaseSignalLinker::RELATED, $signals[1]['role']);
    }

    public function test_signals_for_is_scoped_to_the_tenant(): void
    {
        $this->linker()->linkPrimary(self::TENANT, self::CASE_ID, self::SIGNAL_A, self::ACTOR);

        self::assertSame([], $this->linker()->signalsFor(self::OTHER, self::CASE_ID));
    }
}
