<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Events\EventConsumer;
use App\Domain\Signals\SignalGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Process pending events from the outbox.
 *
 * Usage:
 *   php artisan events:process
 *   php artisan events:process --batch=100
 */
final class ProcessEvents extends Command
{
    protected $signature = 'events:process {--batch=50 : Number of events to process per run}';
    protected $description = 'Process pending events from the outbox';

    public function handle(EventConsumer $consumer): int
    {
        $batch = (int) $this->option('batch');

        if ($batch > 0) {
            $consumer->setBatchSize($batch);
        }

        $this->info('Processing pending events...');

        $result = $consumer->process();

        $this->info("Processed: {$result['processed']}");
        $this->info("Dead-lettered: {$result['deadLettered']}");
        $this->info("Skipped (will retry): {$result['skipped']}");

        return 0;
    }
}
