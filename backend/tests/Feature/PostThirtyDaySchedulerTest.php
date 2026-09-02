<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * `memecoins:mark-recently-crossed` is scheduled on the discovery cadence,
 * offset AFTER risk screening and BEFORE evidence collection, with
 * withoutOverlapping, reusing the existing scheduler container.
 */
class PostThirtyDaySchedulerTest extends TestCase
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
    public function the_marker_command_is_scheduled(): void
    {
        $this->assertStringContainsString(
            'memecoins:mark-recently-crossed',
            (string) $this->event('memecoins:mark-recently-crossed')->command,
        );
    }

    #[Test]
    public function it_runs_after_risk_screening_and_before_evidence(): void
    {
        $mark = $this->event('memecoins:mark-recently-crossed')->expression;
        $risk = $this->event('memecoins:screen-risk')->expression;
        $evidence = $this->event('memecoins:collect-evidence')->expression;
        $discovery = $this->event('memecoins:discover')->expression;

        $this->assertNotSame($discovery, $mark);
        $this->assertSame('7,17,27,37,47,57 * * * *', $mark);

        $firstMinute = fn (string $expr): int => (int) explode(',', explode(' ', $expr)[0])[0];
        $this->assertGreaterThan($firstMinute($risk), $firstMinute($mark));
        $this->assertLessThan($firstMinute($evidence), $firstMinute($mark));
    }

    #[Test]
    public function it_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($this->event('memecoins:mark-recently-crossed')));
    }

    #[Test]
    public function schedule_list_shows_the_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:mark-recently-crossed')
            ->assertExitCode(0);
    }

    #[Test]
    public function no_new_scheduler_container_is_introduced(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 3).'/docker-compose.yml');

        preg_match('/^services:\n(?<body>(?:[ \t][^\n]*\n|\n)*)/m', $compose, $m);
        preg_match_all('/^  ([a-z0-9_-]+):$/m', $m['body'] ?? '', $names);
        sort($names[1]);

        $this->assertSame(['backend', 'frontend', 'postgres', 'scheduler'], $names[1]);
    }
}
