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

/*
| Pump event detection (Step 16A).
|
| Deterministic detection over the stored observation series — no external
| calls. Runs on the SAME cadence as discovery but OFFSET a few minutes so it
| always executes AFTER ingestion has written the latest snapshots:
|
|   DexScreener discovery → snapshots  (minute 0, 10, 20, …)
|                                ↓
|            pump detection            (minute 5, 15, 25, …)
|
| withoutOverlapping() so a slow run is never doubled up. Reuses the existing
| scheduler container.
*/
$pumpOffset = max(1, min($interval - 1, (int) round($interval / 2)));
$pumpMinutes = [];
for ($m = $pumpOffset; $m < 60; $m += $interval) {
    $pumpMinutes[] = $m;
}
$pumpCron = implode(',', $pumpMinutes).' * * * *';

Schedule::command('memecoins:detect-pumps')
    ->cron($pumpCron)
    ->withoutOverlapping(15)
    ->sendOutputTo($scheduledCommandOutput)
    ->description('Deterministic pump event detection over stored snapshots');

/*
| Evidence collection (Step 16B).
|
| Gathers timestamped FACTS around freshly detected pump events — market
| behaviour, stored token metadata, preceding related-token moves, and (the only
| external call) GDELT news. It does NOT explain the pump (that is Step 16C).
|
| Offset a few minutes AFTER pump detection so every new event is investigated
| the same hour it is detected:
|
|   discovery → snapshots   (minute 0, 10, 20, …)
|   pump detection          (minute 5, 15, 25, …)
|   evidence collection     (minute 8, 18, 28, …)
|
| withoutOverlapping() + a per-event cooldown keep repeated runs idempotent.
| Reuses the existing scheduler container.
*/
$evidenceOffset = min($interval - 1, $pumpOffset + max(1, (int) round($interval / 4)));
$evidenceMinutes = [];
for ($m = $evidenceOffset; $m < 60; $m += $interval) {
    $evidenceMinutes[] = $m;
}
$evidenceCron = implode(',', $evidenceMinutes).' * * * *';

Schedule::command('memecoins:collect-evidence')
    ->cron($evidenceCron)
    ->withoutOverlapping(15)
    ->sendOutputTo($scheduledCommandOutput)
    ->description('Evidence collection around detected pump events');

/*
| AI pump explanation (Step 16C).
|
| Interprets the Evidence collected for each recent pump event into an
| evidence-grounded "why did this coin pump?" explanation. The LLM only ever
| sees one event + its ranked evidence — never the wider database — and cites
| evidence ids for every claim.
|
| Offset a few minutes AFTER evidence collection so it interprets the freshest
| evidence set:
|
|   discovery → snapshots   (minute 0, 10, 20, …)
|   pump detection          (minute 5, 15, 25, …)
|   evidence collection     (minute 8, 18, 28, …)
|   AI explanation          (minute 9, 19, 29, …)
|
| withoutOverlapping() + a per-event cooldown keep AI cost bounded. Reuses the
| existing scheduler container. The read API never triggers generation.
*/
$explanationOffset = min($interval - 1, $evidenceOffset + max(1, (int) round($interval / 4)));
$explanationMinutes = [];
for ($m = $explanationOffset; $m < 60; $m += $interval) {
    $explanationMinutes[] = $m;
}
$explanationCron = implode(',', $explanationMinutes).' * * * *';

Schedule::command('memecoins:explain-pumps')
    ->cron($explanationCron)
    ->withoutOverlapping(15)
    ->sendOutputTo($scheduledCommandOutput)
    ->description('AI evidence-backed explanation of recent pump events');
