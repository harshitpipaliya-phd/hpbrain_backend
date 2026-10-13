<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * The single source of truth for "what embedding does this row actually
 * have", shared by the writer (KnowledgeIngestionService) and the reader
 * (RetrievalService). Keeping both in one place means a model/version bump
 * only has to happen in one spot, and the two sides of the contract cannot
 * silently drift out of sync with each other.
 *
 * CURRENT_MODEL / CURRENT_VERSION are what gets stamped on every fresh
 * write and what the reader requires an exact match against before trusting
 * a row's vectors — a row stamped with an older version is treated as
 * unembedded (RetrievalService falls back to keyword search for it) rather
 * than having its vectors compared against a different embedding space as
 * if they were comparable.
 */
final class Embeddings
{
    public const CURRENT_MODEL = 'text-embedding-004';

    public const CURRENT_VERSION = 'v1';

    /**
     * Cosine similarity of two vectors. Returns 0.0 for an empty, mismatched,
     * or zero-magnitude pair rather than dividing by zero or throwing —
     * callers treat 0.0 as "no honest signal", which is accurate for those
     * cases.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));

        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $magA += $x * $x;
            $magB += $y * $y;
        }

        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /**
     * @param  array<int, array{index:int,hash:string,text:string,vector:array<int,float>}>  $chunks
     */
    public static function encodeChunks(array $chunks): string
    {
        return json_encode($chunks, JSON_THROW_ON_ERROR);
    }

    /**
     * Safe decode — malformed JSON, a non-array payload, or null all come
     * back as an empty chunk list rather than throwing. A corrupt embedding
     * column is a reason to fall back to keyword search for that row, not a
     * reason to fail the whole request.
     *
     * @return array<int, array{index:int,hash:string,text:string,vector:array<int,float>}>
     */
    public static function decodeChunks(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $chunks = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ! isset($entry['vector']) || ! is_array($entry['vector'])) {
                continue;
            }

            $chunks[] = [
                'index' => (int) ($entry['index'] ?? 0),
                'hash' => (string) ($entry['hash'] ?? ''),
                'text' => (string) ($entry['text'] ?? ''),
                'vector' => array_map('floatval', $entry['vector']),
            ];
        }

        return $chunks;
    }
}
