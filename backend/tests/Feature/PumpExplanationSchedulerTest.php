<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

class PumpExplanationSchedulerTest extends TestCase
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
    public function explanation_is_scheduled_after_the_other_pipeline_stages(): void
    {
        $discovery = $this->event('memecoins:discover')->expression;
        $pump = $this->event('memecoins:detect-pumps')->expression;
        $evidence = $this->event('memecoins:collect-evidence')->expression;
        $explanation = $this->event('memecoins:explain-pumps')->expression;

        $this->assertSame('*/10 * * * *', $discovery);
        $this->assertSame('5,15,25,35,45,55 * * * *', $pump);
        $this->assertSame('8,18,28,38,48,58 * * * *', $evidence);
        $this->assertSame('9,19,29,39,49,59 * * * *', $explanation);
    }

    #[Test]
    public function explanation_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($this->event('memecoins:explain-pumps')));
    }

    #[Test]
    public function schedule_list_shows_the_explanation_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:explain-pumps')
            ->assertExitCode(0);
    }
}
