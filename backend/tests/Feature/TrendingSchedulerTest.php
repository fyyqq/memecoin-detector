<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * `memecoins:collect-trending` runs near-real-time (every ~5 minutes) and
 * `memecoins:cleanup-trending` runs once daily. Neither runs on an API request.
 */
class TrendingSchedulerTest extends TestCase
{
    private function event(string $needle): Event
    {
        $this->artisan('inspire')->run();

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
    public function collect_trending_runs_every_five_minutes(): void
    {
        $this->assertSame('*/5 * * * *', $this->event('memecoins:collect-trending')->expression);
    }

    #[Test]
    public function collect_trending_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($this->event('memecoins:collect-trending')));
    }

    #[Test]
    public function cleanup_trending_runs_once_daily(): void
    {
        $this->assertSame('40 0 * * *', $this->event('memecoins:cleanup-trending')->expression);
    }

    #[Test]
    public function schedule_list_shows_both_trending_commands(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:collect-trending')
            ->expectsOutputToContain('memecoins:cleanup-trending')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_refresh_interval_is_configurable(): void
    {
        config()->set('trending.refresh_minutes', 5);
        $this->assertSame(5, (int) config('trending.refresh_minutes'));
    }
}
