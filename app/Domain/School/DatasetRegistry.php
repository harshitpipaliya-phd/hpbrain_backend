<?php

declare(strict_types=1);

namespace App\Domain\School;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a tenant's datasets plays which role.
 *
 * WHY THIS EXISTS. The student, academic-structure and academic-intelligence
 * endpoints all need to know two things: which dataset holds exam results, and
 * which holds fee receipts. The obvious shortcut is to write
 * `where('dataset', 'lions-result-data')` in each of them, and that shortcut is
 * exactly what makes an application single-tenant. The next school's export will
 * not be called lions-result-data, and a controller that names it can only ever
 * serve Lions.
 *
 * So the role is DECLARED ON THE SOURCE, in hpbrain_data_sources.config as
 * `{"dataset_role": "academic"}`, and read from there. A tenant with no such
 * source gets null and the screens fall back to whatever they show for an
 * organization without a dataset — which is how Sunrise and FiberValley keep
 * working unchanged.
 *
 * WHY NOT INFER THE ROLE FROM THE DATA. It was tempting to classify a dataset by
 * shape — "has a quantity column, therefore marks" — and it is guesswork. A
 * misclassification would silently publish fee receipts as exam results under a
 * heading a professor would read as fact. Declaring the role costs one JSON key
 * at configure time and cannot be wrong by accident.
 *
 * CACHED PER REQUEST ONLY. This is read several times per request by different
 * controllers and changes only when a source is reconfigured, so it is memoised
 * on the instance rather than put in the shared cache, where it would need an
 * invalidation story it does not earn.
 *
 * THE MEMO ONLY WORKS BECAUSE THE CONTAINER BINDS THIS scoped(). Laravel's
 * default is a fresh instance per injection, which would give every collaborator
 * its own empty memo and run the query once each — see
 * IntelligenceServiceProvider, where the binding lives.
 */
final class DatasetRegistry
{
    public const ROLE_ACADEMIC = 'academic';
    public const ROLE_FEES = 'fees';

    /** @var array<string, array<string, string|null>> tenantId => role => dataset key */
    private array $memo = [];

    /** The dataset holding exam results for this tenant, or null. */
    public function academic(string $tenantId): ?string
    {
        return $this->roles($tenantId)[self::ROLE_ACADEMIC] ?? null;
    }

    /** The dataset holding fee receipts for this tenant, or null. */
    public function fees(string $tenantId): ?string
    {
        return $this->roles($tenantId)[self::ROLE_FEES] ?? null;
    }

    /** True when this tenant's intelligence comes from datasets rather than the ERP. */
    public function hasSchoolDatasets(string $tenantId): bool
    {
        return $this->academic($tenantId) !== null || $this->fees($tenantId) !== null;
    }

    /**
     * @return array<string, string|null> role => dataset key
     */
    public function roles(string $tenantId): array
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        $roles = [self::ROLE_ACADEMIC => null, self::ROLE_FEES => null];

        if (! Schema::hasTable('hpbrain_data_sources')) {
            return $this->memo[$tenantId] = $roles;
        }

        $rows = DB::table('hpbrain_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->whereNotNull('config')
            ->get(['source_key', 'config']);

        foreach ($rows as $row) {
            $config = json_decode((string) $row->config, true);

            if (! is_array($config)) {
                continue;
            }

            $role = $config['dataset_role'] ?? null;

            if (! is_string($role) || ! array_key_exists($role, $roles)) {
                continue;
            }

            // `dataset` overrides source_key because commitDataset() writes the
            // records under config.dataset when it is set, and this has to name
            // the value actually in the dataset column.
            $roles[$role] = (string) ($config['dataset'] ?? $row->source_key);
        }

        return $this->memo[$tenantId] = $roles;
    }

    /** Drop the memo — used by the configure command after it writes a role. */
    public function forget(string $tenantId): void
    {
        unset($this->memo[$tenantId]);
    }
}
