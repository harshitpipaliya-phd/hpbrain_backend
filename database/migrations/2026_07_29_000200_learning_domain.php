<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a learning the wedge it belongs to.
 *
 * hpbrain_learnings records what was learned but not what it was learned
 * ABOUT, so grounding — "which prior learnings bear on this question?" — had
 * nothing to filter on but confidence. MemoryGrounding::retrieveFor() already
 * filters on a `domain` column that has never existed; this is the column it
 * has been querying.
 *
 * NULLABLE ON PURPOSE. A learning with no domain is cross-domain: it must
 * ground every question, not none. Making the column NOT NULL with a default
 * ('general', 'unknown') would file every general lesson under one wedge and
 * hide it from all the others — the opposite of what memory is for. NULL here
 * means "applies everywhere", and the grounding query reads it that way.
 *
 * The index carries `reusable` because that is never not in the query: a failed
 * outcome is recorded so the organization learns from it and is never offered
 * back as a pattern to repeat (ADR-005), so grounding always reads
 * (tenant, domain, reusable) together.
 */
return new class extends Migration
{
    private const TABLE = 'hpbrain_learnings';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'domain')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                // VARCHAR(64), not TEXT: MySQL cannot index a TEXT column
                // without a prefix length (error 1170), and this column exists
                // to be indexed.
                $table->string('domain', 64)->nullable()->after('description');
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'idx_learnings_grounding')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->index(['tenant_id', 'domain', 'reusable'], 'idx_learnings_grounding');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex(self::TABLE, 'idx_learnings_grounding')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropIndex('idx_learnings_grounding');
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn('domain');
        });
    }
};
