<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Providers\GeminiProvider;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.1 RAG v1 — the first real ingestion path for the Knowledge
 * Library. Chunks a knowledge asset's text, embeds each chunk via Gemini,
 * and stores the result on the asset's own `embedding` column (see the
 * 2026_10_10_120000 migration for the column shape and why it is one JSON
 * column rather than a chunks table).
 *
 * IDEMPOTENT BY CONSTRUCTION, NOT BY EXTRA LOGIC. chunk() is a pure
 * function — the same content always produces the same chunk boundaries —
 * and ingestAsset() always OVERWRITES the whole `embedding` column in one
 * UPDATE. Re-running it on an unchanged asset recomputes the identical
 * chunks and vectors and replaces the column with an equal value; it never
 * appends, so "re-ingestion updates rather than duplicates" needs no
 * dedup logic to get right. The per-chunk `hash` (sha256 of
 * "{assetId}#{chunkIndex}") is the stable chunk identity the task calls
 * for, kept for traceability and for a later move to a chunks table,
 * though this single-column design does not itself need it to stay
 * idempotent.
 *
 * SIMPLE ON PURPOSE. chunk() is paragraph splitting with a fixed-length
 * hard split for any one paragraph that is still too long — the task is
 * explicit that v1 chunking should not be overbuilt. No overlap between
 * chunks, no semantic/sentence-boundary awareness.
 */
final class KnowledgeIngestionService
{
    /** Chosen to keep each chunk comfortably inside Gemini's input limits and small enough for a usable citation snippet. */
    private const MAX_CHUNK_CHARS = 1000;

    /**
     * @return array{status:string, chunks?:int}
     */
    public function ingestAsset(string $tenantId, string $assetId, int $maxChunkChars = self::MAX_CHUNK_CHARS): array
    {
        $asset = DB::table('hpbrain_knowledge_assets')
            ->where('tenant_id', $tenantId)
            ->where('id', $assetId)
            ->first(['id', 'content']);

        if ($asset === null) {
            return ['status' => 'not_found'];
        }

        $chunks = $this->chunk((string) $asset->content, $maxChunkChars);

        if ($chunks === []) {
            return ['status' => 'empty_content', 'chunks' => 0];
        }

        $gemini = GeminiProvider::fromConfig();
        $embedded = [];

        foreach ($chunks as $index => $text) {
            $embedded[] = [
                'index' => $index,
                'hash' => hash('sha256', $assetId . '#' . $index),
                'text' => $text,
                'vector' => $gemini->embed($text),
            ];
        }

        DB::table('hpbrain_knowledge_assets')
            ->where('tenant_id', $tenantId)
            ->where('id', $assetId)
            ->update([
                'embedding' => Embeddings::encodeChunks($embedded),
                'embedding_model' => Embeddings::CURRENT_MODEL,
                'embedding_version' => Embeddings::CURRENT_VERSION,
                'updated_date' => now(),
            ]);

        return ['status' => 'ok', 'chunks' => count($embedded)];
    }

    /**
     * Every row for this tenant with no embedding, or one stamped with a
     * model/version that is no longer current. A failure embedding one
     * asset (bad network call, empty Gemini response) is recorded and does
     * not abort the rest of the batch.
     *
     * @return array{processed:int, failed:int, details:array<int, array<string, mixed>>}
     */
    public function ingestPending(string $tenantId, int $limit = 50): array
    {
        $ids = DB::table('hpbrain_knowledge_assets')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNull('embedding')
                  ->orWhereNull('embedding_model')
                  ->orWhereNull('embedding_version')
                  ->orWhere('embedding_model', '!=', Embeddings::CURRENT_MODEL)
                  ->orWhere('embedding_version', '!=', Embeddings::CURRENT_VERSION);
            })
            ->limit($limit)
            ->pluck('id');

        $result = ['processed' => 0, 'failed' => 0, 'details' => []];

        foreach ($ids as $id) {
            try {
                $outcome = $this->ingestAsset($tenantId, (string) $id);
                $result['processed']++;
                $result['details'][] = ['id' => (string) $id] + $outcome;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['details'][] = ['id' => (string) $id, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Paragraph splitting (blank-line separated), merged up to $maxChars; a
     * single paragraph longer than $maxChars is hard-split into fixed
     * windows rather than merged into any neighbor.
     *
     * @return array<int, string>
     */
    public function chunk(string $text, int $maxChars = self::MAX_CHUNK_CHARS): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];
        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) > $maxChars) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                foreach (mb_str_split($paragraph, $maxChars) as $window) {
                    $window = trim($window);
                    if ($window !== '') {
                        $chunks[] = $window;
                    }
                }

                continue;
            }

            if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($paragraph) + 2 > $maxChars) {
                $chunks[] = $buffer;
                $buffer = $paragraph;
                continue;
            }

            $buffer = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return array_values(array_filter($chunks, static fn ($c) => trim($c) !== ''));
    }
}
