<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reasoning\SignalReasoner;
use App\Domain\Universal\EntityResolver;
use App\Repositories\SignalRepository;
use Illuminate\Console\Command;

/**
 * Run reasoning across every 'new' or 'triaged' signal, every tenant.
 *
 * CORRECTED — the first version of this command invented a
 * TenantRepository::allActive() that does not exist in this codebase.
 * Real tenant enumeration, copied exactly from DetectSignals.php (the
 * proven, already-working command this one is modeled on): tenants are
 * those the vocabulary layer maps 'Person' for, not a table this class
 * queries directly. Using the same method DetectSignals uses is what
 * keeps this correct if tenant storage ever changes shape — the same
 * reasoning DetectSignals states for itself.
 *
 * Deliberately does NOT reason over already-recommended signals —
 * re-running the model on the same signal repeatedly would silently
 * multiply real API cost without adding real information.
 */
final class ReasonOverSignals extends Command
{
    protected $signature = 'brain:reason-signals
        {--tenant= : Reason over one tenant instead of all}
        {--limit=20 : Max signals to process per tenant per run, to bound real API cost}';

    protected $description = 'Call the AI provider over pending signals to produce real recommendations';

    public function handle(EntityResolver $resolver, SignalRepository $signals, SignalReasoner $reasoner): int
    {
        $only = $this->option('tenant');
        $limit = (int) $this->option('limit');

        $tenantIds = array_keys($resolver->everyTenantWith('Person'));
        $failures = 0;

        foreach ($tenantIds as $tenantId) {
            $tenantId = (string) $tenantId;

            if ($only !== null && $tenantId !== $only) {
                continue;
            }

            try {
                $pending = array_slice(
                    array_filter(
                        $signals->list($tenantId),
                        fn ($s) => in_array($s['status'] ?? '', ['new', 'triaged'], true)
                    ),
                    0,
                    $limit
                );

                $this->info("Tenant {$tenantId}: " . count($pending) . " signals to reason over (limit {$limit})");

                foreach ($pending as $signal) {
                    $result = $reasoner->reasonOver($tenantId, $signal['id']);

                    $this->line($result !== null
                        ? "  ✓ {$signal['id']} → recommendation {$result}"
                        : "  — {$signal['id']} → UNDETERMINED (model call failed or returned no valid answer)"
                    );
                }
            } catch (\Throwable $e) {
                // One tenant's failure must not stop the others — same
                // discipline as DetectSignals.
                $failures++;
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}