<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Step 22 (corrected) — `memecoins:finalize-monthly-champion` runs DAILY at a
 * quiet hour (self-healing: it settles the previous completed month's five
 * buckets whenever it first runs on/after the 1st), never on the 10-minute
 * discovery cadence, with withoutOverlapping. No new scheduler container.
 *
 * `memecoins:research-monthly-champions` is NOT scheduled — it is on-demand
 * because it may perform external research.
 */
class MonthlyChampionSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private function events(): Schedule
    {
        $this->artisan('inspire')->run();

        /** @var Schedule $schedule */
        return $this->app->make(Schedule::class);
    }

    private function event(string $needle): Event
    {
        foreach ($this->events()->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        $this->fail("{$needle} is not registered with the scheduler");
    }

    #[Test]
    public function finalization_runs_once_a_day(): void
    {
        $this->assertSame('20 0 * * *', $this->event('memecoins:finalize-monthly-champion')->expression);
    }

    #[Test]
    public function finalization_is_not_on_the_discovery_cadence(): void
    {
        $this->assertNotSame(
            $this->event('memecoins:discover')->expression,
            $this->event('memecoins:finalize-monthly-champion')->expression,
        );
    }

    #[Test]
    public function finalization_uses_without_overlapping(): void
    {
        $property = new ReflectionProperty(Event::class, 'withoutOverlapping');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($this->event('memecoins:finalize-monthly-champion')));
    }

    #[Test]
    public function schedule_list_shows_the_finalize_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('memecoins:finalize-monthly-champion')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_research_command_is_not_scheduled(): void
    {
        foreach ($this->events()->events() as $event) {
            $this->assertStringNotContainsString(
                'memecoins:research-monthly-champions',
                (string) $event->command,
                'historical research is on-demand only (it may perform external research)',
            );
        }

        // But it is a registered artisan command.
        $this->artisan('memecoins:research-monthly-champions --year=2026 --month=1')->assertExitCode(0);
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
