<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Ai\AssembledContext;
use App\Domain\Ai\RetrievalResult;

final class RetrievalService
{
    public function searchEntities(string $tenantId, string $query, array $entityTypes): array
    {
        $results = [];

        foreach ($entityTypes as $entityType) {
            $table = match ($entityType) {
                'signal' => 'hpbrain_signals',
                'evidence' => 'hpbrain_evidence',
                'decision' => 'hpbrain_decisions',
                'capability' => 'hpbrain_capabilities',
                default => null,
            };

            if ($table === null) {
                continue;
            }

            $rows = \Illuminate\Support\Facades\DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                      ->orWhere('name', 'like', "%{$query}%")
                      ->orWhere('description', 'like', "%{$query}%");
                })
                ->limit(10)
                ->get();

            foreach ($rows as $row) {
                $results[] = [
                    'id' => (string) $row->id,
                    'type' => $entityType,
                    'content' => (string) ($row->title ?? $row->name ?? $row->description ?? ''),
                    'score' => 0.8,
                ];
            }
        }

        return $results;
    }

    public function searchDocuments(string $tenantId, string $query): array
    {
        return [];
    }

    public function searchGraph(string $tenantId, string $query): array
    {
        return [];
    }

    public function searchMemory(string $tenantId, string $query): array
    {
        return [];
    }
}
