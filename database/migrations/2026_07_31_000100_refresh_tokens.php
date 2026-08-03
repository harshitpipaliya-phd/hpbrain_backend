<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hpbrain_refresh_tokens')) {
            Schema::create('hpbrain_refresh_tokens', function ($table) {
                $table->string('jti')->primary();
                $table->string('tenant_id');
                $table->string('user_id');
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hpbrain_refresh_tokens');
    }
};
