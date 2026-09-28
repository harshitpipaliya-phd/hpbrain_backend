<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ReadinessCheckRepository::create() has always written a `created_by`
 * column that the original hpbrain_readiness_checks migration never
 * defined. Every call to OnboardingEngine::runReadinessChecks() — the
 * `POST /onboarding/{tenantId}/{id}/readiness/run` endpoint — has therefore
 * failed with "Unknown column 'created_by'" against MySQL since the table
 * was created. Additive and idempotent, matching the pattern beside it.
 */
return new class extends Migration
{
    private const TABLE = 'hpbrain_readiness_checks';

    private const COLUMN = 'created_by';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN '.self::COLUMN.' TEXT NOT NULL DEFAULT \'system\'');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, self::COLUMN)) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN '.self::COLUMN);
        }
    }
};
