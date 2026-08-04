<?php

declare(strict_types=1);

namespace App\Domain\Signals;

use App\Repositories\OperationalRecordRepository;

/**
 * Decides which extra signal rules apply to a tenant.
 *
 * The whole point of this class is that the answer is derived from DATA, never
 * from an organization's name. It asks the operational-records table which
 * datasets the tenant actually holds and attaches the rules that read them.
 *
 * Consequences worth stating:
 *   - FiberValley appears nowhere in this file, or in OperationalSignalWriter.
 *   - An organization with no imported data gets exactly the five ERP rules it
 *     gets today, and pays one indexed GROUP BY for the privilege of asking.
 *   - The next organization to import a 'complaint' dataset inherits the
 *     complaint rules with no code change at all.
 *
 * If the table does not exist yet â€” a deployment where the migration has not
 * run â€” this returns no rules rather than throwing. A missing optional table
 * must never take down signal generation for organizations that never used it.
 */
final class SignalRuleRegistry
{
    public function __construct(
        private readonly OperationalRecordRepository $records,
        private readonly OperationalSignalRules $operationalRules,
    ) {
    }

    /**
     * @return array<int, callable(): array{created: bool}>
     */
    public function extraRulesFor(OperationalSignalWriter $generator, string $tenantId): array
    {
        try {
            $datasets = $this->records->datasetCounts($tenantId);
        } catch (\Throwable) {
            return [];
        }

        if ($datasets === []) {
            return [];
        }

        return $this->operationalRules->rulesFor($generator, $tenantId, $datasets);
    }
}
