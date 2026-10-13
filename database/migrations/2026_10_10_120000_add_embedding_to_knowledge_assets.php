<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.1 RAG v1 — the first embedding storage anywhere in this project.
 *
 * APPROVED ARCHITECTURE DECISION: no new infrastructure (no Pinecone / Weaviate
 * / Qdrant / pgvector). `embedding` is a JSON column holding one entry per
 * chunk — `[{"index":int,"hash":string,"text":string,"vector":float[]}, ...]`
 * — and cosine similarity is computed in PHP at query time
 * (RetrievalService::searchDocuments()). This is explicitly a v1/MVP shape,
 * not a scaling design: PHP-side cosine over a JSON blob is fine for the
 * corpus size this installation actually has today, and is not how this
 * would be built if the corpus were large.
 *
 * ONE COLUMN, NOT A CHUNKS TABLE. Each knowledge asset is re-chunked and
 * re-embedded as a whole; overwriting the single JSON column on
 * re-ingestion makes "update, not duplicate" a structural property of the
 * storage rather than logic that has to get re-derived correctly every time
 * (KnowledgeIngestionService::ingestAsset()).
 *
 * `embedding_model` / `embedding_version` are stamped on every write so a
 * future model or chunking-algorithm change does not get silently compared
 * against incompatible stale vectors — RetrievalService treats any row
 * whose stamp does not match the CURRENT constants
 * (App\Domain\Ai\Embeddings) as unembedded and falls back to keyword search
 * for it, never fabricating a comparison across embedding spaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('hpbrain_knowledge_assets', 'embedding')) {
            return;
        }

        Schema::table('hpbrain_knowledge_assets', function (Blueprint $table) {
            $table->json('embedding')->nullable();
            $table->string('embedding_model', 100)->nullable();
            $table->string('embedding_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hpbrain_knowledge_assets', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_model', 'embedding_version']);
        });
    }
};
