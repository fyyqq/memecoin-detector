<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IngestionRun;
use App\Models\Token;
use App\Services\DexScreener\DexScreenerNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemecoinDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    /** Mutable HTTP fixture read by the (once-registered) fakes. */
    private array $fx = [
        'profiles' => [],
        'latestBoosts' => [],
        'topBoosts' => [],
        'metas' => [],
        'searchPairs' => [],
        'tokenPairs' => [],
    ];

    private bool $httpFaked = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-28T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);

        Http::preventStrayRequests();

        config()->set('dexscreener.base_url', 'https://api.dexscreener.com');
        config()->set('dexscreener.search_terms', ['pepe']);
        config()->set('dexscreener.search.ecosystem_terms', []);
        config()->set('dexscreener.search.term_budget', 25);
        config()->set('dexscreener.trending_meta_terms', 0);
        config()->set('dexscreener.limits.discovery_candidate_cap', 500);
        config()->set('dexscreener.cache.discovery_ttl', 0);
        config()->set('dexscreener.cache.enrichment_ttl', 0);
        config()->set('dexscreener.http.retries', 0);
        config()->set('dexscreener.http.retry_sleep_ms', 0);
        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);

        // These tests exercise the keyword-search discovery path (the Step 19
        // fallback). Turn trending-meta discovery off + keyword on.
        config()->set('dexscreener.discovery_sources.trending_meta_enabled', false);
        config()->set('dexscreener.discovery_sources.profiles_enabled', true);
        config()->set('dexscreener.discovery_sources.boosts_enabled', true);
        config()->set('dexscreener.discovery_sources.keyword_enabled', true);

        // Historical qualification has its own test; keep these pipeline tests
        // focused on the DexScreener / observed-peak path (and offline).
        config()->set('historical.coingecko.enabled', false);
        config()->set('historical.geckoterminal.enabled', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function pair(string $chainId, string $tokenAddress, array $overrides = []): array
    {
        return array_replace([
            'chainId' => $chainId,
            'dexId' => 'raydium',
            'pairAddress' => 'PAIR-'.substr(md5($chainId.$tokenAddress.serialize($overrides)), 0, 10),
            'baseToken' => ['address' => $tokenAddress, 'name' => ucfirst($tokenAddress), 'symbol' => 'TKN'],
            'quoteToken' => ['address' => 'QUOTE', 'symbol' => 'SOL'],
            'priceUsd' => '0.01',
            'liquidity' => ['usd' => 250_000.0],
            'volume' => ['h24' => 50_000.0],
            'priceChange' => ['h24' => 3.3],
            'txns' => ['h24' => ['buys' => 20, 'sells' => 12]],
            'fdv' => 6_000_000.0,
            'marketCap' => 6_000_000.0,
            'pairCreatedAt' => $this->now->subDays(10)->getTimestampMs(),
        ], $overrides);
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $tokenPairs  keyed by "chain:address"
     * @param  array<string,mixed>  $extra
     */
    private function fakeDexScreener(array $tokenPairs, array $extra = []): void
    {
        $this->fx['tokenPairs'] = $tokenPairs;
        $this->fx['profiles'] = $extra['profiles'] ?? [];
        $this->fx['latestBoosts'] = $extra['latestBoosts'] ?? [];
        $this->fx['topBoosts'] = $extra['topBoosts'] ?? [];
        $this->fx['metas'] = $extra['metas'] ?? [];
        $this->fx['searchPairs'] = $extra['searchPairs'] ?? array_map(function (string $key): array {
            [$chain, $addr] = explode(':', $key, 2);

            return [
                'chainId' => $chain,
                'baseToken' => ['address' => $addr, 'symbol' => 'TKN'],
                'pairAddress' => 'SEARCH-'.substr(md5($key), 0, 8),
            ];
        }, array_keys($tokenPairs));

        // Register the fakes once; the closures read the mutable fixture above,
        // so repeated calls within a test change the responses (re-calling
        // Http::fake() would merge stubs and the first match would always win).
        if ($this->httpFaked) {
            return;
        }

        $this->httpFaked = true;

        Http::fake([
            'api.dexscreener.com/token-profiles/latest/v1' => fn () => Http::response($this->fx['profiles']),
            'api.dexscreener.com/token-boosts/latest/v1' => fn () => Http::response($this->fx['latestBoosts']),
            'api.dexscreener.com/token-boosts/top/v1' => fn () => Http::response($this->fx['topBoosts']),
            'api.dexscreener.com/metas/trending/v1' => fn () => Http::response($this->fx['metas']),
            'api.dexscreener.com/latest/dex/search*' => fn () => Http::response([
                'schemaVersion' => '1.0.0',
                'pairs' => $this->fx['searchPairs'],
            ]),
            'api.dexscreener.com/token-pairs/v1/*' => function (Request $request) {
                $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
                $segments = array_values(array_filter(explode('/', $path)));
                $chain = strtolower($segments[count($segments) - 2] ?? '');
                $addr = strtolower($segments[count($segments) - 1] ?? '');

                foreach ($this->fx['tokenPairs'] as $key => $pairs) {
                    if (strtolower($key) === "{$chain}:{$addr}") {
                        return Http::response($pairs);
                    }
                }

                return Http::response([]);
            },
        ]);
    }

    private function discover(string $query = ''): TestResponse
    {
        return $this->getJson('/api/memecoins/discover'.($query !== '' ? "?{$query}" : ''))->assertOk();
    }

    // --------------------------------------------------------------------

    #[Test]
    public function health_endpoint_still_works(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function current_market_cap_at_or_above_threshold_creates_a_qualified_token(): void
    {
        $addr = 'good1111111111111111111111111111111111111111';
        $this->fakeDexScreener([
            "solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_000_000.0])],
        ]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.token_key', "solana:{$addr}");
        $res->assertJsonPath('data.0.current_market_cap', fn ($v) => (float) $v === 6_000_000.0);
        $res->assertJsonPath('data.0.observed_peak_market_cap', fn ($v) => (float) $v === 6_000_000.0);
        $res->assertJsonPath('data.0.observed_since', $this->now->toIso8601String());
        $res->assertJsonPath('meta.filters.observed_peak_market_cap_min_usd', 5000000);
        $res->assertJsonPath('meta.ingestion_run_id', fn ($v) => is_int($v) && $v > 0);
        $res->assertJsonPath('meta.retrieved_at', $this->now->toIso8601String());

        $token = Token::query()->firstOrFail();
        $this->assertSame('solana', $token->chain_id);
        $this->assertSame(6_000_000.0, $token->observed_peak_market_cap);
        $this->assertDatabaseCount('market_snapshots', 1);
        $this->assertDatabaseHas('ingestion_runs', ['trigger' => 'manual', 'status' => 'completed']);
    }

    #[Test]
    public function manual_discovery_records_a_completed_ingestion_run_with_counts(): void
    {
        $addr = 'runrec00000000000000000000000000000000000000';
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_000_000.0])]]);

        $runId = $this->discover()->json('meta.ingestion_run_id');

        $run = IngestionRun::query()->findOrFail($runId);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(1, $run->age_eligible);
        $this->assertSame(1, $run->snapshots_written);
        $this->assertSame(1, $run->new_tokens);
        $this->assertSame(1, $run->qualified);
    }

    #[Test]
    public function an_unexpected_pipeline_failure_returns_a_safe_json_error_and_records_a_failed_run(): void
    {
        $addr = 'boom00000000000000000000000000000000000000000';
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_000_000.0])]]);

        $this->mock(DexScreenerNormalizer::class)
            ->shouldReceive('normalize')
            ->andThrow(new \RuntimeException('normalize exploded'));

        $res = $this->getJson('/api/memecoins/discover')->assertStatus(503);
        $res->assertJsonPath('error', 'Discovery run failed. See ingestion_runs for details.');
        $this->assertArrayNotHasKey('trace', $res->json());
        $this->assertArrayNotHasKey('exception', $res->json());

        $run = IngestionRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertStringContainsString('normalize exploded', (string) $run->error_message);
    }

    #[Test]
    public function a_new_token_currently_at_seven_million_qualifies_immediately(): void
    {
        $addr = 'seven222222222222222222222222222222222222222';
        $this->fakeDexScreener([
            "solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 7_000_000.0])],
        ]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('meta.diagnostics.qualified', 1);
        $res->assertJsonPath('meta.diagnostics.qualified_from_current_observation', 1);
        $res->assertJsonPath('meta.diagnostics.new_tokens', 1);
    }

    #[Test]
    public function current_below_threshold_but_stored_observed_peak_above_stays_qualified(): void
    {
        $addr = 'peak3333333333333333333333333333333333333333';

        // First observation: $8M — sets the peak, qualifies.
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 8_000_000.0])]]);
        $this->discover()->assertJsonPath('meta.count', 1);

        // Second observation: current MC drops to $2M.
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 2_000_000.0])]]);
        $res = $this->discover();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.current_market_cap', fn ($v) => (float) $v === 2_000_000.0);
        $res->assertJsonPath('data.0.observed_peak_market_cap', fn ($v) => (float) $v === 8_000_000.0);

        $this->assertDatabaseCount('tokens', 1);
        $this->assertDatabaseCount('market_snapshots', 2);
        $this->assertSame(8_000_000.0, Token::query()->firstOrFail()->observed_peak_market_cap);
    }

    #[Test]
    public function current_below_threshold_and_observed_peak_below_threshold_is_not_qualified(): void
    {
        $addr = 'low44444444444444444444444444444444444444444';
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 3_000_000.0])]]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 0);
        $res->assertJsonPath('meta.diagnostics.not_qualified', 1);
        $res->assertJsonPath('meta.diagnostics.observed_peak_below_threshold', 1);
        $res->assertJsonPath('meta.diagnostics.age_eligible', 1);
        // The observation is still persisted.
        $this->assertDatabaseCount('tokens', 1);
        $this->assertDatabaseCount('market_snapshots', 1);
        $res->assertJsonPath('meta.not_qualified_sample.0.reason', 'observed_peak_below_threshold');
    }

    #[Test]
    public function a_later_lower_observation_does_not_reduce_the_observed_peak(): void
    {
        $addr = 'nope5555555555555555555555555555555555555555';

        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 9_000_000.0])]]);
        $this->discover();
        $peakAt = Token::query()->firstOrFail()->observed_peak_market_cap_at;

        CarbonImmutable::setTestNow($this->now->addHours(3));
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 4_000_000.0])]]);
        $this->discover();

        $token = Token::query()->firstOrFail();
        $this->assertSame(9_000_000.0, $token->observed_peak_market_cap);
        $this->assertSame($peakAt?->toDateTimeString(), $token->observed_peak_market_cap_at?->toDateTimeString());
    }

    #[Test]
    public function a_later_higher_observation_updates_the_observed_peak(): void
    {
        $addr = 'rise6666666666666666666666666666666666666666';

        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_000_000.0])]]);
        $this->discover();

        $later = $this->now->addHours(5);
        CarbonImmutable::setTestNow($later);
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 15_000_000.0])]]);
        $res = $this->discover();

        $res->assertJsonPath('meta.diagnostics.peak_updated', 1);

        $token = Token::query()->firstOrFail();
        $this->assertSame(15_000_000.0, $token->observed_peak_market_cap);
        $this->assertSame($later->toDateTimeString(), $token->observed_peak_market_cap_at?->toDateTimeString());
    }

    #[Test]
    public function null_market_cap_does_not_erase_a_previous_peak(): void
    {
        $addr = 'null7777777777777777777777777777777777777777';

        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 8_000_000.0])]]);
        $this->discover();

        CarbonImmutable::setTestNow($this->now->addHours(2));
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => null])]]);
        $res = $this->discover();

        // Still qualified from stored peak.
        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.current_market_cap', null);
        $res->assertJsonPath('meta.diagnostics.market_cap_unknown', 1);

        $token = Token::query()->firstOrFail();
        $this->assertSame(8_000_000.0, $token->observed_peak_market_cap);
        $this->assertNull($token->marketSnapshots()->latest('id')->first()?->market_cap);
    }

    #[Test]
    public function fdv_never_substitutes_for_market_cap(): void
    {
        $addr = 'fdv88888888888888888888888888888888888888888';
        $this->fakeDexScreener([
            "solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => null, 'fdv' => 25_000_000.0])],
        ]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 0);
        $res->assertJsonPath('meta.diagnostics.market_cap_unknown', 1);
        $res->assertJsonPath('meta.diagnostics.not_qualified', 1);
        $res->assertJsonPath('meta.not_qualified_sample.0.reason', 'insufficient_historical_observation');
        $res->assertJsonPath('meta.not_qualified_sample.0.fdv', fn ($v) => (float) $v === 25_000_000.0);

        $token = Token::query()->firstOrFail();
        $this->assertNull($token->observed_peak_market_cap);
        $this->assertNull($token->marketSnapshots()->firstOrFail()->market_cap);
        $this->assertSame(25_000_000.0, $token->marketSnapshots()->firstOrFail()->fdv);
    }

    #[Test]
    public function a_token_older_than_thirty_days_is_excluded_even_with_a_high_observed_peak(): void
    {
        $addr = 'old99999999999999999999999999999999999999999';
        $this->fakeDexScreener([
            "solana:{$addr}" => [
                $this->pair('solana', $addr, [
                    'marketCap' => 50_000_000.0,
                    'pairCreatedAt' => $this->now->subDays(45)->getTimestampMs(),
                ]),
            ],
        ]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 0);
        $res->assertJsonPath('meta.diagnostics.older_than_max_age', 1);
        // Age-ineligible tokens are not persisted.
        $this->assertDatabaseCount('tokens', 0);
        $this->assertDatabaseCount('market_snapshots', 0);
    }

    #[Test]
    public function null_pair_created_at_is_rejected_as_age_unknown_and_not_persisted(): void
    {
        $addr = 'nodateaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $this->fakeDexScreener([
            "solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 9_000_000.0, 'pairCreatedAt' => null])],
        ]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 0);
        $res->assertJsonPath('meta.diagnostics.age_unknown', 1);
        $this->assertDatabaseCount('tokens', 0);
    }

    #[Test]
    public function duplicate_discovery_of_the_same_chain_and_address_does_not_create_a_duplicate_token(): void
    {
        $addr = 'dupebbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_000_000.0])]]);

        $this->discover();
        CarbonImmutable::setTestNow($this->now->addHour());
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_500_000.0])]]);
        $this->discover();

        $this->assertDatabaseCount('tokens', 1);
        $this->assertDatabaseCount('market_snapshots', 2);
    }

    #[Test]
    public function two_tokens_with_the_same_symbol_on_different_chains_stay_separate(): void
    {
        $a = 'pepeaaaa00000000000000000000000000000000000000';
        $b = 'pepebbbb11111111111111111111111111111111111111';

        $this->fakeDexScreener([
            "ethereum:{$a}" => [$this->pair('ethereum', $a, [
                'marketCap' => 6_000_000.0,
                'baseToken' => ['address' => $a, 'name' => 'Pepe One', 'symbol' => 'PEPE'],
            ])],
            "base:{$b}" => [$this->pair('base', $b, [
                'marketCap' => 7_000_000.0,
                'baseToken' => ['address' => $b, 'name' => 'Pepe Two', 'symbol' => 'PEPE'],
            ])],
        ]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 2);
        $this->assertDatabaseCount('tokens', 2);
        $this->assertSame(1, Token::query()->where('chain_id', 'ethereum')->count());
        $this->assertSame(1, Token::query()->where('chain_id', 'base')->count());
    }

    #[Test]
    public function snapshot_rows_preserve_each_observation(): void
    {
        $addr = 'histccccccccccccccccccccccccccccccccccccccccc';

        foreach ([['mc' => 6_000_000.0, 'p' => '0.01'], ['mc' => 9_000_000.0, 'p' => '0.02'], ['mc' => 4_000_000.0, 'p' => '0.008']] as $i => $obs) {
            CarbonImmutable::setTestNow($this->now->addHours($i));
            $this->fakeDexScreener(["solana:{$addr}" => [
                $this->pair('solana', $addr, ['marketCap' => $obs['mc'], 'priceUsd' => $obs['p']]),
            ]]);
            $this->discover();
        }

        $token = Token::query()->firstOrFail();
        $snapshots = $token->marketSnapshots()->orderBy('observed_at')->get();

        $this->assertCount(3, $snapshots);
        $this->assertSame([6_000_000.0, 9_000_000.0, 4_000_000.0], $snapshots->pluck('market_cap')->all());
        $this->assertSame(9_000_000.0, $token->observed_peak_market_cap);
    }

    #[Test]
    public function observed_peak_market_cap_at_matches_the_observation_that_set_the_peak(): void
    {
        $addr = 'peakatdddddddddddddddddddddddddddddddddddddd';
        $t0 = $this->now;
        $t1 = $this->now->addHours(1);
        $t2 = $this->now->addHours(2);

        CarbonImmutable::setTestNow($t0);
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_000_000.0])]]);
        $this->discover();

        CarbonImmutable::setTestNow($t1);
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 12_000_000.0])]]);
        $this->discover();

        CarbonImmutable::setTestNow($t2);
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 8_000_000.0])]]);
        $this->discover();

        $token = Token::query()->firstOrFail();
        $this->assertSame(12_000_000.0, $token->observed_peak_market_cap);
        $this->assertSame($t1->toDateTimeString(), $token->observed_peak_market_cap_at?->toDateTimeString());
    }

    #[Test]
    public function first_observed_at_is_preserved_and_does_not_move_forward(): void
    {
        $addr = 'firsteeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $t0 = $this->now;
        $t1 = $this->now->addHours(6);

        CarbonImmutable::setTestNow($t0);
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_000_000.0])]]);
        $this->discover();

        CarbonImmutable::setTestNow($t1);
        $this->fakeDexScreener(["solana:{$addr}" => [$this->pair('solana', $addr, ['marketCap' => 6_000_000.0])]]);
        $this->discover();

        $token = Token::query()->firstOrFail();
        $this->assertSame($t0->toDateTimeString(), $token->first_observed_at?->toDateTimeString());
        $this->assertSame($t1->toDateTimeString(), $token->last_observed_at?->toDateTimeString());
    }

    #[Test]
    public function earliest_pair_created_at_is_the_minimum_across_pairs(): void
    {
        $addr = 'multipairfffffffffffffffffffffffffffffffffff';
        $old = $this->now->subDays(20)->getTimestampMs();
        $new = $this->now->subDays(2)->getTimestampMs();

        $this->fakeDexScreener([
            "solana:{$addr}" => [
                $this->pair('solana', $addr, ['pairCreatedAt' => $new, 'liquidity' => ['usd' => 10.0], 'marketCap' => 6_000_000.0]),
                $this->pair('solana', $addr, ['pairCreatedAt' => $old, 'liquidity' => ['usd' => 999_999.0], 'marketCap' => 6_000_000.0]),
            ],
        ]);

        $this->discover()->assertJsonPath('meta.count', 1);

        $token = Token::query()->firstOrFail();
        $this->assertSame(
            CarbonImmutable::createFromTimestampMs($old)->toDateTimeString(),
            $token->earliest_pair_created_at?->toDateTimeString(),
        );
    }

    #[Test]
    public function null_liquidity_across_pairs_does_not_fail_the_request(): void
    {
        $addr = 'noliqgggggggggggggggggggggggggggggggggggggggg';
        $this->fakeDexScreener([
            "solana:{$addr}" => [
                $this->pair('solana', $addr, ['liquidity' => ['usd' => null], 'pairAddress' => 'bbb', 'marketCap' => 9_000_000.0]),
                $this->pair('solana', $addr, ['liquidity' => null, 'pairAddress' => 'aaa', 'marketCap' => 9_000_000.0]),
            ],
        ]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.liquidity_usd', null);
    }

    #[Test]
    public function chain_filter_restricts_results_and_persistence(): void
    {
        $sol = 'solchain0000000000000000000000000000000000000';
        $eth = 'ethchain1111111111111111111111111111111111111';

        $this->fakeDexScreener([
            "solana:{$sol}" => [$this->pair('solana', $sol, ['marketCap' => 8_000_000.0])],
            "ethereum:{$eth}" => [$this->pair('ethereum', $eth, ['marketCap' => 8_000_000.0])],
        ]);

        $res = $this->discover('chain=solana');

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.chain_id', 'solana');
        $this->assertDatabaseCount('tokens', 1);
        $this->assertSame('solana', Token::query()->firstOrFail()->chain_id);
    }

    #[Test]
    public function limit_is_clamped_to_the_server_maximum(): void
    {
        config()->set('dexscreener.limits.max_result_limit', 5);
        $this->fakeDexScreener([]);

        $this->discover('limit=9999')->assertJsonPath('meta.limit', 5);
    }

    #[Test]
    public function provider_failure_does_not_500_the_endpoint(): void
    {
        Http::fake(['api.dexscreener.com/*' => Http::response('upstream boom', 503)]);

        $res = $this->discover();

        $res->assertJsonPath('meta.count', 0);
        $res->assertJsonPath('data', []);
        $this->assertDatabaseCount('tokens', 0);
    }

    #[Test]
    public function invalid_chain_parameter_is_rejected(): void
    {
        $this->fakeDexScreener([]);

        $this->getJson('/api/memecoins/discover?chain=not a chain!')->assertStatus(422);
    }

    #[Test]
    public function total_snapshots_written_is_reported_in_diagnostics(): void
    {
        $a = 'diagaaaa22222222222222222222222222222222222222';
        $b = 'diagbbbb33333333333333333333333333333333333333';

        $this->fakeDexScreener([
            "solana:{$a}" => [$this->pair('solana', $a, ['marketCap' => 8_000_000.0])],
            "solana:{$b}" => [$this->pair('solana', $b, ['marketCap' => 1_000_000.0])],
        ]);

        $res = $this->discover();

        $res->assertJsonPath('meta.diagnostics.age_eligible', 2);
        $res->assertJsonPath('meta.diagnostics.snapshots_written', 2);
        $res->assertJsonPath('meta.diagnostics.new_tokens', 2);
        $res->assertJsonPath('meta.diagnostics.qualified', 1);
        $res->assertJsonPath('meta.diagnostics.not_qualified', 1);
        $this->assertDatabaseCount('market_snapshots', 2);
    }
}
