<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyChainActivity;
use App\Models\DailyTrendingRanking;
use App\Models\MarketSnapshot;
use App\Models\RiskAssessment;
use App\Models\Token;
use App\Models\TrendingSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesRiskAssessments;
use Tests\TestCase;

/**
 * The trending read APIs are PostgreSQL-only. They never call DexScreener /
 * GoPlus / any provider, never open a WebSocket, never recompute, never write.
 *
 * `GET /api/memecoins/trending` = TOP N of the currently-trending, newly-launched
 * memecoins (memecoin + age ≤ 30d + CURRENT market cap in [$5M, $200M] +
 * volume > 0 + liquidity > 0). Default `top_n` (10), max `top_max` (20).
 */
class TrendingApiTest extends TestCase
{
    use CreatesRiskAssessments;
    use RefreshDatabase;

    private CarbonImmutable $now;

    private int $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-02T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();
        $this->bucket = intdiv($this->now->getTimestamp(), 300) * 300;

        config()->set('trending.refresh_minutes', 5);
        config()->set('trending.risk_stale_hours', 6);
        config()->set('trending.top_n', 10);
        config()->set('trending.top_max', 20);
        config()->set('trending.eligibility.max_age_days', 30);
        config()->set('trending.eligibility.min_current_market_cap', 5_000_000);
        config()->set('trending.eligibility.max_current_market_cap', 200_000_000);
        config()->set('trending.volume.top_per_chain', 5);
        config()->set('trending.volume.active_within_hours', 48);
        config()->set('trending.integrity.min_liquidity_usd', 1.0);
        config()->set('trending.integrity.min_transaction_count', 1);
        config()->set('trending.integrity.max_market_cap_usd', 1_000_000_000_000.0);
        config()->set('trending.integrity.max_volume_liquidity_ratio', 75.0);
        config()->set('risk.main_list.min_age_hours', 72);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- fixtures --------------------------------------------------

    private function token(string $chain, string $addr, array $overrides = []): Token
    {
        return Token::query()->create(array_replace([
            'chain_id' => $chain,
            'token_address' => $addr,
            'symbol' => strtoupper(substr($addr, 0, 5)),
            'name' => ucfirst($addr),
            'earliest_pair_created_at' => $this->now->subDays(10),
            'first_observed_at' => $this->now->subDays(10),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 20_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDay(),
        ], $overrides));
    }

    private function snapshot(Token $token, array $overrides = []): MarketSnapshot
    {
        return $token->marketSnapshots()->create(array_replace([
            'observed_at' => $this->now,
            'price_usd' => 0.01,
            'market_cap' => 20_000_000.0,
            'liquidity_usd' => 250_000.0,
            'volume_h24' => 1_000_000.0,
            'price_change_h24' => 10.0,
            'txns_h24' => 900,
        ], $overrides));
    }

    /** An ELIGIBLE trending snapshot by default (memecoin, in-band MC, young pool, activity). */
    private function trendSnap(string $chain, string $addr, string $tf, float $score, ?int $tokenId = null, array $overrides = []): TrendingSnapshot
    {
        return TrendingSnapshot::query()->create(array_replace([
            'token_id' => $tokenId,
            'chain_id' => $chain,
            'token_address' => $addr,
            'pair_address' => 'P-'.$addr,
            'dex_id' => 'raydium',
            'symbol' => strtoupper(substr($addr, 0, 5)),
            'name' => ucfirst($addr).' Inu',
            'is_memecoin_candidate' => 'TRUE',
            'timeframe' => $tf,
            'capture_bucket' => $this->bucket,
            'trend_rank' => 1,
            'tracked_trend_score' => $score,
            'trend_score_components' => ['momentum' => 50, 'volume_activity' => 50, 'transaction_activity' => 50, 'liquidity_quality' => 50, 'persistence' => 20],
            'trend_appearances' => 3,
            'market_cap' => 20_000_000.0,
            'liquidity_usd' => 250_000.0,
            'volume_usd' => 1_000_000.0,
            'price_change_pct' => 12.0,
            'transaction_count' => 900,
            'pair_created_at' => $this->now->subDays(8),
            'trending_meta_slug' => 'degen',
            'trending_meta_name' => 'Degen',
            'source' => 'dexscreener_meta',
            'captured_at' => $this->now,
        ], $overrides));
    }

