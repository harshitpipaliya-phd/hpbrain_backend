<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7.4 — the same person is a Student in K-12, an employee in G2G, a
 * Person in EB. This is the first table anywhere in any of the three
 * products that records "this is the same real person" across them.
 *
 * NOT hpbrain_entity_mappings. That table says WHERE a universal entity
 * lives for a tenant (which table, which field) — schema-level config. This
 * says WHICH ROW in one system is the same person as WHICH ROW in another —
 * row-level identity. Different questions; conflating them was not an
 * option.
 *
 * MATCHED BY EMAIL, NOT BY id. Measured before building this: the same
 * numeric tbluser.id in vivek_erp (K-12) and hp_erp (G2G) is routinely a
 * DIFFERENT real person, even a different tenant (id=68 is tenant 47's user
 * in K-12, tenant 3's user in G2G). email is the only signal that actually
 * corresponds to the same person: 70 matches found across the whole
 * estate, dominated by one real tenant pair (K-12 tenant 329 / G2G tenant
 * 1000018, 64 of the 70) with a handful of likely-coincidental single
 * matches on low-numbered seed/dev tenants.
 *
 * DRAFT UNTIL CONFIRMED, same proposal-only shape as every other
 * heuristically-derived link in this product family (Content Law C5 in
 * K-12/EB, ConceptTagger's proposals). A wrong identity link is worse than
 * none - it would merge two different people's history - so nothing here
 * is authoritative until a human confirms it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cross_product_identity_links')) {
            return;
        }

        Schema::create('cross_product_identity_links', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('k12_sub_institute_id')->nullable();
            $table->unsignedBigInteger('k12_user_id')->nullable();

            $table->unsignedInteger('g2g_sub_institute_id')->nullable();
            $table->unsignedBigInteger('g2g_user_id')->nullable();

            $table->string('match_email')->nullable();
            $table->string('match_method', 32)->default('email');
            $table->decimal('confidence', 4, 3);

            // draft: proposed, never used for a real decision.
            // confirmed: a human checked it; resolvable via CrossProductIdentityResolver.
            // rejected: a human checked it and it was wrong; kept so it is never re-proposed.
            $table->enum('status', ['draft', 'confirmed', 'rejected'])->default('draft');

            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->unique(['k12_user_id', 'g2g_user_id'], 'cpil_k12_g2g_unique');
            $table->index('match_email');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cross_product_identity_links');
    }
};
