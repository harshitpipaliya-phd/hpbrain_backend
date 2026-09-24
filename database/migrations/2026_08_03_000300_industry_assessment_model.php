<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-industry assessment model on hpbrain_industry_templates.
 *
 * KASBA — Knowledge, Ability, Skill, Behaviour, Attitude — is a model of HUMAN
 * capability. It was global, in config('brain.kasba.dimensions'), so every
 * tenant of every industry assessed everything against those five words.
 *
 * That is right for a nurse and wrong for a dialysis machine, whose dimensions
 * are closer to Availability, Performance, Quality and Compliance. Scoring an
 * asset's "attitude" produces a number that looks meaningful and is not, which
 * is the precise failure this architecture exists to prevent: a figure nobody
 * can act on, rendered with the same authority as one they can.
 *
 * Shape:
 *
 *   {
 *     "dimensions": ["knowledge", "ability", "skill", "behaviour", "attitude"],
 *     "maxLevel": 5,
 *     "assessableEntityTypes": ["Person", "OrganizationUnit"]
 *   }
 *
 * NULL means "no industry-specific model", and the config array is the fallback.
 * That keeps the school tenant on exactly the five dimensions it has today.
 */
return new class extends Migration
{
    private const TABLE = 'hpbrain_industry_templates';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'assessment_model')) {
            return;
        }

        DB::unprepared('ALTER TABLE '.self::TABLE.' ADD COLUMN assessment_model JSON NULL AFTER workflows');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, 'assessment_model')) {
            DB::unprepared('ALTER TABLE '.self::TABLE.' DROP COLUMN assessment_model');
        }
    }
};
