<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.1 RAG v1, memory extension — the same embedding storage shape the
 * 2026_10_10_120000 migration gave hpbrain_knowledge_assets, now added to the
 * three Organizational Memory & ESO tables RetrievalService::searchMemory()
 * reads: hpbrain_learnings, hpbrain_mental_models, hpbrain_eso_definitions.
 *
 * SAME SHAPE, NOT THE SAME CONTENT COLUMN. hpbrain_knowledge_assets has one
 * `content` TEXT column to chunk. None of these three tables do — a learning
 * is `pattern` + `description` + `domain`, a mental model is `name` +
 * `description` + `domain`, an ESO definition is `name` + `objective` +
 * `trigger_description` (the exact fields RetrievalService::searchMemory()
 * already searched by keyword, kept identical so the semantic and keyword
 * paths stay comparable). MemoryIngestionService synthesizes that
 * concatenated text per table and chunks/embeds it the same way
 * KnowledgeIngestionService does for a knowledge asset.
 *
 * `embedding` / `embedding_model` / `embedding_version` carry the same
 * meaning as on hpbrain_knowledge_assets: one JSON array of
 * {index,hash,text,vector} chunks, stamped with the model/version that
 * produced it so RetrievalService can tell a current embedding from a stale
 * or absent one (App\Domain\Ai\Embeddings::CURRENT_MODEL/CURRENT_VERSION).
 *
 * Each ALTER is independently guarded with hasColumn() — same idempotency
 * discipline as 2026_10_10_120000, and necessary here because the three
 * tables are altered inside a single migration file.
 */
return new class extends Migration
{
    private const TABLES = ['hpbrain_learnings', 'hpbrain_mental_models', 'hpbrain_eso_definitions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'embedding')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->json('embedding')->nullable();
                $t->string('embedding_model', 100)->nullable();
                $t->string('embedding_version', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'embedding')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['embedding', 'embedding_model', 'embedding_version']);
            });
        }
    }
};
