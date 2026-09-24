<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * `GET /api/v1/signals/{tenantId}` and its window.
 *
 * WHY THE PARAMETERS EXIST. The Signals screen offers a 7 / 30 / 90 / all-time
 * selector and used to apply it in the browser, so choosing "last 7 days" still
 * transferred every signal the tenant had — on the school tenant, 15,002 rows to
 * render a handful. `since` moves that predicate into SQL.
 *
 * WHAT THESE TESTS PIN. Both parameters are additive: omitting them must return
 * exactly what the endpoint returned before, because three other screens still
 * call it that way. And the cap must be applied after the ordering, or a capped
 * response is an arbitrary slice rather than the newest N.
 */
final class SignalListWindowTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-window';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildBrainSchema();
    }

    private function token(string $tenantId = self::TENANT): array
    {
        return ['Authorization' => 'Bearer '.\App\Support\Jwt::issueAccess([
            'id' => 'u1', 'tenantId' => $tenantId, 'role' => 'admin',
        ])];
    }

    private function seedSignal(string $id, string $createdDate, string $tenantId = self::TENANT): void
    {
        DB::table('hpbrain_signals')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'org_id' => $tenantId,
            'source' => 'test',
            'classification' => 'test_case',
            'priority' => 'medium',
            'severity' => 'low',
            'confidence' => 0.5,
            'status' => 'new',
            'metadata' => json_encode([]),
            'created_by' => 'test',
            'created_date' => $createdDate,
        ]);
    }

    public function test_without_parameters_every_signal_is_returned(): void
    {
        $this->seedSignal('old', now()->subDays(200)->format('Y-m-d H:i:s'));
        $this->seedSignal('recent', now()->subDay()->format('Y-m-d H:i:s'));

        $response = $this->getJson('/api/v1/signals/'.self::TENANT, $this->token());

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    public function test_since_excludes_signals_raised_before_the_window(): void
    {
        $this->seedSignal('old', now()->subDays(200)->format('Y-m-d H:i:s'));
        $this->seedSignal('recent', now()->subDay()->format('Y-m-d H:i:s'));

        $response = $this->getJson(
            '/api/v1/signals/'.self::TENANT.'?since='.urlencode(now()->subDays(90)->toIso8601String()),
            $this->token(),
        );

        $response->assertStatus(200);
        $this->assertSame(['recent'], array_column($response->json(), 'id'));
    }

    public function test_limit_keeps_the_newest_rather_than_an_arbitrary_slice(): void
    {
        $this->seedSignal('oldest', now()->subDays(3)->format('Y-m-d H:i:s'));
        $this->seedSignal('middle', now()->subDays(2)->format('Y-m-d H:i:s'));
        $this->seedSignal('newest', now()->subDay()->format('Y-m-d H:i:s'));

        $response = $this->getJson('/api/v1/signals/'.self::TENANT.'?limit=2', $this->token());

        $response->assertStatus(200);
        $this->assertSame(['newest', 'middle'], array_column($response->json(), 'id'));
    }

    /**
     * The window narrows within the tenant; it can never widen across tenants.
     * EnsureTenantScope pins the tenant to the token, so a `since` that would
     * match another organization's rows still cannot reach them.
     */
    public function test_the_window_never_reaches_another_tenants_signals(): void
    {
        $this->seedSignal('ours', now()->subDay()->format('Y-m-d H:i:s'));
        $this->seedSignal('theirs', now()->subDay()->format('Y-m-d H:i:s'), 'other-tenant');

        $response = $this->getJson(
            '/api/v1/signals/'.self::TENANT.'?since='.urlencode(now()->subDays(90)->toIso8601String()),
            $this->token(),
        );

        $response->assertStatus(200);
        $this->assertSame(['ours'], array_column($response->json(), 'id'));
    }

    public function test_an_unparseable_window_is_rejected_rather_than_ignored(): void
    {
        $this->getJson('/api/v1/signals/'.self::TENANT.'?since=not-a-date', $this->token())
            ->assertStatus(422);
    }
}
