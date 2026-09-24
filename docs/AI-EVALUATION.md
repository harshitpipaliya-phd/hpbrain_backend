# AI EVALUATION

## Overview

AI Evaluation provides tools for testing and validating AI model performance against ground truth datasets.

## EvaluationService

### createDataset(string $name, array $cases): EvaluationDataset

Creates a new evaluation dataset with test cases:
```php
$dataset = $service->createDataset('Test Set', [
    ['id' => 'case-1', 'input' => 'What is X?', 'expected' => 'X is...'],
    ['id' => 'case-2', 'input' => 'How does Y work?', 'expected' => 'Y works by...'],
]);
```

### runEvaluation(string $datasetId, string $model): EvaluationResult

Runs the dataset against a model and returns metrics:
```php
$results = $service->runEvaluation($datasetId, 'gpt-4');
// Returns: total, passed, failed, metrics[]
```

## Metrics

| Metric | Description |
|--------|-------------|
| citation_accuracy | % of citations that are valid |
| tenant_isolation | No cross-tenant data leakage |
| hallucination | % of claims without grounding |
| relevance | Response relevance to query |
| refusal | Appropriate refusal rate |
| schema_validity | JSON schema compliance |
| prompt_injection | Resistance to injection |
| latency | Response time |
| cost | USD per call |

## Database

Stored in `hpbrain_ai_evaluations`:
- `evaluation_name`: Human-readable name
- `evaluation_type`: dataset/benchmark
- `dataset`: JSON test cases
- `results`: JSON evaluation results
- `model`: Model tested
- `status`: pending/running/completed/failed
- `run_by`: User who triggered evaluation
- `run_date`: When it ran

## API Endpoints

- `GET /api/v1/ai/evaluations/{tenantId}` - List evaluations
- `POST /api/v1/ai/evaluations` - Create evaluation
- `GET /api/v1/ai/evaluations/{tenantId}/{id}` - Get evaluation
- `POST /api/v1/ai/evaluations/{tenantId}/{id}/run` - Run evaluation
- `GET /api/v1/ai/evaluations/{tenantId}/{id}/results` - Get results
