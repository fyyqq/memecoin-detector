<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class DiscoverySchedulerTest extends TestCase
{
    private function discoveryEvent(): Event
    {
        // Any artisan call bootstraps the console kernel, which loads
        // routes/console.php and registers the schedule.
        $this->artisan('inspire')->run();

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'memecoins:discover')) {
                return $event;
            }
        }

        $this->fail('memecoins:discover is not registered with the scheduler');
    }

    #[Test]
    public function the_discovery_command_is_scheduled_every_ten_minutes(): void
    {
        $this->assertSame('*/10 * * * *', $this->discoveryEvent()->expression);
    }

    #[Test]
    public function the_scheduled_run_records_the_scheduled_trigger(): void
    {
        $this->assertStringContainsString('--trigger=scheduled', (string) $this->discoveryEvent()->command);
    }

    #[Test]
    public function the_schedule_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue(
            $property->getValue($this->discoveryEvent()),
            'The discovery schedule must use withoutOverlapping() so a slow run is never doubled up.',
        );
    }

    #[Test]
    public function schedule_list_shows_the_discovery_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:discover')
            ->assertExitCode(0);
    }
}
