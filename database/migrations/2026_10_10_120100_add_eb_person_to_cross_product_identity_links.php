<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.4 extension — EB's OWN Person record (hpbrain_people) joins the
 * same draft/confirm identity graph that already links K-12 (vivek_erp) and
 * G2G (hp_erp). A separate migration, not an edit to
 * 2026_10_07_100000_create_cross_product_identity_links.php, per the
 * project's rule against altering an already-shipped migration in place.
 *
 * eb_tenant_id / eb_person_id are VARCHAR(36), NOT unsignedInteger /
 * unsignedBigInteger like the k12_ and g2g_ prefixed columns —
 * hpbrain_people.id and .tenant_id are themselves VARCHAR(36) (see
 * database/migrations/2026_01_01_000300_person.php), not auto-increment
 * integers, so the new columns match what they actually reference.
 *
 * Two separate unique constraints, not one three-way key, because a single
 * cross_product_identity_links row may carry an EB<->G2G pair, an EB<->K12
 * pair, both, or (from the pre-existing proposeMatches()) a K12<->G2G pair
 * with no EB side at all. MySQL and SQLite both treat multiple NULLs in a
 * unique index as distinct from one another, exactly like the existing
 * cpil_k12_g2g_unique constraint already relies on — confirmed against both
 * engines' documented behavior before adding a second nullable-column
 * unique index here, not assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cross_product_identity_links', 'eb_person_id')) {
            return;
        }

        Schema::table('cross_product_identity_links', function (Blueprint $table) {
            $table->string('eb_tenant_id', 36)->nullable();
            $table->string('eb_person_id', 36)->nullable();
        });

        Schema::table('cross_product_identity_links', function (Blueprint $table) {
            $table->unique(['eb_person_id', 'g2g_user_id'], 'cpil_eb_g2g_unique');
            $table->unique(['eb_person_id', 'k12_user_id'], 'cpil_eb_k12_unique');
            $table->index('eb_person_id');
        });
    }

    public function down(): void
    {
        Schema::table('cross_product_identity_links', function (Blueprint $table) {
            $table->dropUnique('cpil_eb_g2g_unique');
            $table->dropUnique('cpil_eb_k12_unique');
            $table->dropIndex(['eb_person_id']);
            $table->dropColumn(['eb_tenant_id', 'eb_person_id']);
        });
    }
};
