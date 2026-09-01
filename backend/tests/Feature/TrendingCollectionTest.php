<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyChainActivity;
use App\Models\DailyTrendingRanking;
use App\Models\Token;
use App\Models\TrendingSnapshot;
use App\Services\Trending\TrendingCollectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `memecoins:collect-trending` — "Trending Now" is the TOP N of the CURRENTLY
 * trending, NEWLY-LAUNCHED memecoins that pass our approved filters:
 *
 *   is_memecoin_candidate == TRUE
 *   AND age <= 30 days (real earliest_pair_created_at; unknown -> excluded)
 *   AND CURRENT market cap in [$5M, $200M]
 *   AND volume > 0 AND liquidity > 0
 *
 * Only ELIGIBLE tokens are scored + stored. Built ONLY on the documented
 * `GET /metas/trending/v1` -> `GET /metas/meta/v1/{slug}` APIs — no
 * io.dexscreener.com WebSocket, no scraping. DexScreener is fully HTTP-faked.
 */
class TrendingCollectionTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    /** @var list<string> */
    private array $metaDetailCalls = [];

    /** @var array{metas:list<array<string,mixed>>,metaDetails:array<string,mixed>,tokenPairs:array<string,mixed>} */
    private array $fx = ['metas' => [], 'metaDetails' => [], 'tokenPairs' => []];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-02T12:02:30Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('dexscreener.base_url', 'https://api.dexscreener.com');
        config()->set('dexscreener.cache.discovery_ttl', 0);
        config()->set('dexscreener.cache.enrichment_ttl', 0);
        config()->set('dexscreener.http.retries', 0);
        config()->set('dexscreener.filters.max_age_days', 30);

        config()->set('trending.refresh_minutes', 5);
        config()->set('trending.top_n', 10);
        config()->set('trending.top_max', 20);
        config()->set('trending.collect.max_metas', 18);
        config()->set('trending.collect.max_new_token_enrich', 40);
        config()->set('trending.collect.max_candidates_per_timeframe', 60);
        config()->set('trending.collect.enrich_prefilter_max_age_days', 35);
        config()->set('trending.persistence.window_captures', 12);
        config()->set('trending.eligibility.max_age_days', 30);
        config()->set('trending.eligibility.min_current_market_cap', 5_000_000);
        config()->set('trending.eligibility.max_current_market_cap', 200_000_000);
        config()->set('trending.eligibility.enrich_prefilter_max_age_days', 35);

        // Deterministic classifier config.
        config()->set('trending.memecoin.deny_symbols', ['usdc', 'usdt', 'weth', 'wbtc']);
        config()->set('trending.memecoin.deny_name_patterns', ['staked ether', 'wrapped ', 'usd coin']);
        config()->set('trending.memecoin.meme_meta_slugs', ['dog', 'cat', 'frog', 'animal', 'degen', 'meme']);
        config()->set('trending.memecoin.utility_meta_slugs', ['ai', 'nft', 'defi']);
        config()->set('trending.memecoin.meme_keywords', ['pepe', 'doge', 'wif', 'bonk', 'inu', 'cat', 'dog', 'moon']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- fakes -------------------------------------------------------

    /**
     * A meme trending pair — in a meme narrative, in-band CURRENT market cap,
     * young pool, real volume + liquidity. Passes every Trending-Now filter.
     *
     * @param  array<string,mixed>  $o
     * @return array<string,mixed>
     */
    private function memePair(string $chain, string $addr, array $o = []): array
    {
        return array_replace([
            'chainId' => $chain,
            'dexId' => 'raydium',
            'pairAddress' => 'PAIR-'.substr(md5($chain.$addr), 0, 10),
            'baseToken' => ['address' => $addr, 'name' => ucfirst($addr).' Inu', 'symbol' => strtoupper(substr($addr, 0, 6))],
            'quoteToken' => ['address' => 'Q', 'symbol' => 'SOL'],
            'priceUsd' => '0.02',
            'liquidity' => ['usd' => 300_000.0],
            'volume' => ['h6' => 400_000.0, 'h24' => 1_200_000.0],
            'priceChange' => ['h6' => 8.0, 'h24' => 22.0],
            'txns' => ['h6' => ['buys' => 300, 'sells' => 200], 'h24' => ['buys' => 900, 'sells' => 700]],
            'fdv' => 40_000_000.0,
            'marketCap' => 40_000_000.0,
            'pairCreatedAt' => $this->now->subDays(12)->getTimestampMs(),
        ], $o);
    }

    /**
     * @param  list<array<string,mixed>>  $pairs
     * @param  array<string,list<array<string,mixed>>>  $enrichmentPairs
     */
    private function meta(string $slug, string $name, array $pairs, array $enrichmentPairs = []): void
    {
        $this->fx['metas'][] = ['slug' => $slug, 'name' => $name];
        $this->fx['metaDetails'][$slug] = ['slug' => $slug, 'name' => $name, 'pairs' => $pairs];

        foreach ($pairs as $p) {
            $chain = $p['chainId'] ?? null;
            $addr = $p['baseToken']['address'] ?? null;
            if (! is_string($chain) || ! is_string($addr)) {
                continue;
            }
            $key = mb_strtolower("$chain:$addr");
            $this->fx['tokenPairs'][$key] = $enrichmentPairs[$key] ?? [$p];
        }
    }

    private function bootFakes(): void
    {
        if ($this->faked) {
            return;
        }
        $this->faked = true;

        Http::fake([
            'api.dexscreener.com/token-profiles/latest/v1' => fn () => Http::response([]),
            'api.dexscreener.com/token-boosts/latest/v1' => fn () => Http::response([]),
            'api.dexscreener.com/token-boosts/top/v1' => fn () => Http::response([]),
            'api.dexscreener.com/metas/trending/v1' => fn () => Http::response($this->fx['metas']),
            'api.dexscreener.com/metas/meta/v1/*' => function (Request $request) {
                $slug = urldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));
                $this->metaDetailCalls[] = $slug;

                return Http::response($this->fx['metaDetails'][$slug] ?? []);
            },
            'api.dexscreener.com/token-pairs/v1/*' => function (Request $request) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                $seg = array_values(array_filter(explode('/', $path)));
                $key = mb_strtolower(($seg[count($seg) - 2] ?? '').':'.($seg[count($seg) - 1] ?? ''));

                return Http::response($this->fx['tokenPairs'][$key] ?? []);
            },
        ]);
    }

    private function collect(?CarbonImmutable $now = null): void
    {
        $this->bootFakes();
        app(TrendingCollectionService::class)->collect(now: $now ?? $this->now);
    }

    // ---- documented-source + persistence --------------------------

    #[Test]
    public function it_uses_only_the_documented_meta_apis_and_persists_6h_and_24h_snapshots(): void
    {
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'CatA')]);
        $this->meta('dog', 'Dog', [$this->memePair('bsc', 'DogA')]);

        $this->collect();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/metas/trending/v1'));
        $this->assertEqualsCanonicalizing(['cat', 'dog'], $this->metaDetailCalls);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'io.dexscreener.com'));

        $this->assertSame(2, TrendingSnapshot::where('timeframe', '6h')->count());
        $this->assertSame(2, TrendingSnapshot::where('timeframe', '24h')->count());
        $this->assertSame('dexscreener_meta', TrendingSnapshot::first()->source);
        $this->assertSame('TRUE', TrendingSnapshot::first()->is_memecoin_candidate);
    }

    // ---- MEMECOIN filter ----------------------------------------

    #[Test]
    public function a_non_memecoin_trending_token_is_excluded(): void
    {
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'RealCat')]);
        // Stablecoin sitting in a trending meta — must never appear in Trending Now.
        $this->meta('stonks', 'Stonks', [$this->memePair('ethereum', 'Stable', [
            'baseToken' => ['address' => 'Stable', 'name' => 'USD Coin', 'symbol' => 'USDC'],
        ])]);

        $this->collect();

        $this->assertSame(1, TrendingSnapshot::where('timeframe', '6h')->count());
        $this->assertSame('RealCat', TrendingSnapshot::where('timeframe', '6h')->first()->token_address);
        $this->assertSame(0, TrendingSnapshot::where('token_address', 'Stable')->count());
    }

    #[Test]
    public function an_ambiguous_token_with_no_meme_signal_is_excluded(): void
    {
        // In an "ai" (utility) meta, no meme keyword -> UNKNOWN -> excluded.
        $this->meta('ai', 'AI', [$this->memePair('solana', 'Neura', [
            'baseToken' => ['address' => 'Neura', 'name' => 'Neural Compute', 'symbol' => 'NEURA'],
        ])]);

        $this->collect();

        $this->assertSame(0, TrendingSnapshot::count());
    }

    #[Test]
    public function a_meme_keyword_token_is_included_even_from_a_utility_meta(): void
    {
        $this->meta('ai', 'AI', [$this->memePair('solana', 'AiPepe', [
            'baseToken' => ['address' => 'AiPepe', 'name' => 'AI Pepe', 'symbol' => 'AIPEPE'],
        ])]);

        $this->collect();

        $this->assertSame(1, TrendingSnapshot::where('timeframe', '6h')->count());
        $this->assertSame('TRUE', TrendingSnapshot::where('timeframe', '6h')->first()->is_memecoin_candidate);
    }

    // ---- AGE filter -------------------------------------------

    #[Test]
    public function a_token_older_than_thirty_days_never_appears_in_trending_now(): void
    {
        $this->meta('cat', 'Cat', [
            $this->memePair('solana', 'YoungCat'),
            $this->memePair('solana', 'OldCat', ['pairCreatedAt' => $this->now->subDays(90)->getTimestampMs()]),
        ]);

        $this->collect();

        $this->assertSame(1, TrendingSnapshot::where('timeframe', '6h')->count());
        $this->assertSame('YoungCat', TrendingSnapshot::where('timeframe', '6h')->first()->token_address);
        $this->assertSame(0, TrendingSnapshot::where('token_address', 'OldCat')->count());
    }

    #[Test]
    public function a_token_whose_age_cannot_be_established_is_excluded(): void
    {
        // No pairCreatedAt anywhere -> age unknown -> excluded (do not guess).
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'NoAge', ['pairCreatedAt' => null])]);
        $this->fx['tokenPairs']['solana:noage'] = [array_replace($this->memePair('solana', 'NoAge'), ['pairCreatedAt' => null])];

        $this->collect();

        $this->assertSame(0, TrendingSnapshot::count());
        $this->assertSame(0, Token::count());
    }

    #[Test]
    public function a_brand_new_trending_memecoin_is_enriched_and_becomes_eligible(): void
    {
        $this->assertSame(0, Token::count());

        $this->meta('cat', 'Cat', [$this->memePair('solana', 'FreshCat', ['marketCap' => 30_000_000.0, 'pairCreatedAt' => $this->now->subDays(4)->getTimestampMs()])]);

        $this->collect();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/token-pairs/v1/solana/FreshCat'));
        $this->assertDatabaseHas('tokens', ['chain_id' => 'solana', 'token_address' => 'FreshCat']);
        $token = Token::firstOrFail();
        $this->assertSame($token->id, TrendingSnapshot::where('token_address', 'FreshCat')->first()->token_id);
        $this->assertEqualsWithDelta(4.0, TrendingSnapshot::where('token_address', 'FreshCat')->where('timeframe', '6h')->first()->pair_created_at->diffInDays(now(), true), 0.1);
    }

    // ---- CURRENT MARKET filter -------------------------------

    #[Test]
    public function current_market_cap_below_five_million_is_excluded_from_trending_now(): void
    {
        $this->meta('cat', 'Cat', [
            $this->memePair('solana', 'Big', ['marketCap' => 40_000_000.0]),
            $this->memePair('solana', 'Small', ['marketCap' => 3_000_000.0]),
        ]);

        $this->collect();

        $this->assertSame(1, TrendingSnapshot::where('timeframe', '6h')->count());
        $this->assertSame('Big', TrendingSnapshot::where('timeframe', '6h')->first()->token_address);
    }

    #[Test]
    public function current_market_cap_above_two_hundred_million_is_excluded_from_trending_now(): void
    {
        $this->meta('cat', 'Cat', [
            $this->memePair('solana', 'Mid', ['marketCap' => 40_000_000.0]),
            $this->memePair('solana', 'Huge', ['marketCap' => 450_000_000.0]),
        ]);

        $this->collect();

        $this->assertSame(1, TrendingSnapshot::where('timeframe', '6h')->count());
        $this->assertSame('Mid', TrendingSnapshot::where('timeframe', '6h')->first()->token_address);
    }

    #[Test]
    public function zero_volume_and_zero_liquidity_are_excluded(): void
    {
        $this->meta('cat', 'Cat', [
            $this->memePair('solana', 'Ok'),
            $this->memePair('solana', 'NoLiq', ['liquidity' => ['usd' => 0.0]]),
            $this->memePair('solana', 'NoVol', ['volume' => ['h6' => 0.0, 'h24' => 0.0]]),
        ]);

        $this->collect();

        $this->assertSame(['Ok'], TrendingSnapshot::where('timeframe', '6h')->pluck('token_address')->all());
    }

    #[Test]
    public function per_timeframe_volume_gates_each_view_independently(): void
    {
        // Volume in h24 but zero in h6 -> appears in 24h only.
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'DayOnly', ['volume' => ['h6' => 0.0, 'h24' => 900_000.0]])]);

        $this->collect();

        $this->assertSame(0, TrendingSnapshot::where('timeframe', '6h')->count());
        $this->assertSame(1, TrendingSnapshot::where('timeframe', '24h')->count());
    }

    // ---- ranking + TOP N -----------------------------------

    #[Test]
    public function ranks_are_deterministic_and_dense_per_timeframe(): void
    {
        $this->meta('cat', 'Cat', [
            $this->memePair('solana', 'Hot', ['volume' => ['h6' => 5_000_000.0, 'h24' => 9_000_000.0], 'priceChange' => ['h6' => 90.0, 'h24' => 120.0], 'txns' => ['h6' => ['buys' => 4000, 'sells' => 3000], 'h24' => ['buys' => 9000, 'sells' => 8000]]]),
            $this->memePair('solana', 'Warm', ['volume' => ['h6' => 500_000.0, 'h24' => 1_000_000.0]]),
            $this->memePair('solana', 'Cool', ['volume' => ['h6' => 20_000.0, 'h24' => 40_000.0], 'priceChange' => ['h6' => -30.0, 'h24' => -40.0], 'txns' => ['h6' => ['buys' => 10, 'sells' => 20], 'h24' => ['buys' => 30, 'sells' => 40]]]),
        ]);

        $this->collect();

        $ranks = TrendingSnapshot::where('timeframe', '6h')->orderBy('trend_rank')->pluck('token_address', 'trend_rank');
        $this->assertSame([1, 2, 3], $ranks->keys()->all());
        $this->assertSame('Hot', $ranks[1]);
        $this->assertSame('Cool', $ranks[3]);
    }

    #[Test]
    public function only_eligible_memecoins_are_scored_and_stored(): void
    {
        $pairs = [$this->memePair('solana', 'MemeOk')];
        // 30 non-memes + 5 sub-$5M memes + 5 old memes — all excluded.
        for ($i = 0; $i < 30; $i++) {
            $pairs[] = $this->memePair('solana', "Util$i", ['baseToken' => ['address' => "Util$i", 'name' => "Utility $i", 'symbol' => "UTL$i"]]);
        }
        for ($i = 0; $i < 5; $i++) {
            $pairs[] = $this->memePair('solana', "Tiny$i", ['marketCap' => 1_000_000.0]);
        }
        $this->meta('ai', 'AI', $pairs); // "ai" is a utility meta

        $this->collect();

        // Only "MemeOk" (name has "Inu") survives.
        $this->assertSame(1, TrendingSnapshot::where('timeframe', '6h')->count());
        $this->assertSame('MemeOk', TrendingSnapshot::where('timeframe', '6h')->first()->token_address);
    }

    // ---- chain mapping ------------------------------------

    #[Test]
    public function chain_ids_map_to_the_five_display_buckets_and_keep_their_real_chain(): void
    {
        $this->meta('animal', 'Animals', [
            $this->memePair('solana', 'S1'),
            $this->memePair('robinhood', 'R1'),
            $this->memePair('bsc', 'B1'),
            $this->memePair('base', 'BA1'),
            $this->memePair('ton', 'T1'),
            $this->memePair('avalanche', 'A1'),
        ]);

        $this->collect();

        $buckets = DailyTrendingRanking::where('timeframe', '6h')->pluck('chain_bucket', 'chain_id');
        $this->assertSame('solana', $buckets['solana']);
        $this->assertSame('other', $buckets['ton']);
        $this->assertSame('other', $buckets['avalanche']);
        $this->assertSame('ton', TrendingSnapshot::where('token_address', 'T1')->first()->chain_id);
    }

    // ---- dedupe / persistence ----------------------------

    #[Test]
    public function repeated_runs_inside_the_same_5_minute_bucket_are_deduplicated(): void
    {
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'CatA')]);

        $this->collect($this->now);
        $this->collect($this->now->addMinutes(2));

        $this->assertSame(1, TrendingSnapshot::where('timeframe', '6h')->where('token_address', 'CatA')->count());
        $this->assertSame(1, TrendingSnapshot::where('token_address', 'CatA')->where('timeframe', '6h')->first()->trend_appearances);
    }

    #[Test]
    public function a_new_capture_bucket_appends_a_new_snapshot_and_raises_appearances(): void
    {
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'CatA')]);

        $this->collect($this->now);
        $this->collect($this->now->addMinutes(6));
        $this->collect($this->now->addMinutes(12));

        $rows = TrendingSnapshot::where('token_address', 'CatA')->where('timeframe', '6h')->orderBy('capture_bucket')->get();
        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], $rows->pluck('trend_appearances')->all());
    }

    #[Test]
    public function the_daily_archive_records_best_rank_min_and_peaks_max(): void
    {
        $this->meta('cat', 'Cat', [
            $this->memePair('solana', 'Alpha', ['volume' => ['h6' => 100_000.0, 'h24' => 200_000.0]]),
            $this->memePair('solana', 'Beta', ['volume' => ['h6' => 5_000_000.0, 'h24' => 9_000_000.0]]),
        ]);

        $this->collect($this->now);

        $this->fx['metaDetails']['cat']['pairs'] = [
            $this->memePair('solana', 'Alpha', ['volume' => ['h6' => 8_000_000.0, 'h24' => 12_000_000.0], 'marketCap' => 55_000_000.0]),
            $this->memePair('solana', 'Beta', ['volume' => ['h6' => 60_000.0, 'h24' => 90_000.0]]),
        ];
        $this->collect($this->now->addMinutes(6));

        $alpha = DailyTrendingRanking::where('token_address', 'Alpha')->where('timeframe', '6h')->firstOrFail();
        $this->assertSame(1, $alpha->best_rank);
        $this->assertSame(2, $alpha->appearances);
        $this->assertEqualsWithDelta(8_000_000.0, $alpha->peak_volume, 1.0);
        $this->assertEqualsWithDelta(55_000_000.0, $alpha->peak_market_cap, 1.0);
    }

    #[Test]
    public function chain_activity_rows_are_materialised_for_all_five_buckets(): void
    {
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'FreshS', ['marketCap' => 20_000_000.0, 'pairCreatedAt' => $this->now->subDays(5)->getTimestampMs()])]);

        $this->collect();

        $this->assertSame(5, DailyChainActivity::where('date', $this->now->toDateString())->count());
    }

    // ---- historical preservation --------------------------

    #[Test]
    public function historical_snapshots_remain_after_a_token_exits_the_current_trend(): void
    {
        $day1 = CarbonImmutable::parse('2026-09-01T10:00:00Z');
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'GhostCat', ['pairCreatedAt' => $day1->subDays(12)->getTimestampMs()])]);
        $this->collect($day1);

        // Day 2 — GhostCat gone from trending entirely.
        $this->fx['metaDetails']['cat']['pairs'] = [$this->memePair('solana', 'OtherCat')];
        $this->collect(CarbonImmutable::parse('2026-09-02T10:00:00Z'));

        // The day-1 snapshot + archive row are untouched.
        $this->assertSame(1, TrendingSnapshot::where('token_address', 'GhostCat')->where('timeframe', '6h')->where('capture_bucket', intdiv($day1->getTimestamp(), 300) * 300)->count());
        $this->assertDatabaseHas('daily_trending_rankings', ['date' => '2026-09-01', 'token_address' => 'GhostCat', 'timeframe' => '6h']);
        $this->assertSame(0, DailyTrendingRanking::where('date', '2026-09-02')->where('token_address', 'GhostCat')->count());
    }

    #[Test]
    public function a_token_that_ages_past_thirty_days_keeps_its_old_snapshots_but_gets_no_new_one(): void
    {
        $day1 = CarbonImmutable::parse('2026-08-10T10:00:00Z');
        // Pool created 25 days before day1 -> eligible on day1.
        $this->meta('cat', 'Cat', [$this->memePair('solana', 'Ager', ['pairCreatedAt' => $day1->subDays(25)->getTimestampMs()])]);
        $this->collect($day1);
        $this->assertSame(1, TrendingSnapshot::where('token_address', 'Ager')->where('timeframe', '6h')->count());

        // 20 days later the same pool is now 45 days old -> no new snapshot.
        $this->collect($day1->addDays(20));

        $this->assertSame(1, TrendingSnapshot::where('token_address', 'Ager')->where('timeframe', '6h')->count());
        // ...but the day-1 row still exists.
        $this->assertDatabaseHas('daily_trending_rankings', ['token_address' => 'Ager', 'date' => '2026-08-10']);
    }

    #[Test]
    public function cleanup_prunes_old_snapshots_but_keeps_recent_ones_and_the_daily_archive(): void
    {
        config()->set('trending.retention.snapshot_days', 30);
        config()->set('trending.retention.daily_days', 365);

        $this->meta('cat', 'Cat', [$this->memePair('solana', 'CatA')]);
        $this->collect();

        TrendingSnapshot::query()->update(['captured_at' => $this->now->subDays(45)]);
        $freshId = TrendingSnapshot::query()->first()->id;
        TrendingSnapshot::whereKey($freshId)->update(['captured_at' => $this->now->subDay()]);
        DailyTrendingRanking::query()->update(['date' => $this->now->subDays(400)->toDateString()]);
        DailyChainActivity::query()->update(['date' => $this->now->subDays(400)->toDateString()]);

        $this->artisan('memecoins:cleanup-trending')->assertExitCode(0);

        $this->assertSame(1, TrendingSnapshot::count());
        $this->assertSame(0, DailyTrendingRanking::count());
        $this->assertSame(0, DailyChainActivity::count());
    }
}
