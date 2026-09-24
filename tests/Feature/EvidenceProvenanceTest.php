<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Invariant 1 at the only door evidence enters through: no evidence without
 * provenance, and no provenance the server did not verify.
 *
 * The write path is what is under test, not the schema. `provenance JSON NOT
 * NULL DEFAULT '{}'` accepts an empty object, so a row can satisfy every
 * database constraint and still prove nothing — these tests assert on what is
 * actually stored, not on the status code alone.
 *
 * SCHEMA IS BUILT HERE, not by running migrations. The suite is pinned to
 * in-memory SQLite (phpunit.xml) while every hpbrain_ migration is raw MySQL
 * DDL that SQLite cannot parse. Same convention as DecisionApprovalTest and
 * ApiAuthorizationTest.
 */
final class EvidenceProvenanceTest extends TestCase
{
    private const TENANT = 'tenant-alpha';
    private const ANALYST = 'user-analyst';

    private string $signalId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signalId = Uuid::uuid4()->toString();

        Schema::create('hpbrain_signals', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('source')->nullable();
            $t->string('status')->default('new');
        });

        Schema::create('hpbrain_evidence', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('signal_id', 36)->nullable();
            $t->text('source');
            $t->string('evidence_type', 36)->default('observation');
            $t->text('content');
            $t->text('provenance');
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('hash');
            $t->integer('version')->default(1);
            $t->text('status')->default('active');
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
            $t->index(['tenant_id', 'signal_id'], 'idx_evidence_signal');
        });

        Schema::create('hpbrain_event_store', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('type');
            $t->string('tenant_id', 36);
            $t->string('entity_type');
            $t->string('entity_id', 36);
            $t->string('actor_id', 36);
            $t->text('payload');
            $t->string('correlation_id', 36)->nullable();
            $t->string('causation_id', 36)->nullable();
            $t->string('idempotency_key', 36)->nullable()->unique();
            $t->string('status')->default('pending');
            $t->integer('retry_count')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        DB::table('hpbrain_signals')->insert([
            'id' => $this->signalId, 'tenant_id' => self::TENANT, 'source' => 'erp', 'status' => 'new',
        ]);
    }

    private function auth(string $role, string $id = self::ANALYST, string $tenant = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => $id, 'tenantId' => $tenant, 'role' => $role,
        ])];
    }

    /** A complete, well-formed write. Individual keys are overridden per test. */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'tenantId'     => self::TENANT,
            'signalId'     => $this->signalId,
            'source'       => 'fee_ledger_export',
            'evidenceType' => 'document',
            'content'      => ['note' => 'Grade 9 arrears concentrated in 14 families.'],
            'provenance'   => [
                'source'     => 'ERP fee ledger, nightly export',
                'ts'         => '2026-07-20T09:00:00Z',
                'confidence' => 0.82,
            ],
            'confidence'   => 0.75,
        ], $overrides);
    }

    private function collect(array $overrides = [], string $role = 'analyst'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/evidence', $this->payload($overrides), $this->auth($role));
    }

    // ---- The complete write -------------------------------------------------

    public function test_a_complete_write_stores_full_provenance_and_a_hash(): void
    {
        $response = $this->collect();

        $response->assertStatus(201);

        // Returned as objects, not encoded strings — a create must answer the
        // way a read does.
        self::assertIsArray($response->json('content'));
        self::assertIsArray($response->json('provenance'));

        $stored = DB::table('hpbrain_evidence')->where('id', $response->json('id'))->first();
        $provenance = json_decode((string) $stored->provenance, true);

        self::assertNotSame([], $provenance, 'A provenance of {} is the defect, not a pass.');
        self::assertSame('ERP fee ledger, nightly export', $provenance['source']);
        self::assertSame('2026-07-20T09:00:00Z', $provenance['ts']);
        self::assertSame(0.82, $provenance['confidence']);

        self::assertNotSame('', (string) $stored->hash);
        self::assertSame(64, strlen((string) $stored->hash), 'SHA-256 is 64 hex characters.');
        self::assertSame(self::ANALYST, (string) $stored->created_by);
    }

    // ---- Provenance is not optional, and not a blob -------------------------

    public function test_a_write_without_provenance_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['provenance']);

        $this->postJson('/api/v1/evidence', $payload, $this->auth('analyst'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('provenance');

        self::assertSame(0, DB::table('hpbrain_evidence')->count());
    }

    public function test_provenance_without_an_observation_timestamp_is_rejected(): void
    {
        // Present but incomplete. This is the case a blob rule would wave
        // through: `provenance` is an array, so 'required|array' is satisfied.
        $this->collect(['provenance' => ['source' => 'ERP fee ledger', 'confidence' => 0.82]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provenance.ts');
    }

    public function test_a_provenance_confidence_outside_zero_to_one_is_rejected(): void
    {
        $this->collect(['provenance' => [
            'source' => 'ERP fee ledger', 'ts' => '2026-07-20T09:00:00Z', 'confidence' => 1.5,
        ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provenance.confidence');
    }

    // ---- Ownership ----------------------------------------------------------

    public function test_evidence_cannot_be_attached_to_another_tenants_signal(): void
    {
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_signals')->insert([
            'id' => $foreign, 'tenant_id' => 'tenant-beta', 'source' => 'erp', 'status' => 'new',
        ]);

        // The FK would accept this: the signal exists. Ownership is a separate
        // question, and the answer is no.
        $this->collect(['signalId' => $foreign])
            ->assertStatus(422)
            ->assertJson(['error' => 'signal_not_found']);

        self::assertSame(0, DB::table('hpbrain_evidence')->count());
    }

    // ---- The hash is the server's, always -----------------------------------

    public function test_a_client_supplied_hash_is_ignored(): void
    {
        $response = $this->collect(['hash' => str_repeat('0', 64)]);

        $response->assertStatus(201);

        $stored = DB::table('hpbrain_evidence')->where('id', $response->json('id'))->first();

        self::assertNotSame(str_repeat('0', 64), (string) $stored->hash);

        // The server's hash is reproducible from exactly what it stored — that
        // is the property that makes tampering detectable.
        self::assertSame(
            hash('sha256', $stored->content.'|'.$stored->provenance),
            (string) $stored->hash
        );
    }

    // ---- Confidence is inherited, never invented ----------------------------

    public function test_omitting_confidence_inherits_it_from_provenance(): void
    {
        $payload = $this->payload();
        unset($payload['confidence']);

        $response = $this->postJson('/api/v1/evidence', $payload, $this->auth('analyst'));
        $response->assertStatus(201);

        $stored = DB::table('hpbrain_evidence')->where('id', $response->json('id'))->first();

        self::assertSame(0.82, (float) $stored->confidence);
        self::assertNotSame(0.5, (float) $stored->confidence, 'The column default is a fabricated confidence.');
    }

    // ---- The event ----------------------------------------------------------

    public function test_exactly_one_evidence_recorded_event_is_appended(): void
    {
        $id = $this->collect()->assertStatus(201)->json('id');

        $events = DB::table('hpbrain_event_store')
            ->where('entity_id', $id)->where('type', 'EvidenceRecorded')->get();

        self::assertCount(1, $events);
        // The signal is the thread, not the evidence.
        self::assertSame($this->signalId, $events[0]->correlation_id);
        self::assertSame(self::ANALYST, $events[0]->actor_id);
        self::assertSame('Evidence', $events[0]->entity_type);

        // One event per evidence row, not per tenant: a second piece of
        // evidence on the same signal gets its own.
        $second = $this->collect()->assertStatus(201)->json('id');

        self::assertSame(2, DB::table('hpbrain_event_store')->where('type', 'EvidenceRecorded')->count());
        self::assertNotSame($id, $second);
    }

    // ---- Reads --------------------------------------------------------------

    public function test_for_signal_returns_only_that_signals_evidence(): void
    {
        $other = Uuid::uuid4()->toString();
        DB::table('hpbrain_signals')->insert([
            'id' => $other, 'tenant_id' => self::TENANT, 'source' => 'erp', 'status' => 'new',
        ]);

        $mine   = $this->collect()->json('id');
        $theirs = $this->collect(['signalId' => $other])->json('id');

        $response = $this->getJson(
            '/api/v1/evidence/'.self::TENANT.'/signal/'.$this->signalId, $this->auth('analyst')
        );

        $response->assertStatus(200);
        $ids = array_column($response->json(), 'id');

        self::assertSame([$mine], $ids);
        self::assertNotContains($theirs, $ids);
    }

    // ---- Authorization ------------------------------------------------------

    public function test_a_viewer_cannot_record_evidence(): void
    {
        $this->collect([], 'viewer')
            ->assertStatus(403)
            ->assertJson(['error' => 'forbidden', 'required' => 'create']);

        self::assertSame(0, DB::table('hpbrain_evidence')->count());
    }
}
