<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Jwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * The executor registry: who, or what, is allowed to do the work.
 *
 * Two properties are under test beyond ordinary validation — that a human
 * executor names a real person in this tenant, and that current_workload is
 * server-derived. The second is a security property, not a data-quality one: an
 * executor that can set its own workload can be handed work it has no capacity
 * for.
 *
 * SCHEMA IS BUILT HERE — in-memory SQLite (phpunit.xml) cannot run the raw
 * MySQL migrations.
 */
final class ExecutorValidationTest extends TestCase
{
    private const TENANT = 'tenant-alpha';
    private const ACTOR  = 'user-analyst';

    private string $personId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personId = Uuid::uuid4()->toString();

        // NOTE: no created_by column. hpbrain_executors genuinely has none —
        // the controller must not write one.
        Schema::create('hpbrain_executors', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('executor_type');
            $t->text('name');
            $t->string('person_id', 36)->nullable();
            $t->text('capability_tags');
            $t->decimal('trust_level', 5, 2)->default(0.5);
            $t->integer('max_concurrent')->default(1);
            $t->integer('current_workload')->default(0);
            $t->boolean('available')->default(true);
            $t->text('status')->default('active');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_people', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('first_name');
            $t->text('last_name');
        });

        DB::table('hpbrain_people')->insert([
            'id' => $this->personId, 'tenant_id' => self::TENANT,
            'first_name' => 'Ada', 'last_name' => 'Bursar',
        ]);
    }

    private function auth(string $role): array
    {
        return ['Authorization' => 'Bearer '.Jwt::issueAccess([
            'id' => self::ACTOR, 'tenantId' => self::TENANT, 'role' => $role,
        ])];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'tenantId'       => self::TENANT,
            'executorType'   => 'human',
            'name'           => 'Bursar — fee recovery desk',
            'personId'       => $this->personId,
            'capabilityTags' => ['fee-recovery', 'parent-communication'],
            'trustLevel'     => 0.8,
            'maxConcurrent'  => 5,
        ], $overrides);
    }

    private function register(array $overrides = [], string $role = 'analyst'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/executors', $this->payload($overrides), $this->auth($role));
    }

    // ---- The write actually carries the payload -----------------------------

    public function test_a_complete_write_stores_every_submitted_field(): void
    {
        $response = $this->register();
        $response->assertStatus(201);

        $stored = DB::table('hpbrain_executors')->where('id', $response->json('id'))->first();

        self::assertSame(self::TENANT, $stored->tenant_id);
        self::assertSame('human', $stored->executor_type);
        self::assertSame('Bursar — fee recovery desk', $stored->name);
        self::assertSame($this->personId, $stored->person_id);
        self::assertSame(['fee-recovery', 'parent-communication'], json_decode((string) $stored->capability_tags, true));
        self::assertSame(0.8, (float) $stored->trust_level);
        self::assertSame(5, (int) $stored->max_concurrent);
        self::assertSame('active', $stored->status);

        // The Multi-Agent Monitor calls .map on this; a string has no .map.
        self::assertIsArray($response->json('capability_tags'));
    }

    /** @return array<string, array{0: string}> */
    public static function requiredFields(): array
    {
        return [
            'executorType' => ['executorType'],
            'name'         => ['name'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_a_write_missing_a_required_field_is_rejected(string $field): void
    {
        $payload = $this->payload();
        unset($payload[$field]);

        $this->postJson('/api/v1/executors', $payload, $this->auth('analyst'))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        self::assertSame(0, DB::table('hpbrain_executors')->count());
    }

    public function test_an_unknown_executor_type_is_rejected(): void
    {
        $this->register(['executorType' => 'daemon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('executorType');
    }

    // ---- A human executor names a human --------------------------------------

    public function test_a_human_executor_without_a_person_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['personId']);

        $this->postJson('/api/v1/executors', $payload, $this->auth('analyst'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('personId');

        self::assertSame(0, DB::table('hpbrain_executors')->count());
    }

    public function test_a_system_executor_needs_no_person(): void
    {
        $payload = $this->payload(['executorType' => 'system', 'name' => 'Nightly fee-ledger job']);
        unset($payload['personId']);

        $response = $this->postJson('/api/v1/executors', $payload, $this->auth('analyst'));

        $response->assertStatus(201);
        self::assertNull($response->json('person_id'));
    }

    public function test_a_person_from_another_tenant_is_rejected(): void
    {
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_people')->insert([
            'id' => $foreign, 'tenant_id' => 'tenant-beta', 'first_name' => 'Not', 'last_name' => 'Ours',
        ]);

        $this->register(['personId' => $foreign])
            ->assertStatus(422)
            ->assertJson(['error' => 'person_not_found']);

        self::assertSame(0, DB::table('hpbrain_executors')->count());
    }

    // ---- Workload is derived, never declared ---------------------------------

    public function test_a_client_supplied_workload_is_ignored(): void
    {
        // An executor that can set its own workload can understate its load and
        // be routed work it cannot take.
        $response = $this->register(['currentWorkload' => 99, 'current_workload' => 99]);

        $response->assertStatus(201);

        $stored = DB::table('hpbrain_executors')->where('id', $response->json('id'))->first();

        self::assertSame(0, (int) $stored->current_workload);
    }

    public function test_defaults_are_applied_when_optional_fields_are_omitted(): void
    {
        $response = $this->postJson('/api/v1/executors', [
            'tenantId'     => self::TENANT,
            'executorType' => 'ai',
            'name'         => 'Draft summariser',
        ], $this->auth('analyst'));

        $response->assertStatus(201);

        $stored = DB::table('hpbrain_executors')->where('id', $response->json('id'))->first();

        self::assertSame(0.5, (float) $stored->trust_level);
        self::assertSame(1, (int) $stored->max_concurrent);
        self::assertSame([], json_decode((string) $stored->capability_tags, true));
    }

    /** hpbrain_executors has no created_by column; writing one would throw. */
    public function test_the_insert_does_not_invent_a_created_by_column(): void
    {
        self::assertFalse(Schema::hasColumn('hpbrain_executors', 'created_by'));

        $this->register()->assertStatus(201);
    }

    // ---- Authorization --------------------------------------------------------

    public function test_a_viewer_cannot_register_an_executor(): void
    {
        $this->register([], 'viewer')
            ->assertStatus(403)
            ->assertJson(['error' => 'forbidden', 'required' => 'create']);

        self::assertSame(0, DB::table('hpbrain_executors')->count());
    }
}
