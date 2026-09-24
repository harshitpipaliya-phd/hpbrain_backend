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
 * Golden-path step 5, and Invariant 3: every action is executable.
 *
 * `title TEXT NOT NULL` has no default, so under the old empty rules() this
 * endpoint either threw (strict mode) or wrote a titleless recommendation. The
 * complete-write test reads the row back, which is the only assertion that
 * would have failed against the original code.
 *
 * SCHEMA IS BUILT HERE — in-memory SQLite (phpunit.xml) cannot run the raw
 * MySQL migrations.
 */
final class RecommendationValidationTest extends TestCase
{
    private const TENANT = 'tenant-alpha';
    private const ACTOR  = 'user-analyst';

    private string $stepId;
    private string $esoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stepId = Uuid::uuid4()->toString();
        $this->esoId  = Uuid::uuid4()->toString();

        Schema::create('hpbrain_recommendations', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('reasoning_step_id', 36)->nullable();
            $t->text('category')->default('watch');
            $t->text('title');
            $t->text('description')->nullable();
            $t->text('priority')->default('medium');
            $t->decimal('confidence', 6, 4)->default(0.5);
            $t->text('impact')->nullable();
            $t->text('cost')->nullable();
            $t->text('risk')->nullable();
            $t->text('dependencies');
            $t->text('status')->default('pending');
            // Added by 2026_07_30_000100 — Invariant 3 is now data, not just
            // validation.
            $t->string('eso_id', 36)->nullable();
            $t->text('created_by');
            $t->timestamp('created_date')->nullable();
        });

        Schema::create('hpbrain_reasoning_steps', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->text('description')->nullable();
        });

        Schema::create('hpbrain_eso_definitions', function ($t) {
            $t->string('id', 36)->primary();
            $t->string('tenant_id', 36);
            $t->string('eso_code');
            $t->string('name');
        });

        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $this->stepId, 'tenant_id' => self::TENANT, 'description' => 'Cadence, not capacity.',
        ]);

        DB::table('hpbrain_eso_definitions')->insert([
            'id' => $this->esoId, 'tenant_id' => self::TENANT,
            'eso_code' => 'ESO-FEE-REMIND', 'name' => 'Targeted fee reminder',
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
            'tenantId'        => self::TENANT,
            'reasoningStepId' => $this->stepId,
            'category'        => 'investigate',
            'title'           => 'Review reminder cadence for Grade 9 families',
            'description'     => 'Arrears track the reminder schedule, not household income.',
            'priority'        => 'high',
            'confidence'      => 0.71,
            'impact'          => 'Recovers an estimated 8% of outstanding fees.',
            'cost'            => 'Two staff hours per cycle.',
            'risk'            => 'Reminder fatigue if cadence is raised again within a term.',
            'dependencies'    => ['fee-ledger-export'],
        ], $overrides);
    }

    private function recommend(array $overrides = [], string $role = 'analyst'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/recommendations', $this->payload($overrides), $this->auth($role));
    }

    // ---- The write actually carries the payload -----------------------------

    public function test_a_complete_write_stores_every_submitted_field(): void
    {
        $response = $this->recommend();
        $response->assertStatus(201);

        $stored = DB::table('hpbrain_recommendations')->where('id', $response->json('id'))->first();

        self::assertSame(self::TENANT, $stored->tenant_id);
        self::assertSame($this->stepId, $stored->reasoning_step_id);
        self::assertSame('investigate', $stored->category);
        self::assertSame('Review reminder cadence for Grade 9 families', $stored->title);
        self::assertSame('Arrears track the reminder schedule, not household income.', $stored->description);
        self::assertSame('high', $stored->priority);
        self::assertSame(0.71, (float) $stored->confidence);
        self::assertSame('Recovers an estimated 8% of outstanding fees.', $stored->impact);
        self::assertSame('Two staff hours per cycle.', $stored->cost);
        self::assertSame('Reminder fatigue if cadence is raised again within a term.', $stored->risk);
        self::assertSame(['fee-ledger-export'], json_decode((string) $stored->dependencies, true));
        self::assertSame('pending', $stored->status);
        self::assertSame(self::ACTOR, $stored->created_by);

        self::assertIsArray($response->json('dependencies'));
    }

    /** @return array<string, array{0: string}> */
    public static function requiredFields(): array
    {
        return [
            'reasoningStepId' => ['reasoningStepId'],
            'category'        => ['category'],
            'title'           => ['title'],
            'priority'        => ['priority'],
            'confidence'      => ['confidence'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_a_write_missing_a_required_field_is_rejected(string $field): void
    {
        $payload = $this->payload();
        unset($payload[$field]);

        $this->postJson('/api/v1/recommendations', $payload, $this->auth('analyst'))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        self::assertSame(0, DB::table('hpbrain_recommendations')->count());
    }

    public function test_a_reasoning_step_from_another_tenant_is_rejected(): void
    {
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_reasoning_steps')->insert([
            'id' => $foreign, 'tenant_id' => 'tenant-beta', 'description' => 'theirs',
        ]);

        $this->recommend(['reasoningStepId' => $foreign])
            ->assertStatus(422)
            ->assertJson(['error' => 'reasoning_step_not_found']);
    }

    // ---- Invariant 3: an action names its ESO --------------------------------

    public function test_an_actionable_category_without_an_eso_binding_is_rejected(): void
    {
        // "Intervene" with nothing to execute is advice wearing an action's
        // label.
        $this->recommend(['category' => 'intervene'])
            ->assertStatus(422)
            ->assertJson(['error' => 'eso_binding_required', 'category' => 'intervene']);

        $this->recommend(['category' => 'escalate'])
            ->assertStatus(422)
            ->assertJson(['error' => 'eso_binding_required', 'category' => 'escalate']);

        self::assertSame(0, DB::table('hpbrain_recommendations')->count());
    }

    public function test_an_actionable_category_with_an_eso_binding_is_accepted(): void
    {
        $this->recommend(['category' => 'intervene', 'esoId' => $this->esoId])
            ->assertStatus(201)
            ->assertJson(['category' => 'intervene']);
    }

    public function test_a_watch_category_needs_no_eso_binding(): void
    {
        // Watching is not acting. Requiring an ESO here would make the
        // invariant a nuisance rather than a control.
        $this->recommend(['category' => 'watch'])
            ->assertStatus(201)
            ->assertJson(['category' => 'watch']);
    }

    public function test_an_eso_from_another_tenant_is_rejected(): void
    {
        $foreign = Uuid::uuid4()->toString();
        DB::table('hpbrain_eso_definitions')->insert([
            'id' => $foreign, 'tenant_id' => 'tenant-beta', 'eso_code' => 'X', 'name' => 'theirs',
        ]);

        $this->recommend(['category' => 'intervene', 'esoId' => $foreign])
            ->assertStatus(422)
            ->assertJson(['error' => 'eso_not_found']);
    }

    /**
     * INVERTED, exactly as the previous version of this test instructed.
     *
     * It used to assert that `eso_id` did NOT exist and carried the message
     * "Module 7 has landed: persist esoId and invert this assertion" — a
     * deliberate tripwire so the gap could not be quietly forgotten. The column
     * landed in 2026_07_30_000100, the tripwire fired, and this is the
     * inversion: Invariant 3 is now a property of the row rather than of the
     * request that created it.
     */
    public function test_the_eso_binding_is_persisted(): void
    {
        $id = $this->recommend(['category' => 'intervene', 'esoId' => $this->esoId])->json('id');

        self::assertTrue(Schema::hasColumn('hpbrain_recommendations', 'eso_id'));
        self::assertSame(
            $this->esoId,
            DB::table('hpbrain_recommendations')->where('id', $id)->value('eso_id')
        );
    }

    // ---- Authorization --------------------------------------------------------

    public function test_a_viewer_cannot_create_a_recommendation(): void
    {
        $this->recommend([], 'viewer')
            ->assertStatus(403)
            ->assertJson(['error' => 'forbidden', 'required' => 'create']);

        self::assertSame(0, DB::table('hpbrain_recommendations')->count());
    }
}
