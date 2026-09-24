# PROMPT REGISTRY

## Overview

The Prompt Registry provides versioned prompt management, ensuring every AI call can be traced to its exact prompt version.

## PromptDefinition

```php
final class PromptDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly string $name,
        public readonly string $description,
        public readonly string $purpose,
        public readonly string $systemPrompt,
        public readonly string $userPromptTemplate,
        public readonly array $responseSchema = [],
        public readonly array $allowedRoles = [],
        public readonly array $dataSources = [],
        public readonly string $modelCapability = 'grounded_chat',
        public readonly array $generationSettings = [],
        public readonly string $safetyProfile = 'standard',
        public readonly string $status = 'draft',
        public readonly string $changeSummary = '',
    )
}
```

## PromptRegistry

```php
final class PromptRegistry
{
    public function register(PromptDefinition $definition): void;
    public function get(string $promptKey, string $version = 'latest'): PromptDefinition;
    public function render(string $promptKey, array $context): string;
    public function getAllVersions(string $promptKey): array;
}
```

## Resolution Order

1. Tenant-specific template (tenant_id = actual tenant)
2. Shared template (tenant_id = '*')

## Database

Stored in `hpbrain_ai_prompt_templates`:
- `prompt_key` - Unique identifier
- `version` - Integer version
- `status` - draft/active/archived
- `system_prompt` - System instructions
- `user_prompt_template` - Template with `{{variables}}`
- `response_schema` - JSON schema for validation
- `allowed_roles` - Roles that can use this prompt
- `data_sources` - Data sources this prompt can access
- `model_capability` - Required model capability
- `generation_settings` - Model parameters
- `safety_profile` - Safety rules to apply

## Rendering

Variables are substituted using `{{variable}}` syntax:
```php
$registry->render('rag_query', ['query' => 'test', 'context' => '...']);
```
