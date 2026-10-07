<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Universal\CrossProductIdentityResolver;
use Illuminate\Console\Command;

/**
 * Phase 7.4 — propose K-12/G2G identity links by email match. Writes drafts
 * only; a human reviews and confirms (or rejects) each one before
 * CrossProductIdentityResolver::resolve() will ever return it.
 */
class IdentityProposeMatchesCommand extends Command
{
    protected $signature = 'identity:propose-matches';

    protected $description = 'Propose cross-product (K-12/G2G) identity links by email match — drafts only, never auto-confirmed';

    public function handle(CrossProductIdentityResolver $resolver): int
    {
        $this->info('Proposing cross-product identity links (K-12 <-> G2G, matched by email)...');

        $result = $resolver->proposeMatches();

        $this->table(['Result', 'Count'], [
            ['Proposed (new or refreshed draft)', $result['proposed']],
            ['Skipped (already confirmed)', $result['skipped_confirmed']],
            ['Skipped (already rejected)', $result['skipped_rejected']],
        ]);

        $this->line('Every row above is a DRAFT. Nothing is usable by resolve() until a human confirms it.');

        return self::SUCCESS;
    }
}
