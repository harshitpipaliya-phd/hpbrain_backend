<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * DeepSeek interpretation over verified organization intelligence.
 *
 * The deterministic engine owns facts, calculations, rankings and provenance.
 * This class gives the model only that compact verified context and accepts only
 * a small JSON interpretation back. If the provider is unavailable or the shape is
 * invalid, the response is an explicit unavailable state rather than fallback prose.
 */
final class ExecutiveIntelligenceInterpreter
{
    private const TTL_SECONDS = 21600;

    public function __construct(private readonly AiGateway $ai)
    {
    }

    /**
     * @param array<string, mixed> $intelligence IntelligenceEngine::forOrganization()
     *
     * @return array<string, mixed>
     */
    public function interpret(string $tenantId, string $actorId, array $intelligence, bool $fresh = false): array
    {
        $version = (string) ($intelligence['dataVersion'] ?? '');
        $key = 'brain:intel:interpretation:v1:'.$tenantId.':'.$version;

        if (! $this->ai->isConfigured()) {
            return $this->unavailable($version, 'ai_provider_not_configured');
        }

        if ($fresh) {
            Cache::store('file')->forget($key);
        } else {
            $cached = Cache::store('file')->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $context = $this->context($intelligence);

        try {
            $response = $this->ai->complete(
                new AiRequest(
                    systemPrompt: $this->systemPrompt(),
                    userPrompt: "Interpret this verified organization intelligence context as JSON only:\n"
                        . (json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}'),
                    responseSchema: $this->schema(),
                    maxTokens: 2800,
                    temperature: 0.1,
                ),
                tenantId: $tenantId,
                actorId: $actorId,
                service: 'organization_intelligence_interpretation',
                templateId: 'organizational-intelligence-analyst-v1',
                entityType: 'organization',
                entityId: $tenantId,
            );
        } catch (Throwable $e) {
            return $this->unavailable($version, 'ai_call_failed', mb_substr($e->getMessage(), 0, 300));
        }

        $json = $response->json();
        if (! is_array($json)) {
            return $this->unavailable($version, 'invalid_json_response');
        }

        $validated = $this->validated($json, $version, $response->model);
        Cache::store('file')->put($key, $validated, self::TTL_SECONDS);

        return $validated;
    }

    /**
     * @param array<string, mixed> $all
     *
     * @return array<string, mixed>
     */
    private function context(array $all): array
    {
        $decisions = $all['decisions'];
        $knowledge = $all['knowledge'];
        $risks = $all['risks'];
        $gaps = $all['gaps'];
        $recommendations = $all['recommendations'];
        $profile = $all['profile'];
        $state = $all['state'];

        return [
            'rules' => [
                'facts_are_verified' => true,
                'model_may_interpret_not_recalculate' => true,
                'do_not_invent_numbers_names_departments_capabilities_or_outcomes' => true,
                'unknown_is_valid' => true,
                'financial_benefit' => 'Financial benefit cannot be reliably estimated from available organizational data unless explicit financial inputs are present.',
            ],
            'data_profile' => [
                'tenant_id' => $all['tenantId'],
                'data_version' => $all['dataVersion'],
                'source_systems' => $profile['sourceSystems'],
                'totals' => $profile['totals'],
                'loop' => $profile['loop'],
                'datasets' => array_map(static fn (array $d): array => [
                    'dataset' => $d['dataset'],
                    'label' => $d['label'],
                    'records' => $d['records'],
                    'spanDays' => $d['spanDays'],
                    'closureRate' => $d['closureRate'],
                    'duplicateKeys' => $d['duplicateKeys'],
                    'measure' => $d['measure'],
                    'fields' => array_map(static fn (array $f): array => [
                        'field' => $f['field'],
                        'completeness' => $f['completeness'],
                        'distinct' => $f['distinct'],
                        'invariant' => $f['invariant'],
                    ], $d['fields']),
                ], $profile['datasets']),
            ],
            'organization_state' => [
                'overall' => $state['overall'],
                'headline' => $state['headline'],
                'strengths' => array_slice($state['strengths'], 0, 4),
                'weaknesses' => array_slice($state['weaknesses'], 0, 4),
                'unmeasured' => array_slice($state['unmeasured'], 0, 4),
            ],
            'decisions' => [
                'state' => $decisions['state'],
                'accuracy' => $decisions['accuracy'],
                'latency' => $decisions['latency'],
                'quality' => $decisions['quality'],
                'byCategory' => array_slice($decisions['byCategory'], 0, 8),
                'acceptanceVsEvidence' => $decisions['acceptanceVsEvidence'],
                'acceptanceVsAccuracy' => $decisions['acceptanceVsAccuracy'],
                'rootCause' => $decisions['rootCause'],
            ],
            'knowledge' => [
                'state' => $knowledge['state'],
                'domains' => array_slice($knowledge['domains'], 0, 8),
                'evidence' => $knowledge['evidence'],
                'blindSpots' => array_slice($knowledge['blindSpots'], 0, 10),
                'learnNext' => array_slice($knowledge['learnNext'], 0, 5),
            ],
            'risks' => [
                'open' => $risks['open'],
                'registered' => $risks['registered'],
                'derived' => $risks['derived'],
                'unowned' => $risks['unowned'],
                'maxSeverity' => $risks['maxSeverity'],
                'byRootCause' => $risks['byRootCause'],
                'topRisks' => array_slice($risks['risks'], 0, 8),
            ],
            'gaps' => [
                'total' => $gaps['total'],
                'critical' => $gaps['critical'],
                'high' => $gaps['high'],
                'byArea' => $gaps['byArea'],
                'topGaps' => array_slice($gaps['gaps'], 0, 10),
            ],
            'recommendations' => [
                'total' => $recommendations['total'],
                'critical' => $recommendations['critical'],
                'firstAction' => $recommendations['firstAction'],
                'topRecommendations' => array_slice($recommendations['recommendations'], 0, 6),
                'method' => $recommendations['method'],
            ],
            'movement' => [
                'trends' => array_slice($all['patterns']['trends'], 0, 8),
                'concentrations' => array_slice($all['patterns']['concentrations'], 0, 8),
                'moving' => array_slice($all['patterns']['moving'], 0, 5),
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an Organizational Intelligence Analyst.

Analyze only the structured, verified organization facts provided by the backend. The database supplies facts. The backend supplies deterministic metrics, relationships, confidence, provenance and rankings. Your job is interpretation, explanation and executive recommendation wording.

Rules:
- Return only JSON matching the requested schema.
- Never invent data, numbers, departments, capabilities, risks, outcomes or financial ROI.
- Never recalculate or change quantitative facts. Use only numbers already present in the context.
- Distinguish observed facts from inference and recommendations.
- If evidence is insufficient, say "INSUFFICIENT EVIDENCE", "UNKNOWN", "TREND UNAVAILABLE" or "NOT DETERMINABLE".
- Prefer specific, actionable insights using the organization's actual labels from the context.
- Explain what is happening, why it matters, what to do, how to do it, expected non-financial benefit and confidence.
- Every finding and recommendation must reference supporting evidence from the context in short text form.
- Avoid generic consulting language.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'executive_summary' => 'string',
            'organizational_state' => [
                'overall_assessment' => 'string',
                'strengths' => ['string'],
                'weaknesses' => ['string'],
                'confidence' => 'number|null',
            ],
            'critical_findings' => [[
                'title' => 'string',
                'observed_fact' => 'string',
                'inference' => 'string',
                'why_it_matters' => 'string',
                'evidence' => ['string'],
                'confidence' => 'number|null',
                'severity' => 'critical|high|medium|low|unknown',
                'impact' => 'string',
            ]],
            'root_causes' => [[
                'cause' => 'string',
                'affected_area' => 'string',
                'observed_fact' => 'string',
                'inference' => 'string',
                'evidence' => ['string'],
                'confidence' => 'number|null',
            ]],
            'blind_spots' => ['string'],
            'risks' => ['string'],
            'opportunities' => ['string'],
            'recommendations' => [[
                'title' => 'string',
                'priority' => 'critical|high|medium|low',
                'observed_fact' => 'string',
                'problem' => 'string',
                'action' => 'string',
                'why' => 'string',
                'how' => 'string',
                'evidence' => ['string'],
                'expected_benefit' => 'string',
                'expected_impact' => 'string',
                'effort' => 'string',
                'time_horizon' => 'string',
                'confidence' => 'number|null',
            ]],
            'next_steps' => ['string'],
        ];
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function validated(array $json, string $version, string $model): array
    {
        return [
            'status' => 'available',
            'model' => $model,
            'dataVersion' => $version,
            'generatedAt' => gmdate('c'),
            'executive_summary' => $this->text($json['executive_summary'] ?? ''),
            'organizational_state' => [
                'overall_assessment' => $this->text($json['organizational_state']['overall_assessment'] ?? ''),
                'strengths' => $this->strings($json['organizational_state']['strengths'] ?? []),
                'weaknesses' => $this->strings($json['organizational_state']['weaknesses'] ?? []),
                'confidence' => $this->confidence($json['organizational_state']['confidence'] ?? null),
            ],
            'critical_findings' => $this->objects($json['critical_findings'] ?? [], [
                'title', 'observed_fact', 'inference', 'why_it_matters', 'impact',
            ], ['evidence'], ['confidence'], ['severity']),
            'root_causes' => $this->objects($json['root_causes'] ?? [], [
                'cause', 'affected_area', 'observed_fact', 'inference',
            ], ['evidence'], ['confidence']),
            'blind_spots' => $this->strings($json['blind_spots'] ?? []),
            'risks' => $this->strings($json['risks'] ?? []),
            'opportunities' => $this->strings($json['opportunities'] ?? []),
            'recommendations' => $this->objects($json['recommendations'] ?? [], [
                'title', 'observed_fact', 'problem', 'action', 'why', 'how', 'expected_benefit', 'expected_impact', 'effort', 'time_horizon',
            ], ['evidence'], ['confidence'], ['priority']),
            'next_steps' => $this->strings($json['next_steps'] ?? []),
            'guardrails' => [
                'facts' => 'Quantitative facts remain deterministic backend output.',
                'model_role' => 'DeepSeek produced interpretation only.',
                'unknown_policy' => 'Insufficient evidence is rendered as unknown, not guessed.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $version, string $reason, ?string $detail = null): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'detail' => $detail,
            'dataVersion' => $version,
            'generatedAt' => gmdate('c'),
            'executive_summary' => 'UNKNOWN: DeepSeek interpretation is unavailable. Deterministic organization metrics remain available.',
            'organizational_state' => [
                'overall_assessment' => 'NOT DETERMINABLE by LLM interpretation.',
                'strengths' => [],
                'weaknesses' => [],
                'confidence' => null,
            ],
            'critical_findings' => [],
            'root_causes' => [],
            'blind_spots' => [],
            'risks' => [],
            'opportunities' => [],
            'recommendations' => [],
            'next_steps' => [],
            'guardrails' => [
                'facts' => 'Quantitative facts remain deterministic backend output.',
                'model_role' => 'No model interpretation was accepted for this response.',
                'unknown_policy' => 'Unavailable interpretation is reported explicitly rather than replaced with generated fallback advice.',
            ],
        ];
    }

    /** @param array<int, string> $textKeys @param array<int, string> $listKeys @param array<int, string> $confidenceKeys @param array<int, string> $enumKeys */
    private function objects(mixed $rows, array $textKeys, array $listKeys = [], array $confidenceKeys = [], array $enumKeys = []): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach (array_slice($rows, 0, 8) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $item = [];
            foreach ($textKeys as $key) {
                $item[$key] = $this->text($row[$key] ?? '');
            }
            foreach ($listKeys as $key) {
                $item[$key] = $this->strings($row[$key] ?? []);
            }
            foreach ($confidenceKeys as $key) {
                $item[$key] = $this->confidence($row[$key] ?? null);
            }
            foreach ($enumKeys as $key) {
                $item[$key] = $this->text($row[$key] ?? 'unknown');
            }
            $out[] = $item;
        }

        return $out;
    }

    private function text(mixed $value): string
    {
        return trim(mb_substr(is_scalar($value) ? (string) $value : '', 0, 900));
    }

    private function confidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round(max(0.0, min(1.0, (float) $value)), 4);
    }

    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $v): string => $this->text($v), array_slice($values, 0, 10))));
    }
}
