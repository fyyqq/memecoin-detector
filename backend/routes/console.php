<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduled memecoin ingestion.
|
| Every N minutes (default 10, MEMECOIN_DISCOVERY_INTERVAL_MINUTES) the scheduler
| runs the discovery/persistence pipeline so `market_snapshots` and each token's
| `observed_peak_market_cap` accumulate over time without a user request.
|
| withoutOverlapping(): the enrichment fan-out can take 20-30s. If a run is still
| going when the next tick fires, skip it rather than start a second copy that
| would double the DexScreener load and race on the same token rows. The lock
| uses the default (file) cache store — no Redis required.
|
| sendOutputTo(): the scheduled command runs as its own subprocess whose output
| the scheduler would otherwise swallow. In the container we redirect it to
| PID 1's stdout so the run summary shows up in `docker compose logs scheduler`;
| off-container (a dev running `schedule:work` directly) it falls back to a file.
*/
$interval = (int) config('dexscreener.discovery.interval_minutes', 10);

$scheduledCommandOutput = @is_writable('/proc/1/fd/1')
    ? '/proc/1/fd/1'
    : storage_path('logs/schedule.log');

Schedule::command('memecoins:discover --trigger=scheduled')
    ->cron("*/{$interval} * * * *")
    ->withoutOverlapping(15)
    ->sendOutputTo($scheduledCommandOutput)
    ->description('DexScreener memecoin discovery + snapshot ingestion');
