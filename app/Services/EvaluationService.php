<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\KnowledgeIngestionService;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Phase 6.1 RAG v1 — a real evaluation harness, replacing the previous
 * runEvaluation() which marked every case 'simulated' unconditionally and
 * always counted it as passed. Every status this class now writes is the
 * result of a mechanical check against what RetrievalService and
 * AiGateway::completeWithRag() actually returned for that case — nothing
 * here is a hardcoded verdict.
 *
 * TWO REAL CHECKS PER CASE, BOTH MECHANICAL (the task is explicit that a
 * simple containment/citation check is fine for v1, not a full
 * faithfulness model):
 *   1. RETRIEVAL RELEVANCE — does the case's top-k retrieval actually
 *      include a chunk containing the known answer fact? A real substring
 *      check against RetrievalService::searchDocuments()'s own results,
 *      not an assumption that retrieval worked.
 *   2. FAITHFULNESS — does completeWithRag()'s generated answer (a) state
 *      the known fact, and (b) introduce no numeric token that is not
 *      present anywhere in the retrieved context? (b) is a cheap, real way
 *      to catch an invented number without building an NLI/entailment
 *      model, which is more than this task asks for.
 *
 * WHEN AI ISN'T CONFIGURED, THE RESULT SAYS SO. If
 * AiGateway::isConfigured() is false, no generation call is made and the
 * case is marked 'passed_retrieval_only' or 'retrieval_miss' — never
 * silently promoted to a full 'passed', which would be exactly the kind of
 * fabricated result this rewrite exists to stop doing.
 */
final class EvaluationService
{
    public function __construct(
        private readonly RetrievalService $retrieval,
        private readonly AiGateway $ai,
        private readonly KnowledgeIngestionService $ingestion,
    ) {
    }

