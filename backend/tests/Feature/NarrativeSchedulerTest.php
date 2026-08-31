<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Step 21 — narrative research is scheduled HOURLY (not on the 10-minute
 * discovery cadence) with withoutOverlapping(30), so it never blocks discovery
 * or pump detection.
 */
class NarrativeSchedulerTest extends TestCase
{
    private function event(string $needle): Event
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        $this->fail("{$needle} is not registered with the scheduler");
    }

    #[Test]
    public function narrative_research_runs_hourly(): void
    {
        $this->assertSame('0 * * * *', $this->event('memecoins:research-narratives')->expression);
    }

    #[Test]
    public function narrative_research_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($this->event('memecoins:research-narratives')));

        $expires = new ReflectionProperty(Event::class, 'expiresAt');
        $expires->setAccessible(true);
        $this->assertSame(30, $expires->getValue($this->event('memecoins:research-narratives')));
    }

    #[Test]
    public function narrative_research_is_not_on_the_discovery_cadence(): void
    {
        $this->assertNotSame(
            $this->event('memecoins:discover')->expression,
            $this->event('memecoins:research-narratives')->expression,
        );
    }

    #[Test]
    public function schedule_list_shows_the_narrative_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:research-narratives')
            ->assertExitCode(0);
    }
}