    // ---- /trending — ordering, TOP N, filters ---------------------

    #[Test]
    public function trending_returns_the_latest_capture_ordered_by_score_renumbered_1_to_n(): void
    {
        $this->trendSnap('solana', 'AAA', '6h', 60.0);
        $this->trendSnap('solana', 'BBB', '6h', 90.0);
        $this->trendSnap('solana', 'CCC', '24h', 70.0);
        // older bucket — ignored.
        $this->trendSnap('solana', 'OLD', '6h', 99.0, null, ['capture_bucket' => $this->bucket - 3000, 'captured_at' => $this->now->subHour()]);

        $res = $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk();

        $res->assertJsonPath('meta.timeframe', '6h');
        $res->assertJsonCount(2, 'data');
        $res->assertJsonPath('data.0.symbol', 'BBB');
        $res->assertJsonPath('data.0.rank', 1);
        $res->assertJsonPath('data.1.symbol', 'AAA');
        $res->assertJsonPath('data.1.rank', 2);
        $res->assertJsonPath('meta.source', 'dexscreener_meta');
    }

    #[Test]
    public function the_default_result_is_top_10_and_the_maximum_is_20(): void
    {
        foreach (range(1, 25) as $i) {
            $this->trendSnap('solana', 'T'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '6h', 100.0 - $i);
        }

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.top_n', 10)
            ->assertJsonPath('meta.count', 10);

        $this->getJson('/api/memecoins/trending?timeframe=6h&limit=20')->assertOk()->assertJsonCount(20, 'data');
        // limit above the max is rejected by validation.
        $this->getJson('/api/memecoins/trending?timeframe=6h&limit=50')->assertStatus(422);
    }

    #[Test]
    public function the_meta_advertises_the_exact_filters(): void
    {
        $this->trendSnap('solana', 'AAA', '6h', 80.0);

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()
            ->assertJsonPath('meta.filters.memecoin_only', true)
            ->assertJsonPath('meta.filters.max_age_days', 30)
            ->assertJsonPath('meta.filters.min_current_market_cap', 5000000)
            ->assertJsonPath('meta.filters.max_current_market_cap', 200000000)
            ->assertJsonPath('meta.filters.volume_required', true)
            ->assertJsonPath('meta.filters.liquidity_required', true);
    }

    #[Test]
    public function the_read_guard_drops_rows_that_no_longer_satisfy_the_filters(): void
    {
        $this->trendSnap('solana', 'GoodMeme', '6h', 90.0);
        $this->trendSnap('solana', 'NotMeme', '6h', 95.0, null, ['is_memecoin_candidate' => 'FALSE']);
        $this->trendSnap('solana', 'TooSmall', '6h', 94.0, null, ['market_cap' => 3_000_000.0]);
        $this->trendSnap('solana', 'TooBig', '6h', 93.0, null, ['market_cap' => 450_000_000.0]);
        $this->trendSnap('solana', 'NoLiq', '6h', 92.0, null, ['liquidity_usd' => 0.0]);
        $this->trendSnap('solana', 'NoVol', '6h', 91.0, null, ['volume_usd' => 0.0]);
        $this->trendSnap('solana', 'AgedOut', '6h', 96.0, null, ['pair_created_at' => $this->now->subDays(45)]);
        $this->trendSnap('solana', 'AgeUnknown', '6h', 97.0, null, ['pair_created_at' => null]);

        $res = $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk();

        $this->assertSame(['GoodMeme'], array_column($res->json('data'), 'token_address'));
    }

    #[Test]
    public function the_chain_filter_works_over_the_eligible_universe_and_maps_other(): void
    {
        $this->trendSnap('solana', 'SolMeme', '6h', 90.0);
        $this->trendSnap('base', 'BaseMeme', '6h', 85.0);
        $this->trendSnap('ton', 'TonMeme', '6h', 80.0);

        $this->getJson('/api/memecoins/trending?timeframe=6h&chain=solana')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.token_address', 'SolMeme');
        $this->getJson('/api/memecoins/trending?timeframe=6h&chain=other')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.chain_id', 'ton');
        $this->getJson('/api/memecoins/trending?timeframe=6h&chain=base')->assertOk()
            ->assertJsonPath('data.0.rank', 1); // renumbered within the chain view
    }

