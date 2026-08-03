<?php

declare(strict_types=1);

namespace App\Domain\Ai;

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
    ) {
    }
}
