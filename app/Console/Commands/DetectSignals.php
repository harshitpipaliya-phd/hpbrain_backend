<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Signals\OperationalSignalWriter;
use App\Domain\Signals\RuleEvaluator;
use App\Domain\Signals\SignalRuleRegistry;
use App\Domain\Universal\EntityResolver;
use Illuminate\Console\Command;

/**
 * Run the detection rules across every tenant.
 *
 * The rules existed but nothing ran them. RuleEvaluator::evaluate() had exactly
 * one caller — an HTTP endpoint — so a signal was raised only if a human
 * happened to open a screen and press a button. A Brain that notices problems
 * only while being watched cannot show movement over time, which is why every
 * trend on every screen was flat or absent: there was no history to plot,
 * because history is a by-product of running.
 *
 * Scheduled hourly rather than daily. Detection is cheap — a handful of counting
 * queries per tenant — and the arrival PATTERN is itself intelligence: knowing
 * that six issues appeared this morning and none yesterday afternoon is a
 * different fact from knowing nine appeared today.
 */
final class DetectSignals extends Command
{
    protected $signature = 'brain:detect
        {--tenant= : Evaluate one tenant instead of all}';

    protected $description = 'Evaluate the detection rules and raise signals for what they find';

    public function handle(EntityResolver $resolver, RuleEvaluator $evaluator): int
    {
        $only = $this->option('tenant');

        // Tenants are those that map Person — the entity every shipped rule
        // reads. Asking the vocabulary layer rather than listing tenant ids is
        // what lets this run unchanged for a customer whose people live in a
        // different table.
        $tenantIds = array_keys($resolver->everyTenantWith('Person'));

        $rows = [];
        $failures = 0;

        foreach ($tenantIds as $tenantId) {
            $tenantId = (string) $tenantId;

            if ($only !== null && $tenantId !== $only) {
                continue;
            }

            try {
                // evaluate() reports what it DID; the number of rules in force
                // comes from rulesFor(), so a tenant running zero rules is
                // distinguishable from one whose rules all found nothing.
                $applicable = count($evaluator->rulesFor($tenantId));
                $result = $evaluator->evaluate($tenantId);

                // BOTH RULE FAMILIES, as ImportOrganizationData --generate-signals
                // already runs them. Detection used to evaluate only the rules
                // held as rows, so the operational aggregates — SLA breaches,
                // zone concentration, help-desk call drops — were re-evaluated
                // ONLY when somebody happened to re-import a workbook. A
                // scheduled detector that refreshes half its findings makes the
                // other half quietly stale, and staleness is indistinguishable
                // from "the problem went away" on every screen that reads them.
                //
                // Safe to run every pass: OperationalSignalWriter::raise()
                // refreshes the open signal for a rule rather than stacking a
                // duplicate, exactly as RuleEvaluator does for its own.
                $operational = $this->operationalRules($tenantId);

                $rows[] = [
                    $tenantId,
                    $applicable + $operational['applicable'],
                    ($result['created'] ?? 0) + $operational['created'],
                    ($result['refreshed'] ?? 0) + $operational['refreshed'],
                    ($result['skipped'] ?? 0) + $operational['skipped'],
                ];
            } catch (\Throwable $e) {
                // One tenant's misconfiguration must not stop detection for the
                // others. The failure is reported, not swallowed.
                $failures++;
                $rows[] = [$tenantId, '—', '—', '—', 'FAILED'];
                $this->newLine();
                $this->error("tenant {$tenantId}: ".$e->getMessage());
            }
        }

        if ($rows === []) {
            $this->warn('No tenants map the Person entity, so no rule could run.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'Rules in force', 'Raised', 'Refreshed', 'Not met'], $rows);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The dataset-attached aggregate rules, evaluated for one tenant.
     *
     * A tenant that has imported nothing gets an empty rule list and pays one
     * indexed GROUP BY for the question — the registry's own stated contract.
     *
     * @return array{applicable: int, created: int, refreshed: int, skipped: int}
     */
    private function operationalRules(string $tenantId): array
    {
        $writer = app(OperationalSignalWriter::class);
        $rules = app(SignalRuleRegistry::class)->extraRulesFor($writer, $tenantId);

        $out = ['applicable' => count($rules), 'created' => 0, 'refreshed' => 0, 'skipped' => 0];

        foreach ($rules as $rule) {
            try {
                $outcome = $rule();
            } catch (\Throwable $e) {
                // One malformed rule must not stop the rest — the same contract
                // RuleEvaluator holds for its own family.
                $out['skipped']++;
                $this->warn("tenant {$tenantId}: rule failed — ".$e->getMessage());

                continue;
            }

            match (true) {
                ($outcome['created'] ?? false)   => $out['created']++,
                ($outcome['refreshed'] ?? false) => $out['refreshed']++,
                default                          => $out['skipped']++,
            };
        }

        return $out;
    }
}
