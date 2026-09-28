<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiQuotaExceededException;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use App\Repositories\ImportJobRepository;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Runs generic dataset analysis through the configured AI provider after
 * a successful ingestion commit.
 *
 * The analysis is deliberately generic: it never hardcodes a customer
 * dataset.  It reads the schema and sample rows produced by SchemaDetector
 * and asks the model to produce executive-summary-style output in a fixed
 * JSON shape.
 */
final class DatasetAnalysisService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'executive_summary' => ['type' => 'string'],
            'key_metrics' => ['type' => 'array', 'items' => ['type' => 'string']],
            'anomalies' => ['type' => 'array', 'items' => ['type' => 'string']],
            'trends' => ['type' => 'array', 'items' => ['type' => 'string']],
            'business_intelligence' => ['type' => 'string'],
            'recommendations' => ['type' => 'array', 'items' => ['type' => 'string']],
            'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => [
            'executive_summary',
            'key_metrics',
            'anomalies',
            'trends',
            'business_intelligence',
            'recommendations',
            'risks',
        ],
    ];

    public function __construct(
        private readonly AiGateway $ai,
        private readonly ImportJobRepository $jobs,
    ) {
    }

    /**
     * Analyse the committed dataset and persist the result on the job.
     *
     * WHY EVERY EXIT PATH WRITES A STATUS, NOT ONLY SUCCESS. This used to
     * return null on three unrelated conditions — no AI provider configured,
     * a quota refusal, a provider outage, a reply that was not the JSON
     * asked for — with nothing recorded anywhere. The only consumer of
     * error_report (ImportController::show()) could not tell a tenant "AI
     * analysis is not configured" from "it ran and produced nothing" from
     * "it silently failed"; all three looked identical: the ai_analysis key
     * was simply absent. ai_analysis_status/_reason make that distinction
     * readable without requiring the caller to inspect this class's return
     * value, which IngestionService's own commit path already discards.
     *
     * @return array<string, mixed>|null
     */
    public function analyse(string $tenantId, string $jobId, array $schema, int $committedRows): ?array
    {
        $job = $this->jobs->find($tenantId, $jobId);

        if ($job === null) {
            return null;
        }

        if (! $this->ai->isConfigured()) {
            $this->recordStatus($tenantId, $jobId, $job, 'unavailable', 'no_ai_provider_configured');

            return null;
        }

        $prompt = $this->buildPrompt($schema, $committedRows);

        try {
            $response = $this->ai->complete(
                new AiRequest(
                    systemPrompt: 'You are an enterprise data analyst. Analyse the dataset summary and respond with STRICT JSON only.',
                    userPrompt: $prompt,
                    responseSchema: self::RESPONSE_SCHEMA,
                    maxTokens: 2048,
                    temperature: 0.2,
                ),
                tenantId: $tenantId,
                actorId: (string) ($job['started_by'] ?? 'ingestion'),
                service: 'dataset_analysis',
                entityType: 'import_job',
                entityId: $jobId,
            );
        } catch (AiQuotaExceededException) {
            $this->recordStatus($tenantId, $jobId, $job, 'unavailable', 'ai_quota_exceeded');

            return null;
        } catch (Throwable) {
            $this->recordStatus($tenantId, $jobId, $job, 'unavailable', 'ai_call_failed');

            return null;
        }

        $analysis = $response->json();

        if (! is_array($analysis)) {
            $this->recordStatus($tenantId, $jobId, $job, 'unavailable', 'ai_response_unparseable');

            return null;
        }

        DB::table('hpbrain_import_jobs')
            ->where('tenant_id', $tenantId)
            ->where('id', $jobId)
            ->update([
                'error_report' => json_encode(array_merge(
                    $this->existingReport($job),
                    ['ai_analysis' => $analysis, 'ai_analysis_status' => 'completed']
                )),
            ]);

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function recordStatus(string $tenantId, string $jobId, array $job, string $status, string $reason): void
    {
        DB::table('hpbrain_import_jobs')
            ->where('tenant_id', $tenantId)
            ->where('id', $jobId)
            ->update([
                'error_report' => json_encode(array_merge(
                    $this->existingReport($job),
                    ['ai_analysis_status' => $status, 'ai_analysis_reason' => $reason]
                )),
            ]);
    }

    /**
     * $job comes from ImportJobRepository::find(), which already
     * json-decodes error_report via BaseRepository::hydrate() — so it is an
     * array here, not a JSON string. The previous cast-to-string-then-decode
     * (`json_decode((string) $array, true)`) silently produced the literal
     * string "Array", decoded to null, and fell through to an empty array —
     * discarding whatever error_report content already existed (import-row
     * errors, an earlier ai_analysis) every time this class touched the job.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function existingReport(array $job): array
    {
        $report = $job['error_report'] ?? null;

        if (is_array($report)) {
            return $report;
        }

        $decoded = is_string($report) ? json_decode($report, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function buildPrompt(array $schema, int $committedRows): string
    {
        $columns = $schema['columns'] ?? [];
        $entity = $schema['dataset_type'] ?? 'Unknown';
        $domain = $schema['domain'] ?? 'Operations';

        $columnSummary = [];

        foreach ($columns as $name => $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $columnSummary[] = sprintf(
                '- %s (%s, nulls=%s)',
                $name,
                (string) ($meta['inferred_type'] ?? 'text'),
                number_format((float) ($meta['null_fraction'] ?? 0), 2)
            );
        }

        $sample = $schema['sample_rows'] ?? [];

        return <<<PROMPT
A dataset of {$committedRows} rows was just ingested into an enterprise brain.

Inferred dataset type: {$entity}
Inferred domain: {$domain}

Columns:
%s

Sample rows:
%s

Provide a generic enterprise analysis. Do NOT reference any specific business name or customer dataset. Respond with STRICT JSON only.
PROMPT;
    }
}
