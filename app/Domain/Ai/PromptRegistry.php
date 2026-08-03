<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Repositories\AiCacheRepository;
use RuntimeException;

/**
 * Versioned prompt management.
 *
 * Prompts are retrieved from hpbrain_ai_prompt_templates, keyed by prompt_key
 * and version. The latest active version is returned when version='latest'.
 */
final class PromptRegistry
{
    public function __construct(private readonly AiCacheRepository $cache)
    {
    }

    public function register(PromptDefinition $definition): void
    {
        // Persisted via repository; this registry is a read façade.
    }

    public function get(string $promptKey, string $version = 'latest'): PromptDefinition
    {
        $row = $this->cache->getPromptTemplate($promptKey, $version);

        if ($row === null) {
            throw new RuntimeException("prompt_template_not_found: {$promptKey}");
        }

        return new PromptDefinition(
            key: (string) $row['prompt_key'],
            version: (string) $row['version'],
            name: (string) $row['name'],
            description: (string) ($row['description'] ?? ''),
            purpose: (string) ($row['purpose'] ?? ''),
            systemPrompt: (string) $row['system_prompt'],
            userPromptTemplate: (string) $row['user_prompt_template'],
            responseSchema: json_decode((string) ($row['response_schema'] ?? '{}'), true) ?: [],
            allowedRoles: json_decode((string) ($row['allowed_roles'] ?? '[]'), true) ?: [],
            dataSources: json_decode((string) ($row['data_sources'] ?? '[]'), true) ?: [],
            modelCapability: (string) ($row['model_capability'] ?? 'grounded_chat'),
            generationSettings: json_decode((string) ($row['generation_settings'] ?? '{}'), true) ?: [],
            safetyProfile: (string) ($row['safety_profile'] ?? 'standard'),
            status: (string) ($row['status'] ?? 'draft'),
            changeSummary: (string) ($row['change_summary'] ?? ''),
        );
    }

    public function render(string $promptKey, array $context): string
    {
        $definition = $this->get($promptKey);
        $template = $definition->userPromptTemplate;

        foreach ($context as $key => $value) {
            $template = str_replace('{{'.$key.'}}', (string) $value, $template);
        }

        return $template;
    }

    /** @return array<int, array{version: string, name: string}> */
    public function getAllVersions(string $promptKey): array
    {
        return $this->cache->getPromptVersions($promptKey);
    }
}