    #[Test]
    public function six_hour_and_twenty_four_hour_are_ranked_separately(): void
    {
        $this->trendSnap('solana', 'A', '6h', 40.0, null, ['trend_rank' => 2]);
        $this->trendSnap('solana', 'B', '6h', 80.0, null, ['trend_rank' => 1]);
        $this->trendSnap('solana', 'A', '24h', 95.0, null, ['trend_rank' => 1]);
        $this->trendSnap('solana', 'B', '24h', 30.0, null, ['trend_rank' => 2]);

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()->assertJsonPath('data.0.symbol', 'B');
        $this->getJson('/api/memecoins/trending?timeframe=24h')->assertOk()->assertJsonPath('data.0.symbol', 'A');
    }

    // ---- risk interaction (unchanged concept) --------------------

    #[Test]
    public function a_trending_token_shows_risk_check_stale_when_its_scan_is_old(): void
    {
        $token = $this->token('solana', 'StaleTok');
        $this->snapshot($token);
        $this->passRisk($token)->update(['screened_at' => $this->now->subHours(12)]);
        $this->trendSnap('solana', 'StaleTok', '6h', 80.0, $token->id);

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()
            ->assertJsonPath('data.0.risk_check_stale', true)
            ->assertJsonPath('data.0.risk_level', 'LOWER');
    }

    #[Test]
    public function a_high_risk_token_stays_in_trending_but_is_not_main_list_eligible_and_is_on_risk_watch(): void
    {
        $token = $this->token('solana', 'RiskyTrend');
        $this->snapshot($token);
        $this->failRisk($token, RiskAssessment::LEVEL_HIGH);
        $this->trendSnap('solana', 'RiskyTrend', '6h', 95.0, $token->id);

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()
            ->assertJsonPath('data.0.symbol', 'RISKY')
            ->assertJsonPath('data.0.risk_level', 'HIGH')
            ->assertJsonPath('data.0.main_list_eligible', false);

        $this->getJson('/api/memecoins')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/memecoins/risk-watch')->assertOk()
            ->assertJsonPath('data.0.symbol', 'RISKY')
            ->assertJsonPath('data.0.trend.trend_rank_6h', 1);
    }

    #[Test]
    public function a_clean_mature_trending_token_reaches_the_main_list(): void
    {
        $token = $this->token('solana', 'CleanTrend', ['earliest_pair_created_at' => $this->now->subDays(9)]);
        $this->snapshot($token);
        $this->passRisk($token);
        $this->trendSnap('solana', 'CleanTrend', '6h', 88.0, $token->id);

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()
            ->assertJsonPath('data.0.main_list_eligible', true);
        $this->getJson('/api/memecoins')->assertOk()
            ->assertJsonPath('data.0.symbol', 'CLEAN')
            ->assertJsonPath('data.0.trend.trend_rank_6h', 1);
    }

    #[Test]
    public function a_pokegym_style_young_token_can_trend_but_never_enters_the_main_list(): void
    {
        // 2h old, MC $23M -> genuinely trending, but age < 72h + HIGH risk.
        $token = $this->token('solana', 'PokeGym', ['earliest_pair_created_at' => $this->now->subHours(2)]);
        $this->snapshot($token, ['market_cap' => 23_000_000.0]);
        $this->failRisk($token, RiskAssessment::LEVEL_HIGH);
        $this->trendSnap('solana', 'PokeGym', '6h', 97.0, $token->id, ['market_cap' => 23_000_000.0, 'pair_created_at' => $this->now->subHours(2)]);

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()->assertJsonPath('data.0.symbol', 'POKEG');
        $this->getJson('/api/memecoins')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/memecoins/risk-watch')->assertOk()->assertJsonPath('data.0.symbol', 'POKEG');
    }

