<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * What the repository schedules — and what it deliberately does not.
 *
 * This proves only the DEFINITION in routes/console.php. It cannot prove a host runs
 * `php artisan schedule:run` every minute; that is an operating-system entry outside
 * the repository (see docs/OPERATIONS-RUNBOOK.md for how to verify it).
 *
 * The negative assertions are as important as the positive ones: brain:reason-signals
 * spends money on a model provider on every run, and the ESO-efficacy and dedupe
 * commands are not idempotent or not safe unattended. None may ever be scheduled by
 * accident.
 */
final class SchedulerDefinitionTest extends TestCase
{
    /** @return list<string> the artisan command strings of every scheduled event */
    private function scheduled(): array
    {
        Artisan::call('list'); // boots the console kernel, which loads routes/console.php

        return collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command))
            ->values()
            ->all();
    }

    /** @return array<string, object> event by artisan command */
    private function events(): array
    {
        Artisan::call('list');
        $out = [];

        foreach (app(Schedule::class)->events() as $event) {
            $out[(string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command)] = $event;
        }

        return $out;
    }

    /** @test */
    public function the_unattended_loop_steps_are_scheduled(): void
    {
        $scheduled = $this->scheduled();

        foreach ([
            'brain:process-events --once',
            'brain:snapshot',
            'brain:detect',
            'brain:open-cases',
            'brain:propose-hypotheses',
            'intelligence:warm',
            'operations:warm',
        ] as $command) {
            $this->assertContains($command, $scheduled, "{$command} should be scheduled");
        }
    }

    /** @test */
    public function paid_or_non_idempotent_commands_are_never_scheduled(): void
    {
        $joined = implode("\n", $this->scheduled());

        foreach ([
            'brain:reason-signals',      // buys a provider call per signal on EVERY run
            'brain:compute-eso-efficacy', // appends a new efficacy row per run
            'brain:dedupe-signals',      // destructive when --apply; a deliberate operator act
            'events:process',            // legacy consumer that completes loop events unhandled
            'school:seed-v1-academy',
        ] as $command) {
            $this->assertStringNotContainsString($command, $joined, "{$command} must stay a manual command");
        }
    }

    /** @test */
    public function every_scheduled_step_is_single_flight_and_non_blocking(): void
    {
        foreach ($this->events() as $command => $event) {
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap itself");
            $this->assertTrue($event->runInBackground, "{$command} must not delay the scheduler");
        }
    }

    /** @test */
    public function the_case_and_hypothesis_steps_run_after_detection_and_in_order(): void
    {
        $events = $this->events();

        $minute = fn (string $command): int => (int) explode(' ', $events[$command]->expression)[0];

        // detect (:10) -> open cases -> propose hypotheses -> warm (:25): each step reads
        // what the previous one wrote.
        $this->assertLessThan($minute('brain:open-cases'), $minute('brain:detect'));
        $this->assertLessThan($minute('brain:propose-hypotheses'), $minute('brain:open-cases'));
        $this->assertLessThan($minute('intelligence:warm'), $minute('brain:propose-hypotheses'));
    }
}
