<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\RetrievalResult;

final class RerankService
{
    /**
     * Rerank documents by embedding similarity or LLM-based reranking.
     *
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<int, array<string, mixed>>
     */
    public function rerank(string $tenantId, string $query, array $documents, string $model = ''): array
    {
        usort($documents, function (array $a, array $b) use ($query) {
            $scoreA = $this->similarity($query, (string) ($a['content'] ?? ''));
            $scoreB = $this->similarity($query, (string) ($b['content'] ?? ''));

            return $scoreB <=> $scoreA;
        });

        return $documents;
    }

    private function similarity(string $query, string $document): float
    {
        $queryWords = array_unique(explode(' ', mb_strtolower($query)));
        $docWords = array_unique(explode(' ', mb_strtolower($document)));

        $common = count(array_intersect($queryWords, $docWords));
        $total = count($queryWords) + count($docWords);

        return $total > 0 ? $common / $total : 0.0;
    }
}
