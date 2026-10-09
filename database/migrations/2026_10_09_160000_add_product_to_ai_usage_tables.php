<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D6 (shared cross-product AI gateway), minimal first step: a `product`
 * column on BOTH of EB's usage ledgers (the newer AiModelClient path and
 * the legacy AiGateway path), so rows from either can sit in a
 * cross-product usage view alongside G2G's ai_usage_events (same shape,
 * same schema) and a new K-12 table. Defaulted so every existing row is
 * tagged without a backfill; AiGateway::record() and the AiModelClient
 * meter need no change to keep writing correctly-tagged rows going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hpbrain_ai_usage_events', function (Blueprint $table) {
            $table->string('product', 16)->default('eb')->after('id');
        });

        Schema::table('hpbrain_ai_executions', function (Blueprint $table) {
            $table->string('product', 16)->default('eb')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('hpbrain_ai_usage_events', function (Blueprint $table) {
            $table->dropColumn('product');
        });

        Schema::table('hpbrain_ai_executions', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
