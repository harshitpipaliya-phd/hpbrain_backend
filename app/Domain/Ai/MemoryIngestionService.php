<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Providers\GeminiProvider;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.1 RAG v1, memory extension — ingestion for the three
 * Organizational Memory & ESO tables RetrievalService::searchMemory() reads:
 * hpbrain_learnings, hpbrain_mental_models, hpbrain_eso_definitions.
 *
 * A SIBLING OF KnowledgeIngestionService, NOT AN EXTENSION OF IT. The task
 * that produced this class considered extending KnowledgeIngestionService
 * directly and rejected it: that class's ingestAsset() is hardwired to one
 * table (hpbrain_knowledge_assets) and one source column (`content`). None
 * of these three tables have a single `content` column to chunk — each has
 * a different small set of text fields, and two of the three
 * (hpbrain_learnings, hpbrain_mental_models) have no `updated_date` column
 * at all, so a shared update() call that unconditionally stamps
 * updated_date would break on exactly those two tables. Forcing one table's
 * shape onto three different ones would have meant either a `content`
 * column that does not exist on any of them, or a single-table method
 * signature wearing an if/elseif per table inside it. A sibling service
 * with a small per-table config array is the honest shape for "the same
 * algorithm, three different row shapes."
 *
 * WHAT IS ACTUALLY SHARED: the chunking algorithm itself. This class takes
 * a KnowledgeIngestionService dependency purely to call its chunk() method
 * — paragraph-then-hard-split, deterministic, unchanged — rather than
 * duplicating that logic. Everything else (which columns build the text to
 * embed, which columns get updated, how a row is looked up) is
 * table-specific and lives in self::TABLES.
 *
 * THE TEXT EMBEDDED IS THE SAME TEXT THE KEYWORD FALLBACK SEARCHES.
 * RetrievalService::searchMemory() originally ran LIKE against
 * pattern/description/domain (learnings), name/description/domain (mental
 * models), name/objective/trigger_description (ESO definitions). This
 * class concatenates exactly those fields into the text it chunks and
 * embeds, so a row's semantic and keyword paths are answering with the same
 * underlying content, not two different views of the row.
 *
 * SAME IDEMPOTENCY DISCIPLINE AS KnowledgeIngestionService. chunk() is pure,
 * and ingestRow() always overwrites the whole `embedding` column in one
 * UPDATE — re-ingesting an unchanged row recomputes identical chunks and
 * vectors and replaces the column with an equal value, never appending.
 */
final class MemoryIngestionService
{
    /** Same default as KnowledgeIngestionService::MAX_CHUNK_CHARS — no reason for memory rows to chunk differently. */
    private const MAX_CHUNK_CHARS = 1000;

    /**
     * Per-table ingestion shape.
     *
     *   fields          - text columns concatenated (in order) into the
     *                     string that gets chunked/embedded; also exactly
     *                     the columns RetrievalService's keyword fallback
     *                     searches for this table.
     *   hasUpdatedDate  - whether this table has an updated_date column to
     *                     stamp. hpbrain_learnings and hpbrain_mental_models
     *                     do not (see 2026_01_01_000700_intelligence_entities
     *                     and 2026_01_01_000900_outcomes_learnings) —
     *                     stamping it unconditionally would fail with an
     *                     unknown-column error on those two tables.
     *
     * @var array<string, array{fields: array<int, string>, hasUpdatedDate: bool}>
     */
    public const TABLES = [
        'hpbrain_learnings' => [
            'fields' => ['pattern', 'description', 'domain'],
            'hasUpdatedDate' => false,
        ],
        'hpbrain_mental_models' => [
            'fields' => ['name', 'description', 'domain'],
            'hasUpdatedDate' => false,
        ],
        'hpbrain_eso_definitions' => [
            'fields' => ['name', 'objective', 'trigger_description'],
            'hasUpdatedDate' => true,
        ],
    ];

    public function __construct(private readonly KnowledgeIngestionService $chunker)
    {
    }

    public static function isSupportedTable(string $table): bool
    {
        return array_key_exists($table, self::TABLES);
    }

    /** @return array<int, string> */
    public static function supportedTables(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * @return array{status:string, chunks?:int}
     */
    public function ingestRow(string $tenantId, string $table, string $id, int $maxChunkChars = self::MAX_CHUNK_CHARS): array
    {
        if (! self::isSupportedTable($table)) {
            return ['status' => 'unsupported_table'];
        }

        $config = self::TABLES[$table];

        $row = DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first($config['fields']);

        if ($row === null) {
            return ['status' => 'not_found'];
        }

        $content = $this->buildContent($row, $config['fields']);
        $chunks = $this->chunker->chunk($content, $maxChunkChars);

        if ($chunks === []) {
            return ['status' => 'empty_content', 'chunks' => 0];
        }

        $gemini = GeminiProvider::fromConfig();
        $embedded = [];

        foreach ($chunks as $index => $text) {
            $embedded[] = [
                'index' => $index,
                'hash' => hash('sha256', $table . '#' . $id . '#' . $index),
                'text' => $text,
                'vector' => $gemini->embed($text),
            ];
        }

        $update = [
            'embedding' => Embeddings::encodeChunks($embedded),
            'embedding_model' => Embeddings::CURRENT_MODEL,
            'embedding_version' => Embeddings::CURRENT_VERSION,
        ];

        if ($config['hasUpdatedDate']) {
            $update['updated_date'] = now();
        }

        DB::table($table)
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update($update);

        return ['status' => 'ok', 'chunks' => count($embedded)];
    }

    /**
     * Every row for this tenant/table with no embedding, or one stamped
     * with a model/version that is no longer current. Mirrors
     * KnowledgeIngestionService::ingestPending() exactly: a failure on one
     * row is recorded and does not abort the rest of the batch.
     *
     * @return array{processed:int, failed:int, details:array<int, array<string, mixed>>}
     */
    public function ingestPending(string $tenantId, string $table, int $limit = 50): array
    {
        if (! self::isSupportedTable($table)) {
            return ['processed' => 0, 'failed' => 0, 'details' => [['status' => 'unsupported_table', 'table' => $table]]];
        }

        $ids = DB::table($table)
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
                $outcome = $this->ingestRow($tenantId, $table, (string) $id);
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
     * Concatenates the configured fields (in order), skipping any that are
     * null/blank, joined the same way KnowledgeIngestionService::chunk()
     * expects paragraphs to be separated — a blank line — so each field
     * behaves like its own paragraph going into the chunker.
     *
     * @param  array<int, string>  $fields
     */
    private function buildContent(object $row, array $fields): string
    {
        $parts = [];

        foreach ($fields as $field) {
            $value = trim((string) ($row->{$field} ?? ''));

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode("\n\n", $parts);
    }
}
