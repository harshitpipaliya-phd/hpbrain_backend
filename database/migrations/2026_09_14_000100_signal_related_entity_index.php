<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The index that makes "which signals are about THIS object" an index lookup.
 *
 * WHAT NEEDED IT. ContextEngine answers one question per request —
 *
 *     WHERE tenant_id = ? AND related_entity_id = ?
 *       AND related_entity_type IN (...)
 *
 * — and hpbrain_signals had no index covering it. The six indexes already on
 * the table all lead with tenant_id and continue into status, severity,
 * rule_key, department_id or dedupe_key; none of them reaches
 * related_entity_id. So the planner used idx_signals_tenant and then filtered
 * every one of that tenant's signal ROWS, dragging the table's `metadata` JSON
 * along for each. Measured on the live database:
 *
 *     tenant 1000000 : 15,002 signals,  1 with a recorded subject
 *     tenant 1000010 : 10,400 signals,  0
 *     tenant 1000018 :     31 signals, 27
 *
 * The two large tenants are the problem. They cost a 15,002-row and a
 * 10,400-row scan to return, respectively, one signal and none — and they pay
 * it on every context lookup, which is once per Assistant open. That is the
 * shape of read this database punishes hardest.
 *
 * WHY related_entity_type IS NOT IN THE INDEX. It is declared TEXT, so InnoDB
 * needs a prefix length for it, and the column holds at most a dozen distinct
 * short names. (tenant_id, related_entity_id) already narrows to a handful of
 * rows — an object has single-digit signals — and the type predicate filtering
 * those is free. A third key part would enlarge every entry to save nothing.
 *
 * IT ALSO SERVES THE READS THAT ALREADY EXISTED. PersonProfileService::signals()
 * and PersonIntelligenceService query these same two columns and have been
 * scanning for the same reason; neither had to change to benefit.
 *
 * IDEMPOTENT, MYSQL-ONLY AND LOCK-BOUNDED, matching the other index migrations
 * here. This database is shared with a live ERP, and a migration that turns a
 * slow table into an unavailable one is worse than the problem it fixes.
 *
 * @see 2026_08_18_000500_intelligence_dashboard_indexes
 */
return new class extends Migration
{
    private const TABLE = 'hpbrain_signals';

    private const INDEX = 'idx_signals_tenant_related_entity';

    private const COLUMNS = 'tenant_id, related_entity_id';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable(self::TABLE) || $this->hasIndex()) {
            return;
        }

        DB::statement('SET SESSION lock_wait_timeout = 30');

        try {
            DB::statement('ALTER TABLE '.self::TABLE.' ADD INDEX '.self::INDEX.' ('.self::COLUMNS.')');
        } catch (\Illuminate\Database\QueryException $e) {
            // 1205 is lock wait timeout. Transient load on a shared table must
            // not fail a deploy — re-run this when the table is quieter.
            if ((string) ($e->errorInfo[1] ?? '') !== '1205') {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable(self::TABLE) && $this->hasIndex()) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP INDEX '.self::INDEX);
        }
    }

    private function hasIndex(): bool
    {
        return DB::select('SHOW INDEX FROM `'.self::TABLE.'` WHERE Key_name = ?', [self::INDEX]) !== [];
    }
};
