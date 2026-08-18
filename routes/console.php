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

// Daily metric snapshot. Idempotent within a day, so a retried run overwrites
// rather than double-counting — see SnapshotWriter for why that cannot be left
// to the unique index when dimension_key is nullable.
//
// Early morning UTC: it reads yesterday's settled state rather than racing the
// working day it is meant to describe.
Schedule::command('brain:snapshot')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Detection. Until this existed the rules had one caller — an HTTP endpoint —
// so a signal was raised only when somebody happened to open a screen. A Brain
// that notices problems only while being watched has no history, and every
// trend derived from it is flat for want of data rather than for want of change.
//
// Hourly, not daily: detection is a handful of counting queries, and WHEN
// problems arrive is itself a finding. Ten minutes past the hour keeps it clear
// of the top-of-hour crowd.
Schedule::command('brain:detect')
    ->hourlyAt(10)
    ->withoutOverlapping()
    ->runInBackground();

// Intelligence warming. The engine caches against a data fingerprint, so this
// is a no-op for every organization whose records have not changed — but when
// they have, the recomputation is a multi-minute scan and somebody has to pay
// for it. Before this, that somebody was whichever reader opened a screen first
// after an import.
//
// Twenty-five past the hour: after brain:detect at :10, whose signals are part
// of what the fingerprint covers, so the warm computes over settled input
// rather than racing detection and being invalidated by it minutes later.
//
// withoutOverlapping because a warm of a large tenant can outlast the hour, and
// runInBackground so it never delays the scheduler's other work.
Schedule::command('intelligence:warm')
    ->hourlyAt(25)
    ->withoutOverlapping()
    ->runInBackground();
