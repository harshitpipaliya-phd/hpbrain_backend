<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/**
 * Scheduled work.
 *
 * REQUIRES A HOST CRON ENTRY. Laravel's scheduler is not a daemon — it runs
 * when something invokes it. Without the line below on the server, this file is
 * inert and the outbox never drains, which is indistinguishable from the bug
 * Module 5 fixed:
 *
 *     * * * * * cd /path/to/hp-enterprise-brain && php artisan schedule:run >> /dev/null 2>&1
 *
 * On Windows, the equivalent is a Task Scheduler entry running the same command
 * every minute.
 */

// --once so the command processes one batch and exits, leaving the pacing to
// the scheduler. Without it a backlog would keep one invocation running for
// minutes and withoutOverlapping would suppress every tick behind it.
//
// withoutOverlapping guards against a slow batch and the next tick colliding;
// it is belt-and-braces rather than the real protection, which is the
// conditional claim in ProcessLoopEvents::claim().
//
// runInBackground so a slow consumer cannot delay other scheduled work.
Schedule::command('brain:process-events --once')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
