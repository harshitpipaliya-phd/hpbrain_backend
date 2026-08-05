<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_id')->unique();
            $table->string('source_type'); // csv_upload | internal_db | external_api
            $table->string('display_name');
            $table->json('config')->nullable(); // e.g. mapped field config, per-source
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_id');
            $table->string('sync_type'); // one_time_historical_import | incremental_sync
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('case_count')->default(0);
            $table->unsignedInteger('evidence_count')->default(0);
            $table->string('status')->default('completed'); // matches ADR-004's six-state model
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
        Schema::dropIfExists('data_sources');
    }
};
