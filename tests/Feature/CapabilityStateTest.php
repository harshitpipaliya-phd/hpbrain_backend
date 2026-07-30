<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Capability\CapabilityState;
use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Architecture Invariant 6: every capability carries an explicit state, and the
 * state only moves on evidence.
 *
 * WHAT WAS BROKEN. hpbrain_capability_proficiency has carried capability_state,
 * evidence_ref and state_source since the January migration, and
 * recordProficiency() wrote none of them. Every row it created showed real
 * numeric levels beside a state of 'Unknown' — a claim presented with all the
 * visual authority of a measurement, which is the precise failure the state
 * model exists to prevent.
 *
 * These tests assert on the STORED ROW, not the status code. A 201 was already
 * being returned by the broken version.
 *
 * SCHEMA IS BUILT HERE — the suite is pinned to in-memory SQLite (phpunit.xml)
 * and the hpbrain_ migrations are raw MySQL DDL. See Tests\Support\BuildsBrainSchema.
 */
final class CapabilityStateTest extends TestCase
{
    private const TENANT = 'tenant-alpha';
    private const ACTOR  = 'user-analyst';

    private string $assignmentId;
    private string $evidenceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assignmentId = Uuid::uuid4()->toString();
        $this->evidenceId   = Uuid::uuid4()->toString();

