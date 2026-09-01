<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Step 24 — `memecoins:screen-risk` is scheduled on the discovery cadence,
 * offset AFTER discovery + historical qualification (which run together at the
 * top of the interval) and BEFORE the evidence offset, with withoutOverlapping,
 * reusing the existing scheduler container (no new container).
 */
class RiskSchedulerTest extends TestCase
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
    public function risk_screening_is_scheduled(): void
    {
        $this->assertStringContainsString('memecoins:screen-risk', (string) $this->event('memecoins:screen-risk')->command);
    }

    #[Test]
    public function it_runs_on_the_discovery_cadence_offset_after_discovery_and_before_evidence(): void
    {
        $risk = $this->event('memecoins:screen-risk')->expression;
        $discovery = $this->event('memecoins:discover')->expression;
        $pump = $this->event('memecoins:detect-pumps')->expression;
        $evidence = $this->event('memecoins:collect-evidence')->expression;

        $this->assertSame('*/10 * * * *', $discovery);
        $this->assertNotSame($discovery, $risk, 'risk screening must not fire at the same minute as discovery');
        $this->assertSame('6,16,26,36,46,56 * * * *', $risk);

        // strictly between pump detection (:05) and evidence (:08).
        $firstMinute = fn (string $expr): int => (int) explode(',', explode(' ', $expr)[0])[0];
        $this->assertGreaterThan($firstMinute($pump), $firstMinute($risk));
        $this->assertLessThan($firstMinute($evidence), $firstMinute($risk));
    }

    #[Test]
    public function it_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue(
            $property->getValue($this->event('memecoins:screen-risk')),
            'risk screening must use withoutOverlapping() so a slow run is never doubled up.',
        );
    }

    #[Test]
    public function schedule_list_shows_the_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:screen-risk')
            ->assertExitCode(0);
    }

    #[Test]
    public function no_new_scheduler_container_is_introduced(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 3).'/docker-compose.yml');

        $this->assertStringNotContainsString('risk-scheduler', $compose);
        $this->assertStringNotContainsString('docker/risk', $compose);
        preg_match('/^services:\n(?<body>(?:[ \t][^\n]*\n|\n)*)/m', $compose, $m);
        preg_match_all('/^  ([a-z0-9_-]+):$/m', $m['body'] ?? '', $names);
        sort($names[1]);
        $this->assertSame(['backend', 'frontend', 'postgres', 'scheduler'], $names[1]);
    }
}
