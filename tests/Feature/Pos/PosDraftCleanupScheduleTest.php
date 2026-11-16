<?php

namespace Tests\Feature\Pos;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** The daily POS cleanup is registered exactly once, in the Morocco timezone, without overlap. */
class PosDraftCleanupScheduleTest extends TestCase
{
    public function test_cleanup_is_scheduled_once_daily_in_the_morocco_timezone(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'pos:cleanup-drafts'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertStringContainsString('--execute', $event->command);
        $this->assertSame('Africa/Casablanca', (string) $event->timezone);
        $this->assertSame('50 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_heartbeat_jobs_are_registered_for_scheduler_and_worker(): void
    {
        $names = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->description)->all();

        $this->assertContains('system-health:scheduler-heartbeat', $names);
        $this->assertContains('system-health:queue-worker-heartbeat', $names);
    }
}
