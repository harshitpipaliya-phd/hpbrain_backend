<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\PromptRegistry;
use Illuminate\Support\Facades\DB;

final class ContextAssemblyService
{
    public function __construct(private readonly PromptRegistry $promptRegistry)
    {
    }

    public function assemble(string $tenantId, string $query, array $sources): AssembledContext
    {
        $contextParts = [];
        $citations = [];
        $evidence = [];

        foreach ($sources as $source) {
            $type = $source['type'] ?? 'unknown';
            $content = $source['content'] ?? '';

            if ($content === '') {
                continue;
            }

            $contextParts[] = "[{$type}]\n{$content}";
            $evidence[] = ['type' => $type, 'content' => $content];

            if (!empty($source['id'])) {
                $citations[] = ['id' => $source['id'], 'type' => $type];
            }
        }

        $userPrompt = implode("\n\n", $contextParts);
        $userPrompt .= "\n\n[QUERY]\n{$query}";

        try {
            $definition = $this->promptRegistry->get('rag_query');
            $systemPrompt = $definition->systemPrompt;
        } catch (\Throwable) {
            $systemPrompt = 'You are a helpful assistant. Answer based only on the provided context.';
        }

        return new AssembledContext(
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            citations: $citations,
            groundingEvidence: $evidence,
        );
    }
}