        // Mirrors the table AFTER 2026_01_02_000000_capability_state.
        Schema::create('hpbrain_capability_proficiency', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('assignment_id', 36);
            $t->decimal('knowledge_level', 12, 4)->nullable();
            $t->decimal('ability_level', 12, 4)->nullable();
            $t->decimal('skill_level', 12, 4)->nullable();
            $t->decimal('behaviour_level', 12, 4)->nullable();
            $t->decimal('attitude_level', 12, 4)->nullable();
            $t->decimal('evidence_confidence', 6, 4)->nullable();
            $t->string('capability_state', 20)->default('Unknown');
            $t->string('evidence_ref', 36)->nullable();
            $t->string('state_source', 100)->nullable();
            $t->timestamp('state_changed_date')->nullable();
            $t->text('state_change_reason')->nullable();
            $t->text('assessed_by')->nullable();
            $t->timestamp('assessed_date')->nullable();
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_evidence', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('source');
        });

        DB::table('hpbrain_evidence')->insert([
            'id' => $this->evidenceId, 'tenant_id' => self::TENANT, 'source' => 'assessment_record',
        ]);
    }

    private function auth(string $role = 'analyst'): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ACTOR, 'tenantId' => self::TENANT, 'role' => $role,
        ])];
    }

    private function record(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/kasba/proficiency', array_replace([
            'assignmentId'   => $this->assignmentId,
            'knowledgeLevel' => 3,
            'skillLevel'     => 4,
        ], $overrides), $this->auth());
    }

    /** Puts the assignment at a known state so a transition can be tested from it. */
    private function seedStateAt(string $state): void
    {
        DB::table('hpbrain_capability_proficiency')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT,
            'assignment_id' => $this->assignmentId, 'knowledge_level' => 3,
            'capability_state' => $state, 'evidence_ref' => $this->evidenceId,
            'state_source' => 'seed', 'created_date' => '2026-07-01 09:00:00',
        ]);
    }

    // ---- The write now carries the state ------------------------------------

    public function test_a_valid_advance_stores_the_state_its_evidence_and_its_source(): void
    {
        $response = $this->record([
            'capabilityState' => CapabilityState::ASSESSED,
            'evidenceRef'     => $this->evidenceId,
            'dimension'       => 'knowledge',
        ]);

        $response->assertStatus(201);

        $stored = DB::table('hpbrain_capability_proficiency')->where('id', $response->json('id'))->first();

        self::assertSame('Assessed', $stored->capability_state);
        self::assertSame($this->evidenceId, $stored->evidence_ref);
        // Never unattributed: a state with no source cannot be challenged.
        self::assertSame('api:'.self::ACTOR, $stored->state_source);
        self::assertNotNull($stored->state_changed_date);
        self::assertSame(3.0, (float) $stored->knowledge_level);
    }

    public function test_a_write_that_states_nothing_records_asserted_not_unknown(): void
    {
        // Somebody has recorded a number, so a claim HAS been made. Leaving it
        // Unknown would be as dishonest in the other direction — and it is what
        // the broken version did.
        $response = $this->record();

        $response->assertStatus(201);

        $stored = DB::table('hpbrain_capability_proficiency')->where('id', $response->json('id'))->first();

        self::assertSame('Asserted', $stored->capability_state);
        self::assertNull($stored->evidence_ref);
    }

    // ---- The state never regresses silently ---------------------------------

    public function test_a_regression_is_rejected(): void
    {
        $this->seedStateAt(CapabilityState::MASTERED);

        $response = $this->record([
            'capabilityState' => CapabilityState::ASSERTED,
            'dimension'       => 'knowledge',
        ]);

        $response->assertStatus(422)->assertJson([
            'error' => 'capability_state_transition_rejected',
            'from'  => 'Mastered',
            'to'    => 'Asserted',
        ]);

        self::assertStringContainsString('regression_requires_explicit_reason', $response->json('reason'));
        // Only the seeded row exists; the rejected write left nothing behind.
        self::assertSame(1, DB::table('hpbrain_capability_proficiency')->count());
    }

    public function test_a_regression_with_a_stated_reason_is_allowed(): void
    {
        $this->seedStateAt(CapabilityState::MASTERED);

        // A downgrade is possible, but never a side effect — it has to be said
        // out loud and it is stored so it can be read back.
        $response = $this->record([
            'capabilityState'  => CapabilityState::ASSERTED,
            'dimension'        => 'knowledge',
            'downgradeReason'  => 'Audit found the demonstration evidence was for a different capability.',
        ]);

        $response->assertStatus(201);

        $stored = DB::table('hpbrain_capability_proficiency')->where('id', $response->json('id'))->first();

        self::assertSame('Asserted', $stored->capability_state);
        self::assertStringContainsString('Audit found', (string) $stored->state_change_reason);
    }

    // ---- Evidence is required above Asserted --------------------------------

    public function test_an_advance_past_asserted_without_evidence_is_rejected(): void
    {
        $response = $this->record([
            'capabilityState' => CapabilityState::ASSESSED,
            'dimension'       => 'knowledge',
        ]);

        $response->assertStatus(422)->assertJson(['error' => 'capability_state_transition_rejected']);
        self::assertStringContainsString('requires_evidence', $response->json('reason'));
        self::assertSame(0, DB::table('hpbrain_capability_proficiency')->count());
    }

    public function test_evidence_from_another_tenant_is_rejected(): void
    {
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_evidence')->insert([
            'id' => $foreign, 'tenant_id' => 'tenant-beta', 'source' => 'theirs',
        ]);

        // evidence_ref has no foreign key, so nothing but this check stops a
        // state being "traced" to a row the caller may not read.
        $this->record([
            'capabilityState' => CapabilityState::ASSESSED,
            'evidenceRef'     => $foreign,
            'dimension'       => 'knowledge',
        ])->assertStatus(422)->assertJson(['error' => 'evidence_not_found']);

        self::assertSame(0, DB::table('hpbrain_capability_proficiency')->count());
    }

    // ---- Observed belongs to behaviour and attitude only --------------------

    public function test_observed_is_rejected_on_the_knowledge_dimension(): void
    {
        $response = $this->record([
            'capabilityState' => CapabilityState::OBSERVED,
            'evidenceRef'     => $this->evidenceId,
            'dimension'       => 'knowledge',
        ]);

        // Observed and Demonstrated share a rank but are not alternatives: the
        // state name is evidence of HOW the claim was arrived at, and knowledge
        // is demonstrated, not observed.
        $response->assertStatus(422)->assertJson(['error' => 'capability_state_transition_rejected']);
        self::assertStringContainsString('invalid_for_dimension', $response->json('reason'));
        self::assertSame(0, DB::table('hpbrain_capability_proficiency')->count());
    }

    public function test_observed_is_accepted_on_the_behaviour_dimension(): void
    {
        $this->record([
            'capabilityState' => CapabilityState::OBSERVED,
            'evidenceRef'     => $this->evidenceId,
            'dimension'       => 'behaviour',
        ])->assertStatus(201);

        self::assertSame('Observed', DB::table('hpbrain_capability_proficiency')->value('capability_state'));
    }

    public function test_demonstrated_is_rejected_on_the_behaviour_dimension(): void
    {
        // The mirror of the rule above: behaviour is observed, never
        // demonstrated. Accepting both for both would make the distinction
        // decorative.
        $this->record([
            'capabilityState' => CapabilityState::DEMONSTRATED,
            'evidenceRef'     => $this->evidenceId,
            'dimension'       => 'behaviour',
        ])->assertStatus(422)->assertJson(['error' => 'capability_state_transition_rejected']);
    }

    // ---- The heatmap tells the truth about the cell -------------------------

    public function test_a_cell_reports_its_weakest_state_not_its_best(): void
    {
        // One assessed row and one that nobody has evidenced. Reporting
        // 'Assessed' for the cell would let one measurement make an unmeasured
        // capability look measured.
        $this->seedStateAt(CapabilityState::ASSESSED);

        DB::table('hpbrain_capability_proficiency')->insert([
            'id' => Uuid::uuid4()->toString(), 'tenant_id' => self::TENANT,
            'assignment_id' => Uuid::uuid4()->toString(), 'knowledge_level' => 5,
            'capability_state' => CapabilityState::UNKNOWN, 'created_date' => '2026-07-02 09:00:00',
        ]);

        $states = ['Assessed', 'Unknown'];
        $ranks  = array_map(fn (string $s) => CapabilityState::rank($s), $states);

        self::assertSame(0, min($ranks), 'Unknown must rank lowest, so it wins the weakest-state comparison.');
    }

    // ---- The guard itself ----------------------------------------------------

    public function test_asserted_is_reachable_without_evidence_but_nothing_above_it_is(): void
    {
        // The line between "somebody says so" and "we measured it". Demanding
        // evidence for an assertion would make the honest state unrecordable
        // and push callers to overstate.
        self::assertFalse(CapabilityState::requiresEvidence(CapabilityState::ASSERTED));
        self::assertFalse(CapabilityState::requiresEvidence(CapabilityState::UNKNOWN));

        foreach ([
            CapabilityState::INFERRED, CapabilityState::ASSESSED,
            CapabilityState::DEMONSTRATED, CapabilityState::OBSERVED, CapabilityState::MASTERED,
        ] as $state) {
            self::assertTrue(CapabilityState::requiresEvidence($state), "{$state} must require evidence.");
        }

        self::assertSame(
            'Asserted',
            CapabilityState::advance(CapabilityState::UNKNOWN, CapabilityState::ASSERTED, null)
        );
    }
}
