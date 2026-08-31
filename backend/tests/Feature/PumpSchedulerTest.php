<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class PumpSchedulerTest extends TestCase
{
    private function event(string $needle): Event
    {
        // Any artisan call bootstraps the console kernel + routes/console.php.
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
    public function pump_detection_is_scheduled(): void
    {
        $this->assertStringContainsString('memecoins:detect-pumps', (string) $this->event('memecoins:detect-pumps')->command);
    }

    #[Test]
    public function pump_detection_runs_every_ten_minutes_offset_from_discovery(): void
    {
        $pump = $this->event('memecoins:detect-pumps')->expression;
        $discovery = $this->event('memecoins:discover')->expression;

        $this->assertNotSame($discovery, $pump, 'pump detection must not fire at the same minute as discovery');

        // Same cadence (six firings an hour), offset by ~5 minutes.
        $this->assertSame('5,15,25,35,45,55 * * * *', $pump);
        $this->assertSame('*/10 * * * *', $discovery);
    }

    #[Test]
    public function pump_detection_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue(
            $property->getValue($this->event('memecoins:detect-pumps')),
            'pump detection must use withoutOverlapping() so a slow run is never doubled up.',
        );
    }

    #[Test]
    public function schedule_list_shows_pump_detection(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:detect-pumps')
            ->assertExitCode(0);
    }
}
