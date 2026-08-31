<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * Step 14 — discovery coverage strategy (search-term engine, candidate cap,
 * deterministic prioritization, coverage diagnostics, discovery-status API).
 * DexScreener is fully HTTP-faked with a query-aware search stub.
 */
class DiscoveryCoverageTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    /** @var list<string> terms actually sent to /latest/dex/search */
    private array $searchCalls = [];

    /** @var list<string> token-pairs enrichment paths, in call order */
    private array $enrichCalls = [];

    /** @var array<string,mixed> */
    private array $fx = [
        'profiles' => [],
        'latestBoosts' => [],
        'topBoosts' => [],
        'metas' => [],
        /** term => list<pair> */
        'search' => [],
        /** "chain:addr" => list<pair> */
        'tokenPairs' => [],
    ];

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
        config()->set('dexscreener.cache.discovery_ttl', 0);
        config()->set('dexscreener.cache.enrichment_ttl', 0);
        config()->set('dexscreener.http.retries', 0);
        config()->set('dexscreener.http.retry_sleep_ms', 0);
        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('dexscreener.limits.max_candidates_to_enrich', 120);
        config()->set('dexscreener.limits.discovery_candidate_cap', 500);
        config()->set('historical.coingecko.enabled', false);
        config()->set('historical.geckoterminal.enabled', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- fakes ----------------------------------------------------------

    private function bootFakes(): void
    {
        Http::fake([
            'api.dexscreener.com/token-profiles/latest/v1' => fn () => Http::response($this->fx['profiles']),
            'api.dexscreener.com/token-boosts/latest/v1' => fn () => Http::response($this->fx['latestBoosts']),
            'api.dexscreener.com/token-boosts/top/v1' => fn () => Http::response($this->fx['topBoosts']),
            'api.dexscreener.com/metas/trending/v1' => fn () => Http::response($this->fx['metas']),
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

    /**
     * @param  array<string,mixed>  $o
     * @return array<string,mixed>
     */
    private function pair(string $chain, string $addr, array $o = []): array
    {
        return array_replace([
            'chainId' => $chain,
            'dexId' => 'raydium',
            'pairAddress' => 'P-'.substr(md5($chain.$addr), 0, 10),
            'baseToken' => ['address' => $addr, 'name' => ucfirst($addr), 'symbol' => strtoupper(substr($addr, 0, 4))],
            'quoteToken' => ['address' => 'Q', 'symbol' => 'SOL'],
            'priceUsd' => '0.01',
            'liquidity' => ['usd' => 200_000.0],
            'volume' => ['h24' => 40_000.0],
            'priceChange' => ['h24' => 1.0],
            'txns' => ['h24' => ['buys' => 5, 'sells' => 5]],
            'marketCap' => 6_000_000.0,
            'fdv' => 6_000_000.0,
            'pairCreatedAt' => $this->now->subDays(10)->getTimestampMs(),
        ], $o);
    }

    /** Register a discoverable token: appears for each search term + optional enrichment pair. */
    private function token(string $chain, string $addr, array $terms = [], array $pairOverride = []): void
    {
        foreach ($terms as $t) {
            $this->fx['search'][$t][] = $this->pair($chain, $addr);
        }
        $this->fx['tokenPairs'][mb_strtolower("$chain:$addr")] = [$this->pair($chain, $addr, $pairOverride)];
    }

    private function discover(?int $limit = null): array
    {
        $this->bootFakes();

        return app(DexScreenerDiscoveryService::class)
            ->discover(null, $limit, IngestionRun::TRIGGER_MANUAL)
            ->diagnostics;
    }

    // ---- tests ---------------------------------------------------------

    #[Test]
    public function core_search_terms_are_used(): void
    {
        config()->set('dexscreener.search_terms', ['Pepe', 'doge', 'CAT']);
        $d = $this->discover();

        $this->assertEqualsCanonicalizing(['pepe', 'doge', 'cat'], $this->searchCalls);
        $this->assertSame(3, $d['search_term_categories']['core']);
        $this->assertSame(3, $d['search_terms_used']);
    }

    #[Test]
    public function trending_meta_terms_are_incorporated(): void
    {
        config()->set('dexscreener.search_terms', ['pepe']);
        config()->set('dexscreener.trending_meta_terms', 3);
        $this->fx['metas'] = [
            ['slug' => 'ai-agents', 'name' => 'AI Agents'],
            ['slug' => 'dog-coins', 'name' => 'Dog Coins'],
        ];

        $d = $this->discover();

        $this->assertContains('ai-agents', $this->searchCalls);
        $this->assertContains('dog-coins', $this->searchCalls);
        $this->assertContains('ai agents', $this->searchCalls);
        $this->assertContains('dog coins', $this->searchCalls);
        $this->assertGreaterThanOrEqual(2, $d['search_term_categories']['meta_slug']);
        $this->assertGreaterThanOrEqual(2, $d['search_term_categories']['meta_name']);
        // deterministic priority: core, then meta slugs, then meta names.
        $this->assertSame('pepe', $this->searchCalls[0]);
        $this->assertSame(['ai-agents', 'dog-coins'], array_slice($this->searchCalls, 1, 2));
    }

    #[Test]
    public function duplicate_search_terms_are_removed(): void
    {
        config()->set('dexscreener.search_terms', ['pepe', 'PEPE', ' pepe ', 'doge']);
        config()->set('dexscreener.trending_meta_terms', 2);
        $this->fx['metas'] = [['slug' => 'pepe', 'name' => 'Doge']];

        $this->discover();

        $this->assertSame(['pepe', 'doge'], $this->searchCalls);
    }

    #[Test]
    public function the_term_budget_is_respected(): void
    {
        config()->set('dexscreener.search_terms', array_map(fn ($i) => "term{$i}", range(1, 40)));
        config()->set('dexscreener.search.term_budget', 10);

        $d = $this->discover();

        $this->assertCount(10, $this->searchCalls);
        $this->assertSame(10, $d['search_terms_used']);
        $this->assertSame(10, $d['search_term_budget']);
    }

    #[Test]
    public function the_candidate_cap_is_respected(): void
    {
        config()->set('dexscreener.limits.discovery_candidate_cap', 3);
        config()->set('dexscreener.search_terms', ['pepe']);
        foreach (range(1, 8) as $i) {
            $this->token('solana', "capaddr{$i}", ['pepe']);
        }

        $d = $this->discover();

        $this->assertSame(8, $d['unique_candidates']);
        $this->assertSame(5, $d['candidate_cap_dropped']);
        $this->assertSame(3, $d['candidates_considered']);
        $this->assertSame(3, $d['selected_for_enrichment']);
        $this->assertCount(3, $this->enrichCalls);
    }

    #[Test]
    public function multiple_discovery_sources_are_unioned_on_a_candidate(): void
    {
        config()->set('dexscreener.search_terms', ['pepe']);
        $addr = 'MultiSrc1';
        $this->fx['profiles'] = [['chainId' => 'solana', 'tokenAddress' => $addr]];
        $this->fx['latestBoosts'] = [['chainId' => 'solana', 'tokenAddress' => $addr]];
        $this->token('solana', $addr, ['pepe'], ['marketCap' => 9_000_000.0]);

        $this->bootFakes();
        /** @var DiscoveryResult $result */
        $result = app(DexScreenerDiscoveryService::class)
            ->discover(null, 20, IngestionRun::TRIGGER_MANUAL);

        $this->assertCount(1, $result->candidates);
        $sources = $result->candidates[0]->toArray()['sources'];
        $this->assertEqualsCanonicalizing(['profile', 'boost', 'search'], $sources);
    }

    #[Test]
    public function source_counts_are_correct(): void
    {
        config()->set('dexscreener.search_terms', ['pepe']);
        $this->fx['profiles'] = [
            ['chainId' => 'solana', 'tokenAddress' => 'ProfA'],
            ['chainId' => 'solana', 'tokenAddress' => 'ProfB'],
            ['chainId' => 'solana', 'tokenAddress' => 'Both1'],
        ];
        $this->fx['latestBoosts'] = [['chainId' => 'solana', 'tokenAddress' => 'BoostA']];
        $this->token('solana', 'SearchA', ['pepe']);
        $this->token('solana', 'SearchB', ['pepe']);
        $this->token('solana', 'SearchC', ['pepe']);
        $this->token('solana', 'Both1', ['pepe']); // profile + search

        $d = $this->discover();

        $this->assertSame(3, $d['discovery_source_counts']['profile']); // ProfA, ProfB, Both1
        $this->assertSame(1, $d['discovery_source_counts']['boost']);   // BoostA
        $this->assertSame(4, $d['discovery_source_counts']['search']);  // SearchA/B/C, Both1
        $this->assertSame(7, $d['unique_candidates']);                  // 3 + 1 + 3 distinct
    }

    #[Test]
    public function chain_diagnostics_reflect_candidates_actually_seen(): void
    {
        config()->set('dexscreener.search_terms', ['pepe']);
        $this->token('solana', 'S1', ['pepe']);
        $this->token('solana', 'S2', ['pepe']);
        $this->token('solana', 'S3', ['pepe']);
        $this->token('base', 'B1', ['pepe']);
        $this->token('base', 'B2', ['pepe']);
        $this->token('ethereum', 'E1', ['pepe']);

        $d = $this->discover();

        $this->assertSame(['solana' => 3, 'base' => 2, 'ethereum' => 1], $d['chains_discovered']);
        $this->assertArrayNotHasKey('bsc', $d['chains_discovered']);
    }

    #[Test]
    public function candidate_prioritization_is_deterministic_and_signal_ordered(): void
    {
        config()->set('dexscreener.search_terms', ['pepe']);
        config()->set('dexscreener.limits.discovery_candidate_cap', 4);

        // B fresher profile than A; A also has a search hit => 2 sources.
        $this->fx['profiles'] = [
            ['chainId' => 'solana', 'tokenAddress' => 'B'], // rank 0 (freshest)
            ['chainId' => 'solana', 'tokenAddress' => 'A'], // rank 1
        ];
        $this->token('solana', 'A', ['pepe']);                        // profile + search => 2 sources
        $this->token('solana', 'C', ['pepe']);                        // search only
        $this->fx['search']['pepe'][] = $this->pair('solana', 'C');    // C: 2 search hits
        $this->token('solana', 'D', ['pepe']);                        // search only, 1 hit

        $d = $this->discover();

        // Deterministic (no randomness / no time dependence): fixed inputs ->
        // fixed order. A(2 sources) > B(fresh profile) > C(2 search hits) > D(1).
        $this->assertSame([
            '/token-pairs/v1/solana/A',
            '/token-pairs/v1/solana/B',
            '/token-pairs/v1/solana/C',
            '/token-pairs/v1/solana/D',
        ], $this->enrichCalls);
        $this->assertSame(4, $d['candidates_considered']);
    }

    #[Test]
    public function a_repeated_candidate_is_enriched_once(): void
    {
        config()->set('dexscreener.search_terms', ['pepe', 'dog', 'cat']);
        $addr = 'RepeatMe';
        $this->fx['profiles'] = [['chainId' => 'solana', 'tokenAddress' => $addr]];
        $this->fx['latestBoosts'] = [['chainId' => 'solana', 'tokenAddress' => $addr]];
        $this->token('solana', $addr, ['pepe', 'dog', 'cat']);

        $d = $this->discover();

        $this->assertSame(1, $d['unique_candidates']);
        $this->assertCount(1, $this->enrichCalls);
        $this->assertSame(1, Token::query()->where('token_address', $addr)->count());
    }

    #[Test]
    public function the_final_limit_does_not_shrink_the_discovery_pool(): void
    {
        config()->set('dexscreener.search_terms', ['pepe']);
        foreach (range(1, 6) as $i) {
            $this->token('solana', "poolAddr{$i}", ['pepe'], ['marketCap' => 9_000_000.0]);
        }

        $small = $this->discover(1);
        $small['returned_count'] = $small['returned'];

        $this->searchCalls = [];
        $this->enrichCalls = [];
        $large = $this->discover(50);

        $this->assertSame($large['unique_candidates'], $small['unique_candidates']);
        $this->assertSame($large['selected_for_enrichment'], $small['selected_for_enrichment']);
        $this->assertSame($large['age_eligible'], $small['age_eligible']);
        $this->assertSame(6, $small['unique_candidates']);
        $this->assertSame(1, $small['returned']);
        $this->assertGreaterThan(1, $large['returned']);
    }

    #[Test]
    public function empty_search_terms_are_handled(): void
    {
        config()->set('dexscreener.search_terms', []);
        config()->set('dexscreener.search.ecosystem_terms', []);
        config()->set('dexscreener.trending_meta_terms', 0);
        $this->fx['profiles'] = [['chainId' => 'solana', 'tokenAddress' => 'ProfOnly']];
        $this->token('solana', 'ProfOnly'); // enrichment pair only

        $d = $this->discover();

        $this->assertSame(0, $d['search_terms_used']);
        $this->assertSame([], $this->searchCalls);
        $this->assertSame(1, $d['unique_candidates']);
    }

    #[Test]
    public function search_result_and_empty_term_counts_are_correct(): void
    {
        config()->set('dexscreener.search_terms', ['pepe', 'doge', 'ghost']);
        $this->token('solana', 'PepeTok', ['pepe']);
        $this->token('solana', 'PepeTok2', ['pepe']);
        // 'doge' and 'ghost' return nothing.

        $d = $this->discover();

        $this->assertSame(3, $d['search_terms_used']);
        $this->assertSame(1, $d['search_terms_with_results']);
        $this->assertSame(2, $d['search_terms_empty']);
    }

    #[Test]
    public function the_discovery_status_endpoint_is_read_only(): void
    {
        IngestionRun::query()->create([
            'started_at' => $this->now->subMinutes(5),
            'completed_at' => $this->now->subMinutes(4),
            'status' => IngestionRun::STATUS_COMPLETED,
            'trigger' => IngestionRun::TRIGGER_SCHEDULED,
            'raw_candidates' => 500,
            'unique_candidates' => 420,
            'selected_for_enrichment' => 120,
            'candidate_cap_dropped' => 0,
            'age_eligible' => 60,
            'qualified' => 4,
            'search_terms_used' => 25,
            'search_terms_with_results' => 21,
            'chains_discovered' => ['solana' => 210, 'base' => 80, 'ethereum' => 40],
        ]);

        $runsBefore = IngestionRun::query()->count();
        $tokensBefore = Token::query()->count();

        $res = $this->getJson('/api/memecoins/discovery-status')->assertOk();

        $res->assertJsonPath('data.latest_run.trigger', 'scheduled');
        $res->assertJsonPath('data.latest_run.status', 'completed');
        $res->assertJsonPath('data.discovery.raw_candidates', 500);
        $res->assertJsonPath('data.discovery.selected_for_enrichment', 120);
        $res->assertJsonPath('data.discovery.search_terms_used', 25);
        $res->assertJsonPath('data.chains.solana', 210);
        $res->assertJsonPath('data.chains.base', 80);
        $res->assertJsonPath('meta.source', 'ingestion_runs');

        $this->assertSame($runsBefore, IngestionRun::query()->count());
        $this->assertSame($tokensBefore, Token::query()->count());
    }

    #[Test]
    public function the_discovery_status_endpoint_never_calls_dexscreener(): void
    {
        Http::preventStrayRequests();
        Http::fake(); // record everything; nothing should be sent

        IngestionRun::query()->create([
            'started_at' => $this->now,
            'status' => IngestionRun::STATUS_RUNNING,
            'trigger' => IngestionRun::TRIGGER_MANUAL,
        ]);

        $this->getJson('/api/memecoins/discovery-status')->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_discovery_status_endpoint_handles_no_runs(): void
    {
        $this->getJson('/api/memecoins/discovery-status')->assertOk()
            ->assertJsonPath('data.latest_run', null)
            ->assertJsonPath('data.discovery', null);
    }
}
