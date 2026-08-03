<?php

declare(strict_types=1);

namespace App\Repositories;

final class AiCacheRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'hpbrain_ai_prompt_templates';
    }

    protected function jsonColumns(): array
    {
        return ['response_schema', 'allowed_roles', 'data_sources', 'generation_settings'];
    }

    public function getPromptTemplate(string $promptKey, string $version = 'latest'): ?array
    {
        $q = $this->scoped('platform')->where('prompt_key', $promptKey)->where('status', 'active');

        if ($version !== 'latest') {
            $q->where('version', (int) $version);
        } else {
            $q->orderByDesc('version');
        }

        $row = $q->first();

        return $row ? $this->hydrate((array) $row) : null;
    }

    /** @return array<int, array{version: string, name: string}> */
    public function getPromptVersions(string $promptKey): array
    {
        $rows = $this->scoped('platform')
            ->where('prompt_key', $promptKey)
            ->orderByDesc('version')
            ->get(['version', 'name']);

        return $rows->map(fn ($r) => ['version' => (string) $r->version, 'name' => (string) $r->name])->all();
    }
}
