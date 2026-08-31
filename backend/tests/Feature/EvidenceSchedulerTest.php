<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class EvidenceSchedulerTest extends TestCase
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
    public function evidence_collection_is_scheduled(): void
    {
        $this->assertStringContainsString(
            'memecoins:collect-evidence',
            (string) $this->event('memecoins:collect-evidence')->command,
        );
    }

    #[Test]
    public function evidence_collection_runs_after_pump_detection_each_interval(): void
    {
        $evidence = $this->event('memecoins:collect-evidence')->expression;
        $pump = $this->event('memecoins:detect-pumps')->expression;
        $discovery = $this->event('memecoins:discover')->expression;

        $this->assertSame('*/10 * * * *', $discovery);
        $this->assertSame('5,15,25,35,45,55 * * * *', $pump);
        $this->assertSame('8,18,28,38,48,58 * * * *', $evidence);
    }

    #[Test]
    public function evidence_collection_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($this->event('memecoins:collect-evidence')));
    }

    #[Test]
    public function schedule_list_shows_evidence_collection(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:collect-evidence')
            ->assertExitCode(0);
    }
}
