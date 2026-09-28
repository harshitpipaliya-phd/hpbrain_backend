<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResponse;
use App\Domain\Ingestion\DatasetAnalysisService;
use App\Repositories\ImportJobRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Regression: DatasetAnalysisService::analyse() used to return null on four
 * unrelated conditions — no provider configured, a quota refusal, a
 * provider outage, and a reply that was not the JSON asked for — with
 * nothing recorded on the job either way. Every failure mode was
 * indistinguishable from "analysis genuinely produced nothing" to
 * ImportController::show()'s only consumer of error_report.ai_analysis.
 * These pin that every exit path now leaves a reason, and that persisting
 * one never destroys whatever error_report content already existed on the
 * job (the pre-existing cast-array-to-string bug this fix also closes).
 */
final class DatasetAnalysisServiceTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-analysis';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    private function seedJob(array $errorReport = []): string
    {
        $job = app(ImportJobRepository::class)->create(self::TENANT, [
            'import_type' => 'csv',
            'entity_type' => 'OperationalRecord',
            'started_by' => 'test-user',
            'error_report' => $errorReport,
        ]);

        return $job['id'];
    }

    private function report(string $jobId): array
    {
        $raw = DB::table('hpbrain_import_jobs')->where('id', $jobId)->value('error_report');

        return $raw ? json_decode((string) $raw, true) : [];
    }

    private function schema(): array
    {
        return ['columns' => ['name' => ['inferred_type' => 'text', 'null_fraction' => 0.0]], 'dataset_type' => 'Test', 'domain' => 'Operations'];
    }

    public function test_no_configured_provider_records_a_distinguishable_reason(): void
    {
        config(['brain.ai.provider' => '']); // unconfigured, matches production default

        $jobId = $this->seedJob();
        $result = app(DatasetAnalysisService::class)->analyse(self::TENANT, $jobId, $this->schema(), 10);

        self::assertNull($result);
        $report = $this->report($jobId);
        self::assertSame('unavailable', $report['ai_analysis_status']);
        self::assertSame('no_ai_provider_configured', $report['ai_analysis_reason']);
        self::assertArrayNotHasKey('ai_analysis', $report);
    }

    public function test_a_provider_failure_records_its_own_reason_distinct_from_unconfigured(): void
    {
        config(['brain.ai.provider' => 'test-provider']);
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function complete(AiRequest $request): AiResponse
            {
                throw new RuntimeException('upstream 503');
            }
        });

        $jobId = $this->seedJob();
        $result = app(DatasetAnalysisService::class)->analyse(self::TENANT, $jobId, $this->schema(), 10);

        self::assertNull($result);
        self::assertSame('ai_call_failed', $this->report($jobId)['ai_analysis_reason']);
    }

    public function test_an_exhausted_quota_records_its_own_reason(): void
    {
        config(['brain.ai.provider' => 'test-provider']);
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function complete(AiRequest $request): AiResponse
            {
                return new AiResponse(content: '{}', model: 'test-model', inputTokens: 1, outputTokens: 1);
            }
        });

        DB::table('hpbrain_ai_quotas')->insert([
            'id' => 'quota-analysis', 'tenant_id' => self::TENANT, 'quota_type' => 'feature',
            'quota_key' => 'dataset_analysis', 'limit_value' => 1, 'current_usage' => 1,
            'reset_period' => 'monthly', 'is_active' => true, 'created_by' => 'test',
            'created_date' => '2026-01-01 00:00:00', 'updated_date' => '2026-01-01 00:00:00',
        ]);

        $jobId = $this->seedJob();
        $result = app(DatasetAnalysisService::class)->analyse(self::TENANT, $jobId, $this->schema(), 10);

        self::assertNull($result);
        self::assertSame('ai_quota_exceeded', $this->report($jobId)['ai_analysis_reason']);
    }

    public function test_a_non_json_reply_records_its_own_reason(): void
    {
        config(['brain.ai.provider' => 'test-provider']);
        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function complete(AiRequest $request): AiResponse
            {
                return new AiResponse(content: 'not json at all', model: 'test-model', inputTokens: 1, outputTokens: 1);
            }
        });

        $jobId = $this->seedJob();
        $result = app(DatasetAnalysisService::class)->analyse(self::TENANT, $jobId, $this->schema(), 10);

        self::assertNull($result);
        self::assertSame('ai_response_unparseable', $this->report($jobId)['ai_analysis_reason']);
    }

    public function test_a_successful_analysis_is_persisted_with_a_completed_status(): void
    {
        config(['brain.ai.provider' => 'test-provider']);
        $payload = [
            'executive_summary' => 'ok', 'key_metrics' => [], 'anomalies' => [], 'trends' => [],
            'business_intelligence' => 'ok', 'recommendations' => [], 'risks' => [],
        ];
        $this->app->instance(AiProvider::class, new class($payload) implements AiProvider
        {
            public function __construct(private array $payload)
            {
            }

            public function complete(AiRequest $request): AiResponse
            {
                return new AiResponse(content: (string) json_encode($this->payload), model: 'test-model', inputTokens: 1, outputTokens: 1);
            }
        });

        $jobId = $this->seedJob();
        $result = app(DatasetAnalysisService::class)->analyse(self::TENANT, $jobId, $this->schema(), 10);

        self::assertSame($payload, $result);
        $report = $this->report($jobId);
        self::assertSame('completed', $report['ai_analysis_status']);
        self::assertSame($payload, $report['ai_analysis']);
    }

    /**
     * The bug this fix also closes: error_report already carries content
     * (row-level import errors) written before analysis ever runs. Recording
     * a status must not erase it.
     */
    public function test_recording_a_status_never_destroys_existing_error_report_content(): void
    {
        config(['brain.ai.provider' => '']);

        $jobId = $this->seedJob(['import_errors' => ['row 3: bad date']]);
        app(DatasetAnalysisService::class)->analyse(self::TENANT, $jobId, $this->schema(), 10);

        $report = $this->report($jobId);
        self::assertSame(['row 3: bad date'], $report['import_errors']);
        self::assertSame('no_ai_provider_configured', $report['ai_analysis_reason']);
    }
}
