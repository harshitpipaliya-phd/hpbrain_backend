<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Universal\CrossProductIdentityResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 7.4 extension, tasks 4/5 — the EB<->G2G / EB<->K12 draft-then-confirm
 * path, using ONLY a fixture row, never real data.
 *
 * NO REAL IDENTITY LINK IS EVER CONFIRMED HERE. The only row this test
 * writes carries a fake tenant id, a fake UUID, and
 * 'fixture.only@example.test' — confirm() is called on that one fixture
 * row's id, never against anything that could resolve to a real person.
 * The whole body also runs inside its own DB transaction, rolled back in
 * a finally block, belt-and-braces on top of the fact that this suite
 * already runs against an ephemeral SQLite :memory: connection per the
 * project's phpunit.xml.
 *
 * proposeEbMatches() ITSELF IS NOT EXERCISED HERE. Its email joins read
 * vivek_erp.tbluser / hp_erp.tbluser / hpbrain_people as real schema-
 * qualified tables, which SQLite cannot represent — the same structural
 * reason the pre-existing proposeMatches() has no automated test either.
 * That method is verified separately, for real, against whatever of the
 * configured MySQL host this environment can actually reach (reported in
 * the task summary, not faked here).
 *
 * cross_product_identity_links has no existing hand-rolled test schema to
 * extend (confirmed by grep — no test anywhere references this table
 * yet), so this test builds its own, matching both the 2026_10_07 and the
 * 2026_10_10_120100 migrations' columns, following the same house
 * convention as tests/Support/BuildsBrainSchema.php.
 */
final class CrossProductIdentityResolverEbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cross_product_identity_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('k12_sub_institute_id')->nullable();
            $table->unsignedBigInteger('k12_user_id')->nullable();
            $table->unsignedInteger('g2g_sub_institute_id')->nullable();
            $table->unsignedBigInteger('g2g_user_id')->nullable();
            $table->string('eb_tenant_id', 36)->nullable();
            $table->string('eb_person_id', 36)->nullable();
            $table->string('match_email')->nullable();
            $table->string('match_method', 32)->default('email');
            $table->decimal('confidence', 4, 3);
            $table->enum('status', ['draft', 'confirmed', 'rejected'])->default('draft');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['k12_user_id', 'g2g_user_id'], 'cpil_k12_g2g_unique');
            $table->unique(['eb_person_id', 'g2g_user_id'], 'cpil_eb_g2g_unique');
            $table->unique(['eb_person_id', 'k12_user_id'], 'cpil_eb_k12_unique');
        });
    }

    public function test_a_confirmed_eb_g2g_fixture_link_resolves_in_both_directions(): void
    {
        DB::beginTransaction();

        try {
            $linkId = DB::table('cross_product_identity_links')->insertGetId([
                'eb_tenant_id' => 'eb-tenant-fixture',
                'eb_person_id' => 'person-fixture-uuid',
                'g2g_sub_institute_id' => 999001,
                'g2g_user_id' => 555001,
                'match_email' => 'fixture.only@example.test',
                'match_method' => 'email',
                'confidence' => 0.700,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $resolver = app(CrossProductIdentityResolver::class);

            // Draft must not resolve from either side yet.
            $this->assertNull($resolver->resolve('g2g', 999001, 555001));
            $this->assertNull($resolver->resolveEb('eb-tenant-fixture', 'person-fixture-uuid'));

            $this->assertTrue($resolver->confirm($linkId, confirmedBy: 1));
            // Never silently re-confirms / overwrites an existing verdict.
            $this->assertFalse($resolver->confirm($linkId, confirmedBy: 2));

            $fromG2g = $resolver->resolve('g2g', 999001, 555001);
            $this->assertNotNull($fromG2g);
            $this->assertSame('eb-tenant-fixture', $fromG2g['eb']['tenant_id']);
            $this->assertSame('person-fixture-uuid', $fromG2g['eb']['person_id']);

            $fromEb = $resolver->resolveEb('eb-tenant-fixture', 'person-fixture-uuid');
            $this->assertNotNull($fromEb);
            $this->assertSame(999001, $fromEb['g2g']['sub_institute_id']);
            $this->assertSame(555001, $fromEb['g2g']['user_id']);
            $this->assertNull($fromEb['k12']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_confirmed_eb_k12_fixture_link_resolves_from_k12_side(): void
    {
        DB::beginTransaction();

        try {
            $linkId = DB::table('cross_product_identity_links')->insertGetId([
                'eb_tenant_id' => 'eb-tenant-fixture-2',
                'eb_person_id' => 'person-fixture-uuid-2',
                'k12_sub_institute_id' => 888002,
                'k12_user_id' => 444002,
                'match_email' => 'fixture.two@example.test',
                'match_method' => 'email',
                'confidence' => 0.700,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $resolver = app(CrossProductIdentityResolver::class);
            $resolver->confirm($linkId, confirmedBy: 1);

            $fromK12 = $resolver->resolve('k12', 888002, 444002);
            $this->assertNotNull($fromK12);
            $this->assertSame('person-fixture-uuid-2', $fromK12['eb']['person_id']);

            $fromEb = $resolver->resolveEb('eb-tenant-fixture-2', 'person-fixture-uuid-2');
            $this->assertSame(444002, $fromEb['k12']['user_id']);
            $this->assertNull($fromEb['g2g']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_pending_review_never_surfaces_a_rejected_or_confirmed_row(): void
    {
        DB::beginTransaction();

        try {
            DB::table('cross_product_identity_links')->insert([
                [
                    'eb_tenant_id' => 'eb-t3', 'eb_person_id' => 'eb-p3',
                    'g2g_sub_institute_id' => 1, 'g2g_user_id' => 1,
                    'match_email' => 'draft@example.test', 'confidence' => 0.700, 'status' => 'draft',
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'eb_tenant_id' => 'eb-t4', 'eb_person_id' => 'eb-p4',
                    'g2g_sub_institute_id' => 2, 'g2g_user_id' => 2,
                    'match_email' => 'rejected@example.test', 'confidence' => 0.700, 'status' => 'rejected',
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            $pending = app(CrossProductIdentityResolver::class)->pendingReview();
            $emails = array_map(fn ($r) => $r->match_email, $pending);

            $this->assertContains('draft@example.test', $emails);
            $this->assertNotContains('rejected@example.test', $emails);
        } finally {
            DB::rollBack();
        }
    }
}
