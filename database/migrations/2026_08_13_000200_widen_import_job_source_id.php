<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'hpbrain_import_jobs';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'source_id')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $column = DB::selectOne(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS max_length
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [self::TABLE, 'source_id'],
        );

        if ($column !== null && (int) $column->max_length < 191) {
            DB::unprepared('ALTER TABLE '.self::TABLE.' MODIFY source_id VARCHAR(191) NULL');
        }
    }

    public function down(): void
    {
        // Intentionally not narrowed. Existing source keys can be longer than
        // 36 characters, and shrinking this column would truncate provenance.
    }
};