    #[Test]
    public function a_suspicious_identity_mismatch_token_like_doge_on_solana_does_not_trend_if_old_or_out_of_band(): void
    {
        // A "DOGE / Dogecoin on Solana" that is 400d old and $9B MC -> excluded
        // from Trending Now by BOTH the age and the current-MC gate.
        $this->trendSnap('solana', 'FakeDoge', '6h', 99.0, null, [
            'symbol' => 'DOGE', 'name' => 'Dogecoin',
            'market_cap' => 9_000_000_000.0,
            'pair_created_at' => $this->now->subDays(400),
        ]);

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- historical archive unaffected -------------------------

    #[Test]
    public function the_history_endpoint_still_returns_old_tokens_that_are_no_longer_eligible_today(): void
    {
        DailyTrendingRanking::query()->create([
            'date' => $this->now->subDay()->toDateString(),
            'chain_bucket' => 'solana', 'timeframe' => '6h', 'token_id' => null,
            'chain_id' => 'solana', 'token_address' => 'YesterHit', 'symbol' => 'YEST', 'name' => 'Yesterday',
            'best_rank' => 1, 'best_score' => 82.0, 'appearances' => 9,
            'peak_market_cap' => 40_000_000.0, 'peak_volume' => 3_000_000.0,
            'first_seen_at' => $this->now->subDay(), 'last_seen_at' => $this->now->subDay(),
        ]);

        // Not trending, not eligible today — no trending_snapshots at all.
        $this->assertSame(0, TrendingSnapshot::count());

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/memecoins/trending/history?date='.$this->now->subDay()->toDateString().'&timeframe=6h')->assertOk()
            ->assertJsonPath('meta.source', 'daily_trending_rankings')
            ->assertJsonPath('data.0.token_address', 'YesterHit');
    }

    // ---- read-only guarantees --------------------------------

    #[Test]
    public function every_trending_read_api_makes_zero_provider_calls_and_zero_writes(): void
    {
        $token = $this->token('solana', 'ReadOnly');
        $this->snapshot($token);
        $this->passRisk($token);
        $this->trendSnap('solana', 'ReadOnly', '6h', 80.0, $token->id);
        DailyTrendingRanking::query()->create(['date' => $this->now->subDay()->toDateString(), 'chain_bucket' => 'solana', 'timeframe' => '6h', 'token_id' => $token->id, 'chain_id' => 'solana', 'token_address' => 'ReadOnly', 'symbol' => 'READ', 'name' => 'Read', 'best_rank' => 1, 'best_score' => 80.0, 'appearances' => 1, 'first_seen_at' => $this->now->subDay(), 'last_seen_at' => $this->now->subDay()]);
        DailyChainActivity::query()->create(['date' => $this->now->toDateString(), 'chain_bucket' => 'solana', 'total_volume_usd' => 1.0, 'total_liquidity_usd' => 1.0, 'active_token_count' => 1, 'computed_at' => $this->now]);

        $before = DB::table('trending_snapshots')->count() + DB::table('daily_trending_rankings')->count() + DB::table('daily_chain_activity')->count() + DB::table('tokens')->count();

        $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk();
        $this->getJson('/api/memecoins/trending?timeframe=24h')->assertOk();
        $this->getJson('/api/memecoins/trending/history?date='.$this->now->subDay()->toDateString().'&timeframe=6h')->assertOk();
        $this->getJson('/api/memecoins/top-volume')->assertOk();
        $this->getJson('/api/memecoins/chain-activity')->assertOk();

        $after = DB::table('trending_snapshots')->count() + DB::table('daily_trending_rankings')->count() + DB::table('daily_chain_activity')->count() + DB::table('tokens')->count();
        $this->assertSame($before, $after);
    }

    #[Test]
    public function the_trend_score_never_uses_market_cap_two_rows_same_activity_different_mc_score_the_same(): void
    {
        // Both eligible; identical activity, very different MC -> identical stored score.
        $this->trendSnap('solana', 'BigMc', '6h', 77.0, null, ['market_cap' => 190_000_000.0]);
        $this->trendSnap('solana', 'SmallMc', '6h', 77.0, null, ['market_cap' => 6_000_000.0]);

        $rows = $this->getJson('/api/memecoins/trending?timeframe=6h')->assertOk()->json('data');
        $this->assertEqualsWithDelta($rows[0]['tracked_trend_score'], $rows[1]['tracked_trend_score'], 0.001);
    }
}
