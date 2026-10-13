<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\EvaluationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsBrainSchema;
use Tests\TestCase;

/**
 * Phase 6.1 RAG v1, task 3 — EvaluationService's real pass/fail mechanics.
 *
 * Every Gemini call in this file is Http::fake()'d, so this validates the
 * HARNESS'S MECHANICS (chunking, cosine ranking, containment/citation
 * checks, the JSON response contract) deterministically and at zero live
 * cost — it is NOT a claim about live-model retrieval quality. That real,
 * live spot-check (via `php artisan ai:eval-rag`) is reported separately.
 *
 * The fake embedding is a real bag-of-words hashing vector (not a constant
 * or a pass-through), so two pieces of text that actually share vocabulary
 * score a genuinely higher cosine similarity than two that do not — this
 * test exercises the real ranking logic, not a rigged one.
 */
final class RagEvaluationHarnessTest extends TestCase
{
    use BuildsBrainSchema;

    private const TENANT = 'tenant-rag-eval';

    private const DIMS = 64;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBrainSchema();
    }

    /** @return array<int, float> */
    private function fakeEmbed(string $text): array
    {
        $vector = array_fill(0, self::DIMS, 0.0);

        foreach (preg_split('/\W+/u', mb_strtolower($text)) as $word) {
            if ($word === '') {
                continue;
            }

            $vector[crc32($word) % self::DIMS] += 1.0;
        }

        return $vector;
    }

    private function fakeGeminiFullPipeline(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, ':embedContent')) {
                $text = (string) ($request->data()['content']['parts'][0]['text'] ?? '');

                return Http::response(['embedding' => ['values' => $this->fakeEmbed($text)]], 200);
            }

            if (str_contains($url, ':generateContent')) {
                // Echoes the retrieved context back as the "answer" — a
                // faithful completion by construction when retrieval found
                // the right chunk, which is exactly what this fake is for:
                // it isolates "did retrieval find the right chunk" from
                // "did the model paraphrase faithfully", the latter being
                // a live-model quality question this mocked test does not
                // claim to answer.
                $userPrompt = (string) ($request->data()['contents'][0]['parts'][0]['text'] ?? '');
                preg_match('/RETRIEVED CONTEXT:\n- \[\S+ \S+\] (.+)/s', $userPrompt, $m);
                $context = trim($m[1] ?? '');
                $answer = explode("\n", $context)[0] ?? $context;

                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => json_encode(['answer' => $answer])]]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10],
                ], 200);
            }

            return Http::response('unexpected url: ' . $url, 404);
        });
    }

    public function test_default_dataset_passes_end_to_end_when_ai_is_configured(): void
    {
        config(['brain.ai.provider' => 'gemini']);
        config(['brain.ai.gemini.api_key' => 'test-gemini-key']);
        $this->fakeGeminiFullPipeline();

        $evaluation = app(EvaluationService::class);
        $seed = $evaluation->seedDefaultRagDataset(self::TENANT);
        $results = $evaluation->runEvaluation(self::TENANT, $seed['datasetId'], 'test-model');

        $statuses = array_column($results['metrics'], 'status');

        $this->assertSame(8, $results['total']);
        $this->assertTrue($results['aiConfigured']);
        $this->assertNotContains('simulated', $statuses, 'the old unconditional-pass status must never appear');
        // The real claim this test backs: every case's retrieval actually
        // found its own fixture's fact, and completeWithRag()'s generated
        // answer (the context echo) is mechanically faithful to it.
        $this->assertSame(8, $results['passed'], 'statuses were: ' . implode(',', $statuses));
        $this->assertSame(0, $results['failed']);

        foreach ($results['metrics'] as $metric) {
            $this->assertSame('passed', $metric['status']);
            $this->assertTrue($metric['retrievalHit']);
            $this->assertTrue($metric['faithfulness']);
        }
    }

    public function test_when_ai_is_not_configured_cases_are_marked_passed_retrieval_only_not_simulated(): void
    {
        // Default testing config: AI_PROVIDER="" (see phpunit.xml) -> isConfigured() is false.
        Http::fake(function (Request $request) {
            $text = (string) ($request->data()['content']['parts'][0]['text'] ?? '');

            return Http::response(['embedding' => ['values' => $this->fakeEmbed($text)]], 200);
        });
        config(['brain.ai.gemini.api_key' => 'test-gemini-key']);

        $evaluation = app(EvaluationService::class);
        $this->assertFalse(app(\App\Domain\Ai\AiGateway::class)->isConfigured());

        $seed = $evaluation->seedDefaultRagDataset(self::TENANT);
        $results = $evaluation->runEvaluation(self::TENANT, $seed['datasetId'], 'test-model');

        $this->assertFalse($results['aiConfigured']);
        $statuses = array_column($results['metrics'], 'status');
        $this->assertNotContains('simulated', $statuses);
        $this->assertNotContains('passed', $statuses, 'no generation call was made, so a full pass must never be claimed');
        $this->assertSame(8, $results['passed'], 'retrieval-only passes still count, but see status below');

        foreach ($results['metrics'] as $metric) {
            $this->assertSame('passed_retrieval_only', $metric['status']);
        }
    }

    public function test_an_unfaithful_answer_with_an_invented_number_is_marked_failed_not_passed(): void
    {
        config(['brain.ai.provider' => 'gemini']);
        config(['brain.ai.gemini.api_key' => 'test-gemini-key']);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, ':embedContent')) {
                $text = (string) ($request->data()['content']['parts'][0]['text'] ?? '');

                return Http::response(['embedding' => ['values' => $this->fakeEmbed($text)]], 200);
            }

            if (str_contains($url, ':generateContent')) {
                // Deliberately invents a number (999) that is not present
                // anywhere in the retrieved context, to prove the numeric
                // citation check actually catches a fabricated figure
                // rather than rubber-stamping any JSON reply.
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [['text' => json_encode(['answer' => 'The policy allows up to 999 days.'])]]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10],
                ], 200);
            }

            return Http::response('unexpected url: ' . $url, 404);
        });

        $evaluation = app(EvaluationService::class);
        $seed = $evaluation->seedDefaultRagDataset(self::TENANT);
        $results = $evaluation->runEvaluation(self::TENANT, $seed['datasetId'], 'test-model');

        $this->assertSame(0, $results['passed']);
        $this->assertSame(8, $results['failed']);

        foreach ($results['metrics'] as $metric) {
            $this->assertSame('failed', $metric['status']);
            $this->assertTrue($metric['retrievalHit']);
            $this->assertFalse($metric['faithfulness']);
            $this->assertSame('unfaithful_answer', $metric['reason']);
        }
    }
}
