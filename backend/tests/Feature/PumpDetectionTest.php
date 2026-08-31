<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PumpEvent;
use App\Models\Token;
use App\Services\Pump\PumpDetectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 16A — deterministic pump event detection. No external calls.
 */
class PumpDetectionTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-28T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('pump.thresholds.minimum_market_cap_change_pct', 50);
        config()->set('pump.thresholds.minimum_price_change_pct', 40);
        config()->set('pump.thresholds.minimum_volume_change_ratio', 2.0);
        config()->set('pump.thresholds.minimum_transaction_change_ratio', 2.0);
        config()->set('pump.thresholds.minimum_confirmation_signals', 2);
        config()->set('pump.thresholds.strong_move_multiplier', 1.5);
        config()->set('pump.windows.primary_minutes', 60);
        config()->set('pump.windows.acceleration_minutes', 25);
        config()->set('pump.windows.tolerance_minutes', 20);
        config()->set('pump.event_merge_window_minutes', 60);
        config()->set('pump.event_stale_after_minutes', 90);
        config()->set('pump.query.recent_token_minutes', 30);
        config()->set('pump.query.recent_snapshots_per_token', 24);
        config()->set('pump.query.minimum_snapshots', 3);
        config()->set('pump.score.weight_market_cap', 35);
        config()->set('pump.score.weight_price', 30);
        config()->set('pump.score.weight_volume', 20);
        config()->set('pump.score.weight_transactions', 15);
        config()->set('pump.score.acceleration_bonus_max', 15);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @param  array<string,mixed>  $tokenOverrides
     */
    private function token(array $tokenOverrides = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(20),
            'symbol' => 'PUMP',
            'name' => 'Pump Token',
            'earliest_pair_created_at' => $this->now->subDays(5),
            'first_observed_at' => $this->now->subHours(6),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 3_000_000.0,
            'observed_peak_market_cap_at' => $this->now,
        ], $tokenOverrides));

        return $token;
    }

    /**
     * @param  list<array{min:int,mc:?float,price:?float,vol:?float,txns:?int}>  $series
     *                                                                                    `min` = minutes BEFORE $this->now (descending, oldest first).
     */
    private function series(Token $token, array $series): void
    {
        foreach ($series as $s) {
            $token->marketSnapshots()->create([
                'observed_at' => $this->now->subMinutes($s['min']),
                'price_usd' => $s['price'] ?? null,
                'market_cap' => $s['mc'] ?? null,
                'fdv' => $s['mc'] ?? null,
                'liquidity_usd' => 100_000.0,
                'volume_h24' => $s['vol'] ?? null,
                'price_change_h24' => null,
                'txns_h24' => $s['txns'] ?? null,
                'buys_h24' => isset($s['txns']) ? (int) ($s['txns'] * 0.6) : null,
                'sells_h24' => isset($s['txns']) ? (int) ($s['txns'] * 0.4) : null,
                'primary_pair_address' => 'pair',
                'primary_dex_id' => 'raydium',
                'earliest_pair_created_at' => $this->now->subDays(5),
            ]);
        }
        $token->update(['last_observed_at' => $this->now->subMinutes(min(array_column($series, 'min')))]);
    }

    private function detect(?CarbonImmutable $now = null): void
    {
        app(PumpDetectionService::class)->detect($now);
    }

    // ---- tests --------------------------------------------------------

    #[Test]
    public function a_clear_confirmed_pump_creates_one_high_confidence_event(): void
    {
        $token = $this->token();
        // flat ~1M for an hour, then +90% MC/price with 3x volume and 2.6x txns
        $this->series($token, [
            ['min' => 70, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 40, 'mc' => 1_400_000.0, 'price' => 0.014, 'vol' => 180_000.0, 'txns' => 700],
            ['min' => 20, 'mc' => 1_700_000.0, 'price' => 0.017, 'vol' => 250_000.0, 'txns' => 900],
            ['min' => 0, 'mc' => 1_900_000.0, 'price' => 0.019, 'vol' => 320_000.0, 'txns' => 1_050],
        ]);

        $this->detect();

        $this->assertDatabaseCount('pump_events', 1);
        $event = PumpEvent::query()->firstOrFail();
        $this->assertSame($token->id, $event->token_id);
        $this->assertSame(PumpEvent::CONFIDENCE_HIGH, $event->confidence);
        $this->assertSame(PumpEvent::STATUS_ACTIVE, $event->status); // peak is the latest observation
        $this->assertNull($event->ended_at);
        $this->assertGreaterThan(70, $event->detection_score);
        $this->assertEqualsWithDelta(90.0, (float) $event->market_cap_change_pct, 1.0);
        $this->assertEqualsWithDelta(3.2, (float) $event->volume_h24_change_ratio, 0.05);
        $this->assertSame($this->now->subMinutes(60)->toIso8601String(), $event->started_at->toIso8601String());
        $this->assertSame($this->now->toIso8601String(), $event->peak_at->toIso8601String());
    }

    #[Test]
    public function a_lone_price_move_with_no_confirmation_is_not_a_pump(): void
    {
        $token = $this->token();
        // price/MC +50% but volume + txns FLAT → only 2 correlated move signals?
        // Actually MC and price both move → 2 signals. Make ONLY price move.
        $this->series($token, [
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => 1_000_000.0, 'price' => 0.012, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 0, 'mc' => 1_000_000.0, 'price' => 0.016, 'vol' => 100_000.0, 'txns' => 400],
        ]);

        $this->detect();

        // price +60% but MC flat, volume flat, txns flat → 1 signal only → no event.
        $this->assertDatabaseCount('pump_events', 0);
    }

    #[Test]
    public function a_token_without_enough_snapshots_is_skipped(): void
    {
        $token = $this->token();
        $this->series($token, [
            ['min' => 30, 'mc' => 1_000_000.0, 'price' => 0.01, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 0, 'mc' => 5_000_000.0, 'price' => 0.05, 'vol' => 900_000.0, 'txns' => 3_000],
        ]);

        $this->detect();

        $this->assertDatabaseCount('pump_events', 0);
    }

    #[Test]
    public function a_market_cap_move_plus_one_activity_confirmation_is_a_pump(): void
    {
        $token = $this->token();
        $this->series($token, [
            ['min' => 65, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 0, 'mc' => 1_650_000.0, 'price' => 0.010, 'vol' => 230_000.0, 'txns' => 500],
        ]);

        $this->detect();

        $this->assertDatabaseCount('pump_events', 1);
        $event = PumpEvent::query()->firstOrFail();
        // MC +65% (pass) + volume 2.3x (pass) = 2 signals; price flat.
        $this->assertEqualsWithDelta(65.0, (float) $event->market_cap_change_pct, 1.0);
        $this->assertSame(PumpEvent::CONFIDENCE_MEDIUM, $event->confidence);
    }

    #[Test]
    public function a_continuous_pump_across_two_runs_stays_one_merged_event(): void
    {
        $token = $this->token();
        // Run 1: pump in progress, peak at "now" (active).
        $this->series($token, [
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => 1_400_000.0, 'price' => 0.014, 'vol' => 220_000.0, 'txns' => 850],
            ['min' => 0, 'mc' => 1_700_000.0, 'price' => 0.017, 'vol' => 260_000.0, 'txns' => 900],
        ]);
        $this->detect();
        $this->assertDatabaseCount('pump_events', 1);
        $firstId = PumpEvent::query()->value('id');

        // 20 min later: pump continued higher.
        $later = $this->now->addMinutes(20);
        CarbonImmutable::setTestNow($later);
        $token->marketSnapshots()->create([
            'observed_at' => $later, 'price_usd' => 0.021, 'market_cap' => 2_100_000.0, 'fdv' => 2_100_000.0,
            'liquidity_usd' => 100_000.0, 'volume_h24' => 300_000.0, 'txns_h24' => 1_100,
            'buys_h24' => 660, 'sells_h24' => 440, 'primary_pair_address' => 'pair', 'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays(5),
        ]);
        $token->update(['last_observed_at' => $later]);

        $this->detect($later);

        $this->assertDatabaseCount('pump_events', 1);
        $event = PumpEvent::query()->firstOrFail();
        $this->assertSame($firstId, $event->id, 'the existing event must be merged, not replaced');
        $this->assertEqualsWithDelta(2_100_000.0, (float) $event->peak_market_cap, 1.0);
        $this->assertSame($later->toIso8601String(), $event->peak_at->toIso8601String());
        $this->assertSame($this->now->subMinutes(60)->toIso8601String(), $event->started_at->toIso8601String());
    }

    #[Test]
    public function two_separate_pumps_are_two_events_and_the_first_is_not_overwritten(): void
    {
        $token = $this->token(['first_observed_at' => $this->now->subHours(12)]);
        // Pump 1: ~10 hours ago, peaked then fully faded.
        $token->marketSnapshots()->createMany([
            $this->snap($token, 600, 1_000_000, 0.01, 100_000, 400),
            $this->snap($token, 560, 1_000_000, 0.01, 100_000, 400),
            $this->snap($token, 540, 1_900_000, 0.019, 320_000, 1_000),
            $this->snap($token, 520, 1_100_000, 0.011, 140_000, 500),
            $this->snap($token, 500, 1_000_000, 0.01, 100_000, 400),
        ]);
        // Run detection "back then" so event 1 is recorded.
        $backThen = $this->now->subMinutes(500);
        CarbonImmutable::setTestNow($backThen);
        $token->update(['last_observed_at' => $backThen]);
        $this->detect($backThen);
        $this->assertDatabaseCount('pump_events', 1);
        $event1PeakAt = PumpEvent::query()->value('peak_at');

        // Pump 2: now.
        CarbonImmutable::setTestNow($this->now);
        $token->marketSnapshots()->createMany([
            $this->snap($token, 70, 1_000_000, 0.01, 100_000, 400),
            $this->snap($token, 60, 1_000_000, 0.01, 100_000, 400),
            $this->snap($token, 0, 1_800_000, 0.018, 300_000, 950),
        ]);
        $token->update(['last_observed_at' => $this->now]);
        $this->detect();

        $this->assertDatabaseCount('pump_events', 2);
        $first = PumpEvent::query()->orderBy('started_at')->first();
        $this->assertEquals($event1PeakAt, $first?->peak_at, 'event 1 must be untouched');
    }

    #[Test]
    public function the_event_starts_at_the_trough_and_peaks_at_the_highest_market_cap(): void
    {
        $token = $this->token();
        $this->series($token, [
            ['min' => 70, 'mc' => 1_200_000.0, 'price' => 0.012, 'vol' => 120_000.0, 'txns' => 450],
            ['min' => 60, 'mc' => 900_000.0, 'price' => 0.009, 'vol' => 90_000.0, 'txns' => 350],   // trough
            ['min' => 40, 'mc' => 1_600_000.0, 'price' => 0.016, 'vol' => 240_000.0, 'txns' => 800],
            ['min' => 20, 'mc' => 1_800_000.0, 'price' => 0.018, 'vol' => 300_000.0, 'txns' => 950], // peak
            ['min' => 0, 'mc' => 1_500_000.0, 'price' => 0.015, 'vol' => 200_000.0, 'txns' => 700],  // declined
        ]);

        $this->detect();

        $event = PumpEvent::query()->firstOrFail();
        $this->assertSame($this->now->subMinutes(60)->toIso8601String(), $event->started_at->toIso8601String());
        $this->assertSame($this->now->subMinutes(20)->toIso8601String(), $event->peak_at->toIso8601String());
        $this->assertEqualsWithDelta(900_000.0, (float) $event->start_market_cap, 1.0);
        $this->assertEqualsWithDelta(1_800_000.0, (float) $event->peak_market_cap, 1.0);
    }

    #[Test]
    public function a_pump_that_has_already_faded_is_completed_with_an_end_timestamp(): void
    {
        $token = $this->token();
        $this->series($token, [
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => 1_900_000.0, 'price' => 0.019, 'vol' => 320_000.0, 'txns' => 1_000], // peak
            ['min' => 0, 'mc' => 1_200_000.0, 'price' => 0.012, 'vol' => 150_000.0, 'txns' => 550],    // faded
        ]);

        $this->detect();

        $event = PumpEvent::query()->firstOrFail();
        $this->assertSame(PumpEvent::STATUS_COMPLETED, $event->status);
        $this->assertSame($this->now->toIso8601String(), $event->ended_at?->toIso8601String());
        $this->assertSame($this->now->subMinutes(30)->toIso8601String(), $event->peak_at->toIso8601String());
    }

    #[Test]
    public function the_detection_score_is_deterministic_and_grows_with_the_move(): void
    {
        $small = $this->token();
        $this->series($small, [
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => 1_250_000.0, 'price' => 0.0125, 'vol' => 150_000.0, 'txns' => 600],
            ['min' => 0, 'mc' => 1_550_000.0, 'price' => 0.0155, 'vol' => 210_000.0, 'txns' => 850],
        ]);
        $big = $this->token();
        $this->series($big, [
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => 1_800_000.0, 'price' => 0.018, 'vol' => 280_000.0, 'txns' => 1_000],
            ['min' => 0, 'mc' => 3_000_000.0, 'price' => 0.030, 'vol' => 500_000.0, 'txns' => 1_800],
        ]);

        $this->detect();
        $smallScore = PumpEvent::query()->where('token_id', $small->id)->value('detection_score');
        $bigScore = PumpEvent::query()->where('token_id', $big->id)->value('detection_score');

        $this->assertGreaterThan($smallScore, $bigScore);

        // Re-running detection does not change the score.
        $this->detect();
        $this->assertSame($bigScore, PumpEvent::query()->where('token_id', $big->id)->value('detection_score'));
    }

    #[Test]
    public function confidence_low_when_only_market_cap_and_price_move_without_activity_confirmation(): void
    {
        $token = $this->token();
        $this->series($token, [
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => 1_400_000.0, 'price' => 0.014, 'vol' => 102_000.0, 'txns' => 405],
            ['min' => 0, 'mc' => 1_900_000.0, 'price' => 0.019, 'vol' => 105_000.0, 'txns' => 410],
        ]);

        $this->detect();

        $event = PumpEvent::query()->firstOrFail();
        // MC +90% and price +90% (2 move signals) but volume/txns essentially flat.
        $this->assertSame(PumpEvent::CONFIDENCE_LOW, $event->confidence);
    }

    #[Test]
    public function the_volume_ratio_is_stored_as_a_ratio_and_nulls_are_tolerated(): void
    {
        $token = $this->token();
        $this->series($token, [
            // no volume / txns data at all
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => null, 'txns' => null],
            ['min' => 30, 'mc' => 1_400_000.0, 'price' => 0.014, 'vol' => null, 'txns' => null],
            ['min' => 0, 'mc' => 1_900_000.0, 'price' => 0.019, 'vol' => null, 'txns' => null],
        ]);

        $this->detect();

        // MC + price move = 2 signals → still an event; ratios null, not zero.
        $event = PumpEvent::query()->firstOrFail();
        $this->assertNull($event->volume_h24_change_ratio);
        $this->assertNull($event->txns_h24_change_ratio);
        $this->assertSame(PumpEvent::CONFIDENCE_LOW, $event->confidence);
    }

    #[Test]
    public function a_stale_active_event_is_swept_to_completed(): void
    {
        $token = $this->token(['last_observed_at' => $this->now->subMinutes(200)]);
        PumpEvent::query()->create([
            'token_id' => $token->id,
            'started_at' => $this->now->subMinutes(260),
            'peak_at' => $this->now->subMinutes(200),
            'ended_at' => null,
            'start_market_cap' => 1_000_000.0,
            'peak_market_cap' => 2_000_000.0,
            'start_price_usd' => 0.01,
            'peak_price_usd' => 0.02,
            'market_cap_change_pct' => 100.0,
            'price_change_pct' => 100.0,
            'volume_h24_change_ratio' => 3.0,
            'txns_h24_change_ratio' => 2.5,
            'duration_minutes' => 60,
            'detection_score' => 80,
            'confidence' => 'high',
            'status' => PumpEvent::STATUS_ACTIVE,
        ]);

        $this->detect();

        $event = PumpEvent::query()->firstOrFail();
        $this->assertSame(PumpEvent::STATUS_COMPLETED, $event->status);
        $this->assertNotNull($event->ended_at);
    }

    #[Test]
    public function detection_never_calls_dexscreener_or_any_provider(): void
    {
        Http::fake();
        $token = $this->token();
        $this->series($token, [
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => 1_500_000.0, 'price' => 0.015, 'vol' => 250_000.0, 'txns' => 900],
            ['min' => 0, 'mc' => 1_900_000.0, 'price' => 0.019, 'vol' => 320_000.0, 'txns' => 1_100],
        ]);

        $this->detect();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_command_runs_and_prints_a_summary(): void
    {
        $token = $this->token();
        $this->series($token, [
            ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => 1_500_000.0, 'price' => 0.015, 'vol' => 250_000.0, 'txns' => 900],
            ['min' => 0, 'mc' => 1_900_000.0, 'price' => 0.019, 'vol' => 320_000.0, 'txns' => 1_100],
        ]);

        $this->artisan('memecoins:detect-pumps')
            ->expectsOutputToContain('Pump detection completed.')
            ->expectsOutputToContain('Tokens analyzed:')
            ->expectsOutputToContain('Pump events created:')
            ->assertExitCode(0);

        $this->assertDatabaseCount('pump_events', 1);
    }

    #[Test]
    public function detection_uses_a_bounded_number_of_queries(): void
    {
        foreach (range(1, 6) as $i) {
            $token = $this->token(['token_address' => "BoundedAddr{$i}"]);
            $this->series($token, [
                ['min' => 60, 'mc' => 1_000_000.0, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
                ['min' => 30, 'mc' => 1_400_000.0, 'price' => 0.014, 'vol' => 220_000.0, 'txns' => 850],
                ['min' => 0, 'mc' => 1_700_000.0, 'price' => 0.017, 'vol' => 260_000.0, 'txns' => 950],
            ]);
        }

        DB::enableQueryLog();
        $this->detect();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // token-id list + one windowed snapshot query + token load + per-event
        // create/merge + sweep counts. Bounded, NOT one snapshot query per token.
        $this->assertLessThan(30, $queryCount, "expected a bounded query count, got {$queryCount}");
    }

    #[Test]
    public function a_snapshot_with_null_market_cap_falls_back_to_price(): void
    {
        $token = $this->token();
        $this->series($token, [
            ['min' => 60, 'mc' => null, 'price' => 0.010, 'vol' => 100_000.0, 'txns' => 400],
            ['min' => 30, 'mc' => null, 'price' => 0.015, 'vol' => 240_000.0, 'txns' => 850],
            ['min' => 0, 'mc' => null, 'price' => 0.019, 'vol' => 320_000.0, 'txns' => 1_050],
        ]);

        $this->detect();

        // price +90% + volume 3.2x + txns 2.6x → detected even with null MC.
        $event = PumpEvent::query()->firstOrFail();
        $this->assertNull($event->start_market_cap);
        $this->assertNull($event->peak_market_cap);
        $this->assertNull($event->market_cap_change_pct);
        $this->assertEqualsWithDelta(90.0, (float) $event->price_change_pct, 1.0);
    }

    /**
     * @return array<string,mixed>
     */
    private function snap(Token $token, int $minutesAgo, ?float $mc, ?float $price, ?float $vol, ?int $txns): array
    {
        return [
            'observed_at' => $this->now->subMinutes($minutesAgo),
            'price_usd' => $price,
            'market_cap' => $mc,
            'fdv' => $mc,
            'liquidity_usd' => 100_000.0,
            'volume_h24' => $vol,
            'txns_h24' => $txns,
            'buys_h24' => $txns !== null ? (int) ($txns * 0.6) : null,
            'sells_h24' => $txns !== null ? (int) ($txns * 0.4) : null,
            'primary_pair_address' => 'pair',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays(5),
        ];
    }
}