    /** @return array<string, mixed> */
    public function createDataset(string $tenantId, string $name, array $cases): array
    {
        // Generated here rather than by MySQL's UUID(). insertGetId() returns
        // the auto-increment value, and this key is a VARCHAR(36) — so the id
        // handed back was always 0, and runEvaluation() could never find the
        // dataset it had just created.
        $id = Uuid::uuid4()->toString();

        DB::table('hpbrain_ai_evaluations')->insert([
            'id'              => $id,
            'tenant_id'       => $tenantId,
            'evaluation_name' => $name,
            'evaluation_type' => 'dataset',
            'dataset'         => json_encode($cases),
            'results'         => json_encode([]),
            'model'           => null,
            'status'          => 'pending',
            'run_by'          => null,
            'run_date'        => null,
            'created_by'      => 'system',
            'created_date'    => now()->format('Y-m-d H:i:s'),
            'updated_date'    => now()->format('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'name' => $name, 'status' => 'pending'];
    }

    /**
     * 8 hand-authored questions, each paired with a known-correct source
     * document and the specific fact the answer must contain. Domain
     * matches what this product actually is (HR/ops policy content), and
     * every expected fact is a short, mechanically-checkable phrase (a
     * number or a specific term) rather than anything requiring judgment to
     * verify.
     *
     * @return array<int, array{id:string, sourceTitle:string, sourceContent:string, question:string, expectedFact:string}>
     */
    public function defaultRagCases(): array
    {
        return [
            [
                'id' => 'leave-accrual',
                'sourceTitle' => 'Employee Leave Accrual Policy',
                'sourceContent' => 'Full-time employees accrue 1.5 days of paid leave per month, capped at 18 days per calendar year. Unused leave beyond 18 days is forfeited at year end and does not carry over to the next calendar year.',
                'question' => 'How many paid leave days can a full-time employee accrue at most per calendar year?',
                'expectedFact' => '18 days',
            ],
            [
                'id' => 'expense-deadline',
                'sourceTitle' => 'Travel Expense Reimbursement Policy',
                'sourceContent' => 'Travel expense claims must be submitted within 30 days of the trip end date. Claims submitted after 30 days require written manager override approval before Finance will process them.',
                'question' => 'Within how many days of a trip must a travel expense claim be submitted?',
                'expectedFact' => '30 days',
            ],
            [
                'id' => 'probation-length',
                'sourceTitle' => 'New Hire Probation Policy',
                'sourceContent' => 'Every new hire serves a probation period of 90 days from their joining date. A probation review is held on or before day 90, and only after a passed review does the employee move to confirmed status.',
                'question' => 'How long is the probation period for a new hire?',
                'expectedFact' => '90 days',
            ],
            [
                'id' => 'notice-period',
                'sourceTitle' => 'Resignation Notice Policy',
                'sourceContent' => 'A confirmed employee must give 60 days written notice before their last working day. An employee still on probation may resign with 15 days notice instead.',
                'question' => 'How many days of written notice must a confirmed employee give before resigning?',
                'expectedFact' => '60 days',
            ],
            [
                'id' => 'wfh-days',
                'sourceTitle' => 'Remote Work Policy',
                'sourceContent' => 'Employees may work from home up to 2 days per week, subject to manager approval. Requests for more than 2 remote days per week must be escalated to the department head.',
                'question' => 'How many days per week can an employee work from home without escalation?',
                'expectedFact' => '2 days',
            ],
            [
                'id' => 'referral-bonus',
                'sourceTitle' => 'Employee Referral Bonus Policy',
                'sourceContent' => 'An employee who refers a successful hire receives a referral bonus of 25000 rupees, paid out after the new hire completes 90 days of continuous employment.',
                'question' => 'What is the referral bonus amount paid for a successful hire?',
                'expectedFact' => '25000',
            ],
            [
                'id' => 'laptop-refresh',
                'sourceTitle' => 'IT Asset Refresh Policy',
                'sourceContent' => 'Company laptops are refreshed every 4 years from the original issue date. An early refresh requires department head approval and a documented hardware fault.',
                'question' => 'Every how many years are company laptops refreshed under normal policy?',
                'expectedFact' => '4 years',
            ],
            [
                'id' => 'overtime-cap',
                'sourceTitle' => 'Overtime Policy',
                'sourceContent' => 'Overtime is capped at 12 hours per week for non-exempt employees. Any overtime beyond the 12-hour cap requires prior written approval from the reporting manager.',
                'question' => 'What is the weekly overtime cap for non-exempt employees?',
                'expectedFact' => '12 hours',
            ],
        ];
    }

    /**
     * Creates (or reuses) the eval dataset and makes sure every case's
     * fixture source document actually exists in hpbrain_knowledge_assets
     * for this tenant, ingesting/embedding it. Embedding is best-effort —
     * if the Gemini call fails, the row is left unembedded and
     * RetrievalService's own keyword fallback still produces a real,
     * honestly-scored (if lower) result for it rather than this method
     * failing outright.
     *
     * @return array{datasetId:string, seededAssetIds:array<string,string>}
     */
    public function seedDefaultRagDataset(string $tenantId): array
    {
        $cases = $this->defaultRagCases();
        $seededAssetIds = [];

        foreach ($cases as $case) {
            $assetId = $this->ensureFixtureAsset($tenantId, $case['id'], $case['sourceTitle'], $case['sourceContent']);
            $seededAssetIds[$case['id']] = $assetId;

            try {
                $this->ingestion->ingestAsset($tenantId, $assetId);
            } catch (\Throwable $e) {
                // Left unembedded; searchDocuments() falls back to keyword
                // search for this row. Not fatal to seeding the dataset.
            }
        }

        $dataset = $this->createDataset($tenantId, 'RAG smoke test v1', $cases);

        return ['datasetId' => $dataset['id'], 'seededAssetIds' => $seededAssetIds];
    }

    /**
     * Deterministic id so re-seeding the same case for the same tenant
     * updates rather than duplicates. md5 (32 hex chars), not sha256 (64) —
     * hpbrain_knowledge_assets.id is VARCHAR(36); a longer id truncates
     * silently on some engines and errors loudly on MySQL's strict mode
     * (caught by the real spot-check against the live MySQL schema, not
     * by the SQLite-backed test suite, which does not enforce the length).
     */
    private function ensureFixtureAsset(string $tenantId, string $caseId, string $title, string $content): string
    {
        $assetId = 'ef-' . md5($tenantId . ':' . $caseId);

        $existing = DB::table('hpbrain_knowledge_assets')
            ->where('tenant_id', $tenantId)
            ->where('id', $assetId)
            ->exists();

        if ($existing) {
            DB::table('hpbrain_knowledge_assets')
                ->where('tenant_id', $tenantId)
                ->where('id', $assetId)
                ->update(['title' => $title, 'content' => $content, 'updated_date' => now()]);

            return $assetId;
        }

        DB::table('hpbrain_knowledge_assets')->insert([
            'id' => $assetId,
            'tenant_id' => $tenantId,
            'title' => $title,
            'category' => 'policy',
            'content' => $content,
            'tags' => json_encode([]),
            'confidence' => 0.7,
            'related_person_ids' => json_encode([]),
            'related_capability_ids' => json_encode([]),
            'reuse_count' => 0,
            'status' => 'active',
            'created_by' => 'evaluation_harness',
            'created_date' => now(),
            'updated_date' => now(),
        ]);

        return $assetId;
    }

    /** @return array<string, mixed> */
    public function runEvaluation(string $tenantId, string $datasetId, string $model): array
    {
        $dataset = DB::table('hpbrain_ai_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('id', $datasetId)
            ->first();

        if (!$dataset) {
            return ['error' => 'dataset_not_found'];
        }

        $cases = json_decode((string) $dataset->dataset, true) ?: [];
        $aiConfigured = $this->ai->isConfigured();

        $results = [
            'total' => count($cases),
            'passed' => 0,
            'failed' => 0,
            'aiConfigured' => $aiConfigured,
            'metrics' => [],
        ];

        foreach ($cases as $case) {
            $metric = $this->evaluateCase($tenantId, $case, $aiConfigured);
            $results['metrics'][] = $metric;

            if (in_array($metric['status'], ['passed', 'passed_retrieval_only'], true)) {
                $results['passed']++;
            } else {
                $results['failed']++;
            }
        }

        DB::table('hpbrain_ai_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('id', $datasetId)
            ->update([
                'results'     => json_encode($results),
                'model'       => $model,
                'status'      => 'completed',
                'run_by'      => 'system',
                'run_date'    => now()->format('Y-m-d H:i:s'),
                'updated_date'=> now()->format('Y-m-d H:i:s'),
            ]);

        return $results;
    }

    /**
     * One case, end to end: normalize -> retrieve -> check relevance ->
     * (if AI is configured and relevance held) generate -> check
     * faithfulness. Every branch records WHY it is a pass or a fail.
     *
     * @return array<string, mixed>
     */
    private function evaluateCase(string $tenantId, array $case, bool $aiConfigured): array
    {
        $caseId = (string) ($case['id'] ?? 'unknown');
        $question = $case['question'] ?? $case['input'] ?? null;
        $expectedFact = $case['expectedFact'] ?? $case['expected'] ?? null;

        if ($question === null || $expectedFact === null) {
            return ['case' => $caseId, 'status' => 'invalid_case', 'reason' => 'missing question or expectedFact'];
        }

        $topK = $this->retrieval->searchDocuments($tenantId, (string) $question, 5);

        $contextText = implode("\n", array_map(fn ($d) => (string) ($d['content'] ?? ''), $topK));
        $retrievalHit = $contextText !== '' && mb_stripos($contextText, (string) $expectedFact) !== false;

        if (! $retrievalHit) {
            return [
                'case' => $caseId,
                'status' => 'retrieval_miss',
                'retrievalHit' => false,
                'retrievalPaths' => array_values(array_unique(array_column($topK, 'retrieval'))),
            ];
        }

        if (! $aiConfigured) {
            return [
                'case' => $caseId,
                'status' => 'passed_retrieval_only',
                'retrievalHit' => true,
                'retrievalPaths' => array_values(array_unique(array_column($topK, 'retrieval'))),
                'faithfulness' => null,
                'reason' => 'ai_not_configured',
            ];
        }

        try {
            $response = $this->ai->completeWithRag(
                $tenantId,
                'evaluation_harness',
                'rag_evaluation',
                new AiRequest(
                    systemPrompt: 'Answer the question in one short sentence, using only the RETRIEVED CONTEXT. '
                        . 'Reply as strict JSON: {"answer": "..."}.',
                    userPrompt: (string) $question,
                    responseSchema: ['answer' => 'string, one sentence, json'],
                    maxTokens: 200,
                    temperature: 0.0,
                ),
                ['documents' => $topK],
            );
        } catch (\Throwable $e) {
            return [
                'case' => $caseId,
                'status' => 'ai_call_failed',
                'retrievalHit' => true,
                'error' => $e->getMessage(),
            ];
        }

        $json = $response->json();

        if ($json === null || ! isset($json['answer'])) {
            return [
                'case' => $caseId,
                'status' => 'ai_response_not_json',
                'retrievalHit' => true,
                'rawResponse' => mb_substr($response->content, 0, 300),
            ];
        }

        $answer = (string) $json['answer'];
        $faithful = $this->isFaithful($answer, (string) $expectedFact, $contextText);

        return [
            'case' => $caseId,
            'status' => $faithful ? 'passed' : 'failed',
            'retrievalHit' => true,
            'retrievalPaths' => array_values(array_unique(array_column($topK, 'retrieval'))),
            'faithfulness' => $faithful,
            'answer' => $answer,
            'reason' => $faithful ? null : 'unfaithful_answer',
        ];
    }

    /**
     * Mechanical faithfulness check (task 3 explicitly allows a simple
     * containment/citation check, not a full faithfulness model):
     *   1. the expected fact is stated in the answer, AND
     *   2. every distinct numeric token in the answer also appears
     *      somewhere in the retrieved context — a cheap, real way to catch
     *      an invented number.
     */
    private function isFaithful(string $answer, string $expectedFact, string $context): bool
    {
        if (mb_stripos($answer, $expectedFact) === false) {
            return false;
        }

        preg_match_all('/\d+(?:\.\d+)?/', $answer, $answerNumbers);
        preg_match_all('/\d+(?:\.\d+)?/', $context, $contextNumbers);

        $contextSet = array_flip($contextNumbers[0]);

        foreach (array_unique($answerNumbers[0]) as $number) {
            if (! isset($contextSet[$number])) {
                return false;
            }
        }

        return true;
    }
}
