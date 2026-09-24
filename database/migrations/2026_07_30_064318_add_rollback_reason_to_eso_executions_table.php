<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hpbrain_eso_executions', function (Blueprint $table) {
            $table->text('rollback_reason')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hpbrain_eso_executions', function (Blueprint $table) {
            $table->dropColumn('rollback_reason');
        });
    }
};
