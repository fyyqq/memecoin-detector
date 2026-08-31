<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\IngestionRun;
use App\Models\Token;
use App\Services\DexScreener\DexScreenerDiscoveryService;
use App\Services\DexScreener\DiscoveryResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 19 — trending-meta-first discovery.
 *
 * Primary source: DexScreener's documented Trending Meta API
 * (GET /metas/trending/v1 → GET /metas/meta/v1/{slug}). The real per-pair
 * Trending table (io.dexscreener.com WebSocket) is undocumented + Cloudflare
 * -walled and is NOT used — see docs/trending-discovery-reconnaissance.md.
 *
 * DexScreener is fully HTTP-faked. No live calls.
 */
class TrendingMetaDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    /** @var list<string> */
    private array $metaDetailCalls = [];

    /** @var list<string> */
    private array $searchCalls = [];

    /** @var list<string> token-pairs enrichment paths in call order */
    private array $enrichCalls = [];

    /** @var array<string,mixed> */
    private array $fx = [
        'metas' => [],
        /** slug => { name, pairs: [...] } */
        'metaDetails' => [],
        'profiles' => [],
        'latestBoosts' => [],
        'topBoosts' => [],
        /** term => list<pair> */
        'search' => [],
        /** "chain:addr" => list<pair> */
        'tokenPairs' => [],
    ];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-28T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('dexscreener.base_url', 'https://api.dexscreener.com');
        config()->set('dexscreener.cache.discovery_ttl', 0);
        config()->set('dexscreener.cache.enrichment_ttl', 0);
        config()->set('dexscreener.http.retries', 0);
        config()->set('dexscreener.http.retry_sleep_ms', 0);
        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('dexscreener.filters.prefilter_max_age_days', 35);
        config()->set('dexscreener.limits.discovery_candidate_cap', 500);
        config()->set('dexscreener.limits.max_candidates_to_enrich', 120);
        config()->set('dexscreener.limits.default_result_limit', 20);
        config()->set('dexscreener.limits.max_result_limit', 50);

        // Step 19 defaults: trending meta ON, keyword OFF.
        config()->set('dexscreener.discovery_sources.trending_meta_enabled', true);
        config()->set('dexscreener.discovery_sources.trending_meta_limit', 18);
        config()->set('dexscreener.discovery_sources.profiles_enabled', true);
        config()->set('dexscreener.discovery_sources.boosts_enabled', true);
        config()->set('dexscreener.discovery_sources.keyword_enabled', false);
        config()->set('dexscreener.trending_meta_terms', 0);
        config()->set('dexscreener.search_terms', ['pepe']);
        config()->set('dexscreener.search.ecosystem_terms', []);

        config()->set('historical.coingecko.enabled', false);
        config()->set('historical.geckoterminal.enabled', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- fakes / helpers ------------------------------------------------

    /**
     * @param  array<string,mixed>  $o
     * @return array<string,mixed>
     */
    private function metaPair(string $chain, string $addr, array $o = []): array
    {
        return array_replace([
            'chainId' => $chain,
            'dexId' => 'raydium',
            'pairAddress' => 'MP-'.substr(md5($chain.$addr), 0, 12),
            'baseToken' => ['address' => $addr, 'name' => ucfirst($addr), 'symbol' => strtoupper(substr($addr, 0, 5))],
            'quoteToken' => ['address' => 'Q', 'symbol' => 'SOL'],
            'priceUsd' => '0.02',
            'liquidity' => ['usd' => 300_000.0],
            'volume' => ['h24' => 90_000.0],
            'priceChange' => ['h24' => 12.0, 'h6' => 4.0],
            'txns' => ['h24' => ['buys' => 100, 'sells' => 60]],
            'fdv' => 50_000_000.0,
            'marketCap' => 50_000_000.0,
            'pairCreatedAt' => $this->now->subDays(10)->getTimestampMs(),
        ], $o);
    }

    /**
     * Register a trending meta and its member pairs. Also registers a default
     * /token-pairs/v1 enrichment response per member (one pair, echoing the meta
     * pair) unless the caller overrides it via $enrichmentPairs.
     *
     * @param  list<array<string,mixed>>  $pairs
     * @param  array<string,list<array<string,mixed>>>  $enrichmentPairs  "chain:addr" => pairs
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
            if (! isset($this->fx['tokenPairs'][$key])) {
                $this->fx['tokenPairs'][$key] = $enrichmentPairs[$key] ?? [$p];
            }
        }

        foreach ($enrichmentPairs as $key => $ep) {
            $this->fx['tokenPairs'][mb_strtolower($key)] = $ep;
        }
    }

    private function bootFakes(): void
    {
        if ($this->faked) {
            return;
        }
        $this->faked = true;

        Http::fake([
            'api.dexscreener.com/token-profiles/latest/v1' => fn () => Http::response($this->fx['profiles']),
            'api.dexscreener.com/token-boosts/latest/v1' => fn () => Http::response($this->fx['latestBoosts']),
            'api.dexscreener.com/token-boosts/top/v1' => fn () => Http::response($this->fx['topBoosts']),
            'api.dexscreener.com/metas/trending/v1' => fn () => Http::response($this->fx['metas']),
            'api.dexscreener.com/metas/meta/v1/*' => function (Request $request) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                $slug = urldecode(basename($path));
                $this->metaDetailCalls[] = $slug;

                return Http::response($this->fx['metaDetails'][$slug] ?? []);
            },
            'api.dexscreener.com/latest/dex/search*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $term = (string) ($q['q'] ?? '');
                $this->searchCalls[] = $term;

                return Http::response(['schemaVersion' => '1.0.0', 'pairs' => $this->fx['search'][$term] ?? []]);
            },
            'api.dexscreener.com/token-pairs/v1/*' => function (Request $request) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);
                $this->enrichCalls[] = $path;
                $seg = array_values(array_filter(explode('/', $path)));
                $key = mb_strtolower(($seg[count($seg) - 2] ?? '').':'.($seg[count($seg) - 1] ?? ''));

                return Http::response($this->fx['tokenPairs'][$key] ?? []);
            },
        ]);
    }

    private function discoverResult(?int $limit = null): DiscoveryResult
    {
        $this->bootFakes();

        return app(DexScreenerDiscoveryService::class)->discover(null, $limit, IngestionRun::TRIGGER_MANUAL);
    }

    /** @return array<string,mixed> */
    private function diagnostics(?int $limit = null): array
    {
        return $this->discoverResult($limit)->diagnostics;
    }

    // ---- tests --------------------------------------------------------

    #[Test]
    public function the_trending_meta_endpoints_are_called_and_slugs_consumed(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'CatA')]);
        $this->meta('dog', 'Dog', [$this->metaPair('solana', 'DogA')]);

        $d = $this->diagnostics();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/metas/trending/v1'));
        $this->assertEqualsCanonicalizing(['cat', 'dog'], $this->metaDetailCalls);
        $this->assertSame(2, $d['trending_meta_count']);
        $this->assertEqualsCanonicalizing(['cat', 'dog'], $d['trending_meta_slugs_used']);
        $this->assertSame(2, $d['trending_meta_pairs_seen']);
    }

    #[Test]
    public function member_pairs_become_candidates_tagged_trending_meta(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'CatQual', ['marketCap' => 40_000_000.0])]);

        $result = $this->discoverResult();

        $this->assertCount(1, $result->candidates);
        $row = $result->candidates[0]->toArray();
        $this->assertContains('trending_meta', $row['sources']);
        $this->assertSame('cat', $row['discovery_context']['trending_meta_slug']);
        $this->assertSame('Cat', $row['discovery_context']['trending_meta_name']);
        $this->assertSame(1, $result->diagnostics['discovery_source_counts']['trending_meta']);
    }

    #[Test]
    public function multiple_metas_for_the_same_token_are_unioned(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'Shared', ['marketCap' => 30_000_000.0])]);
        $this->meta('dog', 'Dog', [$this->metaPair('solana', 'Shared', ['marketCap' => 30_000_000.0])]);

        $result = $this->discoverResult();

        $this->assertCount(1, $result->candidates);
        $ctx = $result->candidates[0]->toArray()['discovery_context'];
        $this->assertSame(2, $ctx['trending_meta_count']);
        // one 'trending_meta' source tag, not two
        $this->assertSame(['trending_meta'], $result->candidates[0]->toArray()['sources']);
    }

    #[Test]
    public function the_source_set_unions_across_all_discovery_feeds(): void
    {
        $addr = 'MultiSrc';
        $this->fx['profiles'] = [['chainId' => 'solana', 'tokenAddress' => $addr]];
        $this->fx['latestBoosts'] = [['chainId' => 'solana', 'tokenAddress' => $addr]];
        $this->meta('cat', 'Cat', [$this->metaPair('solana', $addr, ['marketCap' => 20_000_000.0])]);

        $sources = $this->discoverResult()->candidates[0]->toArray()['sources'];

        $this->assertEqualsCanonicalizing(['trending_meta', 'profile', 'boost'], $sources);
    }

    #[Test]
    public function the_paid_narrative_bar_ad_is_ignored(): void
    {
        // The documented /metas/meta response never carries the ad, but a
        // non-pair entry (no baseToken.address / no pairAddress) must be skipped
        // and never counted as an organic discovery.
        $this->meta('cat', 'Cat', [
            $this->metaPair('solana', 'RealCat', ['marketCap' => 12_000_000.0]),
            ['type' => 'ad', 'title' => 'Sponsored — Play now!', 'url' => 'https://sponsor.example'],
            ['chainId' => 'solana', 'baseToken' => ['address' => 'NoPairAddr'], 'marketCap' => 9_000_000.0],
        ]);

        $result = $this->discoverResult();

        $this->assertCount(1, $result->candidates);
        $this->assertSame('solana:realcat', $result->candidates[0]->toArray()['token_key']);
        $this->assertSame(2, $result->diagnostics['trending_meta_ad_or_malformed_skipped']);
    }

    // ---- pre-filter --------------------------------------------------

    #[Test]
    public function a_market_cap_above_the_ceiling_is_pre_filtered_out(): void
    {
        $this->meta('cat', 'Cat', [
            $this->metaPair('solana', 'Huge', ['marketCap' => 250_000_000.0]),
            $this->metaPair('solana', 'Fine', ['marketCap' => 120_000_000.0]),
        ]);

        $d = $this->diagnostics();

        $this->assertSame(1, $d['trending_meta_prefilter_dropped']);
        $this->assertSame(1, $d['trending_meta_prefilter_reasons']['market_cap_above_ceiling']);
        $this->assertSame(1, $d['unique_candidates']);
        $this->assertCount(1, $this->enrichCalls);
    }

    #[Test]
    public function a_market_cap_at_or_below_the_ceiling_survives_pre_filter(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'Edge', ['marketCap' => 200_000_000.0])]);

        $d = $this->diagnostics();

        $this->assertSame(0, $d['trending_meta_prefilter_dropped']);
        $this->assertSame(1, $d['unique_candidates']);
    }

    #[Test]
    public function the_five_million_lower_bound_is_not_a_pre_filter(): void
    {
        // A trending-meta pair with current MC well below $5M must still be
        // enriched + persisted (it may qualify via historical evidence later).
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'Small', ['marketCap' => 900_000.0])]);

        $d = $this->diagnostics();

        $this->assertSame(0, $d['trending_meta_prefilter_dropped']);
        $this->assertSame(1, $d['unique_candidates']);
        $this->assertSame(1, $d['age_eligible']);
        $this->assertDatabaseCount('tokens', 1);
        // ...but it does not reach the main list without a >= $5M peak.
        $this->assertSame(0, $d['qualified']);
    }

    #[Test]
    public function zero_volume_is_pre_filtered_out(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'NoVol', ['volume' => ['h24' => 0.0]])]);

        $d = $this->diagnostics();

        $this->assertSame(1, $d['trending_meta_prefilter_reasons']['volume_zero']);
        $this->assertSame(0, $d['unique_candidates']);
    }

    #[Test]
    public function zero_liquidity_is_pre_filtered_out(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'NoLiq', ['liquidity' => ['usd' => 0.0]])]);

        $d = $this->diagnostics();

        $this->assertSame(1, $d['trending_meta_prefilter_reasons']['liquidity_zero']);
        $this->assertSame(0, $d['unique_candidates']);
    }

    #[Test]
    public function a_missing_market_cap_is_pre_filtered_out(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'NoMc', ['marketCap' => null])]);

        $d = $this->diagnostics();

        $this->assertSame(1, $d['trending_meta_prefilter_reasons']['market_cap_missing_or_zero']);
        $this->assertSame(0, $d['unique_candidates']);
    }

    #[Test]
    public function a_missing_pair_created_at_is_pre_filtered_out(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'NoDate', ['pairCreatedAt' => null])]);

        $d = $this->diagnostics();

        $this->assertSame(1, $d['trending_meta_prefilter_reasons']['pair_created_at_missing']);
        $this->assertSame(0, $d['unique_candidates']);
    }

    #[Test]
    public function a_loose_pair_age_over_thirty_five_days_is_pre_filtered_out(): void
    {
        $this->meta('cat', 'Cat', [
            $this->metaPair('solana', 'Ancient', ['pairCreatedAt' => $this->now->subDays(40)->getTimestampMs()]),
        ]);

        $d = $this->diagnostics();

        $this->assertSame(1, $d['trending_meta_prefilter_reasons']['loose_age_exceeded']);
        $this->assertSame(0, $d['unique_candidates']);
    }

    #[Test]
    public function final_age_validation_uses_the_earliest_pair_created_at_not_the_meta_pair(): void
    {
        // Meta pair says the pool is 20 days old (survives the loose 35-day
        // pre-filter), but full enrichment reveals an older pool → excluded by
        // the FINAL 30-day gate.
        $addr = 'OldToken';
        $this->meta('cat', 'Cat',
            [$this->metaPair('solana', $addr, ['pairCreatedAt' => $this->now->subDays(20)->getTimestampMs()])],
            ["solana:$addr" => [
                $this->metaPair('solana', $addr, ['pairAddress' => 'new', 'pairCreatedAt' => $this->now->subDays(20)->getTimestampMs()]),
                $this->metaPair('solana', $addr, ['pairAddress' => 'old', 'liquidity' => ['usd' => 999_999.0], 'pairCreatedAt' => $this->now->subDays(45)->getTimestampMs()]),
            ]],
        );

        $d = $this->diagnostics();

        $this->assertSame(1, $d['unique_candidates']);
        $this->assertSame(1, $d['older_than_max_age']);
        $this->assertSame(0, $d['age_eligible']);
        $this->assertDatabaseCount('tokens', 0);
    }

    // ---- qualification: $5M–$200M peak universe --------------------

    #[Test]
    public function a_peak_within_the_band_qualifies(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'Mid', ['marketCap' => 60_000_000.0])]);

        $d = $this->diagnostics();

        $this->assertSame(1, $d['qualified']);
        $this->assertSame(1, $this->discoverResult()->diagnostics['returned']);
    }

    #[Test]
    public function a_current_mc_below_five_million_stays_qualified_after_an_earlier_peak(): void
    {
        $addr = 'Dumper';
        // Run 1: MC $80M → qualifies, observed_peak = $80M.
        $this->meta('cat', 'Cat', [$this->metaPair('solana', $addr, ['marketCap' => 80_000_000.0])]);
        $this->discoverResult()->diagnostics;

        // Run 2: MC dumps to $2M.
        $this->fx['metaDetails']['cat']['pairs'] = [$this->metaPair('solana', $addr, ['marketCap' => 2_000_000.0])];
        $this->fx['tokenPairs'][mb_strtolower("solana:$addr")] = [$this->metaPair('solana', $addr, ['marketCap' => 2_000_000.0])];
        CarbonImmutable::setTestNow($this->now->addHour());

        $d = $this->discoverResult()->diagnostics;

        $this->assertSame(1, $d['qualified']);
        $token = Token::query()->firstOrFail();
        $this->assertSame(80_000_000.0, $token->observed_peak_market_cap);

        $this->getJson('/api/memecoins')->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.current_market_cap', fn ($v) => (float) $v === 2_000_000.0)
            ->assertJsonPath('data.0.observed_peak_market_cap', fn ($v) => (float) $v === 80_000_000.0);
    }

    #[Test]
    public function a_verified_historical_peak_in_band_qualifies_when_current_mc_is_low(): void
    {
        $addr = 'Verified';
        $token = Token::query()->create([
            'chain_id' => 'solana',
            'token_address' => $addr,
            'symbol' => 'VER',
            'name' => 'Verified',
            'earliest_pair_created_at' => $this->now->subDays(8),
            'first_observed_at' => $this->now->subDays(2),
            'last_observed_at' => $this->now->subDays(2),
            'observed_peak_market_cap' => 1_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDays(2),
        ]);
        HistoricalPeakEvidence::query()->create([
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'peak_value_usd' => 40_000_000.0,
            'peak_observed_at' => $this->now->subDays(5),
            'evidence_source' => 'coingecko',
            'evidence_basis' => 'market_cap',
            'checked_at' => $this->now->subDays(2),
        ]);
        $token->update([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 40_000_000.0,
            'historical_peak_value_at' => $this->now->subDays(5),
        ]);

        $this->meta('cat', 'Cat', [$this->metaPair('solana', $addr, ['marketCap' => 900_000.0])]);

        $d = $this->diagnostics();

        $this->assertSame(1, $d['qualified']);
        $this->getJson('/api/memecoins')->assertOk()->assertJsonPath('meta.count', 1);
    }

    #[Test]
    public function a_verified_peak_above_the_ceiling_does_not_qualify_even_when_current_mc_is_in_band(): void
    {
        $addr = 'Whale';
        $token = Token::query()->create([
            'chain_id' => 'solana',
            'token_address' => $addr,
            'symbol' => 'WHL',
            'name' => 'Whale',
            'earliest_pair_created_at' => $this->now->subDays(8),
            'first_observed_at' => $this->now->subDays(2),
            'last_observed_at' => $this->now->subDays(2),
            'observed_peak_market_cap' => 320_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDays(2),
        ]);

        $this->meta('cat', 'Cat', [$this->metaPair('solana', $addr, ['marketCap' => 150_000_000.0])]);

        $d = $this->diagnostics();

        $this->assertSame(0, $d['qualified']);
        $this->assertSame(1, $d['not_qualified_peak_above_ceiling']);
        $this->assertSame('qualifying_peak_above_ceiling', $this->discoverResult()->notQualifiedSample[0]['reason']);
        $this->getJson('/api/memecoins')->assertOk()->assertJsonPath('meta.count', 0);
        $this->assertSame($token->id, Token::query()->firstOrFail()->id);
        $this->assertSame(320_000_000.0, Token::query()->firstOrFail()->observed_peak_market_cap);
    }

    #[Test]
    public function a_historical_estimate_alone_does_not_qualify(): void
    {
        $addr = 'EstOnly';
        $token = Token::query()->create([
            'chain_id' => 'solana',
            'token_address' => $addr,
            'symbol' => 'EST',
            'name' => 'Est Only',
            'earliest_pair_created_at' => $this->now->subDays(8),
            'first_observed_at' => $this->now->subDays(2),
            'last_observed_at' => $this->now->subDays(2),
            'observed_peak_market_cap' => 1_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDays(2),
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'historical_estimate_fdv_usd' => 40_000_000.0,
        ]);
        HistoricalPeakEvidence::query()->create([
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'peak_value_usd' => 40_000_000.0,
            'evidence_source' => 'geckoterminal',
            'evidence_basis' => 'fdv_total_supply',
            // Within the re-lookup cooldown so the estimate row survives this run.
            'checked_at' => $this->now->subMinutes(30),
        ]);

        $this->meta('cat', 'Cat', [$this->metaPair('solana', $addr, ['marketCap' => 900_000.0])]);

        $d = $this->diagnostics();

        $this->assertSame(0, $d['qualified']);
        $this->assertSame(1, $d['not_qualified_fdv_estimate_only']);
        $this->getJson('/api/memecoins')->assertOk()->assertJsonPath('meta.count', 0);
    }

    #[Test]
    public function the_observed_peak_market_cap_semantics_are_unchanged(): void
    {
        $addr = 'PeakSem';
        $this->meta('cat', 'Cat', [$this->metaPair('solana', $addr, ['marketCap' => 30_000_000.0])]);
        $this->discoverResult();

        $this->fx['metaDetails']['cat']['pairs'] = [$this->metaPair('solana', $addr, ['marketCap' => 9_000_000.0])];
        $this->fx['tokenPairs'][mb_strtolower("solana:$addr")] = [$this->metaPair('solana', $addr, ['marketCap' => 9_000_000.0])];
        CarbonImmutable::setTestNow($this->now->addHours(2));
        $this->discoverResult();

        $token = Token::query()->firstOrFail();
        $this->assertSame(30_000_000.0, $token->observed_peak_market_cap);
        $this->assertSame(2, $token->marketSnapshots()->count());
    }

    // ---- prioritization + caps -------------------------------------

    #[Test]
    public function trending_meta_outranks_profile_boost_and_search(): void
    {
        config()->set('dexscreener.discovery_sources.keyword_enabled', true);
        config()->set('dexscreener.search_terms', ['pepe']);
        config()->set('dexscreener.limits.discovery_candidate_cap', 1);

        // A: trending meta only.  B: profile + boost + 3 search hits.
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'AAA', ['marketCap' => 10_000_000.0])]);
        $this->fx['profiles'] = [['chainId' => 'solana', 'tokenAddress' => 'BBB']];
        $this->fx['latestBoosts'] = [['chainId' => 'solana', 'tokenAddress' => 'BBB']];
        $this->fx['search']['pepe'] = [
            $this->metaPair('solana', 'BBB'), $this->metaPair('solana', 'BBB'), $this->metaPair('solana', 'BBB'),
        ];
        $this->fx['tokenPairs']['solana:bbb'] = [$this->metaPair('solana', 'BBB')];

        $d = $this->diagnostics();

        $this->assertSame(2, $d['unique_candidates']);
        $this->assertSame(1, $d['candidates_considered']);
        $this->assertSame(['/token-pairs/v1/solana/AAA'], $this->enrichCalls);
    }

    #[Test]
    public function more_distinct_trending_metas_ranks_higher(): void
    {
        config()->set('dexscreener.limits.discovery_candidate_cap', 1);

        $this->meta('cat', 'Cat', [
            $this->metaPair('solana', 'ONE', ['marketCap' => 10_000_000.0]),
            $this->metaPair('solana', 'TWO', ['marketCap' => 10_000_000.0]),
        ]);
        $this->meta('dog', 'Dog', [$this->metaPair('solana', 'TWO', ['marketCap' => 10_000_000.0])]);

        $this->diagnostics();

        // TWO is in 2 metas → enriched first.
        $this->assertSame(['/token-pairs/v1/solana/TWO'], $this->enrichCalls);
    }

    #[Test]
    public function the_candidate_cap_and_enrichment_cap_are_respected(): void
    {
        config()->set('dexscreener.limits.discovery_candidate_cap', 4);
        config()->set('dexscreener.limits.max_candidates_to_enrich', 2);

        $pairs = [];
        foreach (range(1, 8) as $i) {
            $pairs[] = $this->metaPair('solana', "cap$i", ['marketCap' => 10_000_000.0]);
        }
        $this->meta('cat', 'Cat', $pairs);

        $d = $this->diagnostics();

        $this->assertSame(8, $d['unique_candidates']);
        $this->assertSame(4, $d['candidate_cap_dropped']);
        $this->assertSame(4, $d['candidates_considered']);
        $this->assertSame(2, $d['selected_for_enrichment']);
        $this->assertSame(2, $d['deferred_candidates']);
        $this->assertCount(2, $this->enrichCalls);
    }

    #[Test]
    public function the_trending_meta_limit_bounds_how_many_metas_are_expanded(): void
    {
        config()->set('dexscreener.discovery_sources.trending_meta_limit', 2);
        foreach (['a', 'b', 'c', 'd'] as $s) {
            $this->meta($s, strtoupper($s), [$this->metaPair('solana', "tok$s", ['marketCap' => 10_000_000.0])]);
        }

        $d = $this->diagnostics();

        $this->assertSame(2, $d['trending_meta_count']);
        $this->assertCount(2, $this->metaDetailCalls);
    }

    // ---- keyword fallback -----------------------------------------

    #[Test]
    public function keyword_search_is_off_by_default(): void
    {
        config()->set('dexscreener.search_terms', ['pepe']);
        $this->fx['search']['pepe'] = [$this->metaPair('solana', 'SearchTok', ['marketCap' => 10_000_000.0])];
        $this->fx['tokenPairs']['solana:searchtok'] = [$this->metaPair('solana', 'SearchTok')];
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'MetaTok', ['marketCap' => 10_000_000.0])]);

        $d = $this->diagnostics();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/latest/dex/search'));
        $this->assertSame([], $this->searchCalls);
        $this->assertSame(0, $d['search_terms_used']);
        $this->assertFalse($d['keyword_discovery_enabled']);
        $this->assertSame(0, $d['discovery_source_counts']['search']);
        $this->assertSame(1, $d['unique_candidates']); // MetaTok only
    }

    #[Test]
    public function keyword_search_works_as_a_supplemental_source_when_enabled(): void
    {
        config()->set('dexscreener.discovery_sources.keyword_enabled', true);
        config()->set('dexscreener.search_terms', ['pepe']);
        $this->fx['search']['pepe'] = [$this->metaPair('solana', 'SearchTok', ['marketCap' => 10_000_000.0])];
        $this->fx['tokenPairs']['solana:searchtok'] = [$this->metaPair('solana', 'SearchTok', ['marketCap' => 10_000_000.0])];
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'MetaTok', ['marketCap' => 10_000_000.0])]);

        $d = $this->diagnostics();

        $this->assertContains('pepe', $this->searchCalls);
        $this->assertTrue($d['keyword_discovery_enabled']);
        $this->assertSame(1, $d['discovery_source_counts']['search']);
        $this->assertSame(1, $d['discovery_source_counts']['trending_meta']);
        $this->assertSame(2, $d['unique_candidates']);
    }

    // ---- diagnostics + discovery-status --------------------------

    #[Test]
    public function chain_diagnostics_reflect_only_chains_actually_seen(): void
    {
        $this->meta('cat', 'Cat', [
            $this->metaPair('solana', 'S1', ['marketCap' => 10_000_000.0]),
            $this->metaPair('solana', 'S2', ['marketCap' => 10_000_000.0]),
            $this->metaPair('base', 'B1', ['marketCap' => 10_000_000.0]),
            $this->metaPair('ethereum', 'E1', ['marketCap' => 10_000_000.0]),
        ]);

        $d = $this->diagnostics();

        $this->assertSame(['solana' => 2, 'base' => 1, 'ethereum' => 1], $d['chains_discovered']);
        $this->assertArrayNotHasKey('bsc', $d['chains_discovered']);
        $this->assertSame(
            ['trending_meta' => 4, 'profile' => 0, 'boost' => 0, 'search' => 0],
            $d['discovery_source_counts'],
        );
    }

    #[Test]
    public function the_discovery_status_endpoint_exposes_trending_meta_coverage_and_never_calls_dexscreener(): void
    {
        $this->meta('cat', 'Cat', [
            $this->metaPair('solana', 'S1', ['marketCap' => 10_000_000.0]),
            $this->metaPair('solana', 'Huge', ['marketCap' => 900_000_000.0]),
        ]);
        $this->meta('dog', 'Dog', [$this->metaPair('base', 'B1', ['marketCap' => 10_000_000.0])]);
        $this->discoverResult();

        Http::fake(); // record from here on; nothing new should be sent
        $res = $this->getJson('/api/memecoins/discovery-status')->assertOk();
        Http::assertNothingSent();

        $res->assertJsonPath('data.trending_meta.meta_count', 2);
        $res->assertJsonPath('data.trending_meta.pairs_seen', 3);
        $res->assertJsonPath('data.trending_meta.unique_candidates', 2);
        $res->assertJsonPath('data.trending_meta.slugs_used', ['cat', 'dog']);
        $res->assertJsonPath('data.sources.trending_meta', 2);
        $res->assertJsonPath('data.chains.solana', 1);
        $res->assertJsonPath('data.chains.base', 1);
        $res->assertJsonPath('data.discovery.pre_filtered_candidates', 2);
    }

    #[Test]
    public function historical_evidence_stays_separate_and_no_external_lookup_for_current_observation(): void
    {
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'Obs', ['marketCap' => 30_000_000.0])]);

        $this->discoverResult();

        $token = Token::query()->firstOrFail();
        $evidence = $token->historicalPeakEvidence()->firstOrFail();
        $this->assertSame(HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, $evidence->status);
        $this->assertSame(30_000_000.0, $evidence->peak_value_usd);
        // observed peak column is the same value, but kept in its own field.
        $this->assertSame(30_000_000.0, $token->observed_peak_market_cap);
        $this->assertNull($token->historical_estimate_fdv_usd);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'coingecko') || str_contains($r->url(), 'geckoterminal'));
    }

    #[Test]
    public function trending_meta_can_be_disabled_leaving_only_activity_feeds(): void
    {
        config()->set('dexscreener.discovery_sources.trending_meta_enabled', false);
        $this->fx['profiles'] = [['chainId' => 'solana', 'tokenAddress' => 'ProfOnly']];
        $this->fx['tokenPairs']['solana:profonly'] = [$this->metaPair('solana', 'ProfOnly', ['marketCap' => 10_000_000.0])];
        $this->meta('cat', 'Cat', [$this->metaPair('solana', 'MetaTok', ['marketCap' => 10_000_000.0])]);

        $d = $this->diagnostics();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/metas/'));
        $this->assertSame(0, $d['trending_meta_count']);
        $this->assertSame(1, $d['unique_candidates']); // ProfOnly
        $this->assertSame(1, $d['discovery_source_counts']['profile']);
    }
}
