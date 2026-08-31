<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\IngestionRun;
use App\Models\Token;
use App\Services\DexScreener\DexScreenerDiscoveryService;
use App\Services\Historical\HistoricalQualificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Historical qualification engine (Step 13C). CoinGecko + GeckoTerminal are
 * always HTTP-faked — no live calls.
 */
class HistoricalQualificationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-28T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('historical.min_peak_market_cap_usd', 5_000_000);
        config()->set('historical.lookup_cooldown_hours', 6);
        config()->set('historical.max_lookups_per_run', 15);
        config()->set('historical.coingecko.enabled', true);
        config()->set('historical.coingecko.base_url', 'https://api.coingecko.com/api/v3');
        config()->set('historical.coingecko.api_key', null);
        config()->set('historical.coingecko.cache_ttl', 0);
        config()->set('historical.coingecko.retry_sleep_ms', 0);
        config()->set('historical.coingecko.max_calls_per_run', 20);
        config()->set('historical.geckoterminal.enabled', true);
        config()->set('historical.geckoterminal.base_url', 'https://api.geckoterminal.com/api/v2');
        config()->set('historical.geckoterminal.cache_ttl', 0);
        config()->set('historical.geckoterminal.max_calls_per_run', 45);
        config()->set('historical.geckoterminal.estimate.allow_without_mint_signal', false);
        config()->set('historical.chain_map', [
            'solana' => ['coingecko' => 'solana', 'geckoterminal' => 'solana'],
            'ethereum' => ['coingecko' => 'ethereum', 'geckoterminal' => 'eth'],
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeToken(array $overrides = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.bin2hex(random_bytes(6)),
            'symbol' => 'TKN',
            'name' => 'Test Token',
            'earliest_pair_created_at' => $this->now->subDays(3),
            'first_observed_at' => $this->now->subDays(1),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 1_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subHours(6),
        ], $overrides));

        return $token;
    }

    private function service(): HistoricalQualificationService
    {
        return app(HistoricalQualificationService::class);
    }

    /**
     * @return array{evidence:array<int,HistoricalPeakEvidence>,stats:array<string,int>}
     */
    private function resolve(Token $token): array
    {
        return $this->service()->qualify(
            [['token' => $token, 'chain_id' => $token->chain_id, 'token_address' => $token->token_address]],
            $this->now,
        );
    }

    /**
     * Fake CoinGecko + GeckoTerminal. `$cg` and `$gt` are handler maps.
     *
     * @param  array<string,mixed>  $cg
     * @param  array<string,mixed>  $gt
     */
    private function fakeProviders(array $cg = [], array $gt = []): void
    {
        Http::fake([
            // --- CoinGecko ---
            'api.coingecko.com/api/v3/coins/*/contract/*' => fn () => $cg['contract'] ?? Http::response(['error' => 'coin not found'], 404),
            'api.coingecko.com/api/v3/coins/*/market_chart/range*' => fn () => $cg['market_chart'] ?? Http::response(['market_caps' => []]),

            // --- GeckoTerminal (order: specific before generic) ---
            'api.geckoterminal.com/api/v2/networks/*/tokens/*/pools' => fn () => $gt['pools'] ?? Http::response(['data' => []]),
            'api.geckoterminal.com/api/v2/networks/*/tokens/*/info' => fn () => $gt['info'] ?? Http::response(['data' => ['attributes' => []]]),
            'api.geckoterminal.com/api/v2/networks/*/pools/*/ohlcv/hour*' => fn () => $gt['ohlcv'] ?? Http::response(['data' => ['attributes' => ['ohlcv_list' => []]]]),
            'api.geckoterminal.com/api/v2/networks/*/tokens/*' => fn () => $gt['token'] ?? Http::response(['data' => ['attributes' => []]]),
        ]);
    }

    /** CoinGecko contract response with a coin id. */
    private function cgContract(string $coinId): \Closure
    {
        return fn () => Http::response(['id' => $coinId, 'symbol' => 'tkn']);
    }

    /**
     * CoinGecko market_chart with the given [ms, marketCap] points.
     *
     * @param  list<array{0:int,1:float}>  $points
     */
    private function cgMarketChart(array $points): \Closure
    {
        return fn () => Http::response(['prices' => [], 'market_caps' => $points, 'total_volumes' => []]);
    }

    private function gtPools(string $poolAddress, float $reserve = 500_000.0): \Closure
    {
        return fn () => Http::response(['data' => [[
            'attributes' => ['address' => $poolAddress, 'name' => 'TKN / USDC', 'reserve_in_usd' => (string) $reserve],
        ]]]);
    }

    /**
     * GeckoTerminal hourly OHLCV: rows of [ts, o, h, l, c, v].
     *
     * @param  list<array{0:int,1:float,2:float,3:float,4:float,5:float}>  $rows
     */
    private function gtOhlcv(array $rows): \Closure
    {
        return fn () => Http::response(['data' => ['attributes' => ['ohlcv_list' => $rows]]]);
    }

    private function gtToken(?float $normalizedTotalSupply): \Closure
    {
        $attr = [];
        if ($normalizedTotalSupply !== null) {
            $attr['normalized_total_supply'] = (string) $normalizedTotalSupply;
        }

        return fn () => Http::response(['data' => ['attributes' => $attr]]);
    }

    /** @param string|null $mintAuthority explicit null = immutable; string = mutable; omit key = unknown */
    private function gtInfo(?string $mintAuthority, bool $includeKey = true): \Closure
    {
        $attr = $includeKey ? ['mint_authority' => $mintAuthority] : [];

        return fn () => Http::response(['data' => ['attributes' => $attr]]);
    }

    // ---- tests ----------------------------------------------------------

    #[Test]
    public function current_mc_at_or_above_threshold_is_current_observation_immediately(): void
    {
        $this->fakeProviders(); // present but must not be called
        $token = $this->makeToken(['observed_peak_market_cap' => 8_000_000.0]);

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, $evidence->status);
        $this->assertSame(8_000_000.0, $evidence->peak_value_usd);
        $this->assertSame(HistoricalPeakEvidence::SOURCE_DEXSCREENER, $evidence->evidence_source);
        $this->assertSame(HistoricalPeakEvidence::BASIS_CURRENT_MARKET_CAP, $evidence->evidence_basis);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_token_already_at_threshold_does_not_call_historical_providers(): void
    {
        $this->fakeProviders();
        $token = $this->makeToken(['observed_peak_market_cap' => 6_000_000.0]);

        $stats = $this->resolve($token)['stats'];

        $this->assertSame(0, $stats['historical_lookups_performed']);
        Http::assertNothingSent();
    }

    #[Test]
    public function current_mc_below_threshold_triggers_a_historical_lookup(): void
    {
        $this->fakeProviders(); // both 404/empty -> UNKNOWN
        $token = $this->makeToken(['observed_peak_market_cap' => 1_000_000.0]);

        $stats = $this->resolve($token)['stats'];

        $this->assertSame(1, $stats['historical_lookups_performed']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'coingecko') || str_contains($r->url(), 'geckoterminal'));
    }

    #[Test]
    public function coingecko_verified_historical_market_cap_above_threshold_is_historical_verified(): void
    {
        $this->fakeProviders(cg: [
            'contract' => $this->cgContract('test-coin'),
            'market_chart' => $this->cgMarketChart([
                [$this->now->subDays(2)->getTimestampMs(), 8_200_000.0],
                [$this->now->subDay()->getTimestampMs(), 3_000_000.0],
            ]),
        ]);
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED, $evidence->status);
        $this->assertSame(8_200_000.0, $evidence->peak_value_usd);
        $this->assertSame(HistoricalPeakEvidence::SOURCE_COINGECKO, $evidence->evidence_source);
        $this->assertSame(HistoricalPeakEvidence::BASIS_MARKET_CAP, $evidence->evidence_basis);
        $this->assertSame('coingecko:test-coin', $evidence->source_reference);
        $this->assertSame($this->now->subDays(2)->toIso8601String(), $evidence->peak_observed_at?->toIso8601String());
    }

    #[Test]
    public function coingecko_404_falls_back_to_geckoterminal(): void
    {
        $this->fakeProviders(
            cg: [], // 404
            gt: [
                'pools' => $this->gtPools('Pool111'),
                'ohlcv' => $this->gtOhlcv([[$this->now->subDay()->getTimestamp(), 0.01, 0.02, 0.009, 0.011, 100.0]]),
                'token' => $this->gtToken(1_000_000_000.0),
                'info' => $this->gtInfo(null),
            ],
        );
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];

        // 0.02 * 1e9 = 20M -> estimate
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE, $evidence->status);
        $this->assertSame(HistoricalPeakEvidence::SOURCE_GECKOTERMINAL, $evidence->evidence_source);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/contract/'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'geckoterminal'));
    }

    #[Test]
    public function coingecko_zero_filled_market_caps_are_not_verified(): void
    {
        $this->fakeProviders(
            cg: [
                'contract' => $this->cgContract('zero-coin'),
                'market_chart' => $this->cgMarketChart([
                    [$this->now->subDays(2)->getTimestampMs(), 0.0],
                    [$this->now->subDay()->getTimestampMs(), 0.0],
                ]),
            ],
            gt: ['pools' => fn () => Http::response(['data' => []])], // no GT estimate either
        );
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $evidence->status);
        $this->assertStringContainsString('zero', (string) $evidence->notes);
    }

    #[Test]
    public function geckoterminal_estimate_with_safe_immutable_supply_above_threshold_is_historical_estimate(): void
    {
        $this->fakeProviders(gt: [
            'pools' => $this->gtPools('PoolABC'),
            'ohlcv' => $this->gtOhlcv([
                [$this->now->subDays(2)->getTimestamp(), 0.005, 0.008, 0.004, 0.006, 500.0],
                [$this->now->subDay()->getTimestamp(), 0.006, 0.007, 0.005, 0.0055, 400.0],
            ]),
            'token' => $this->gtToken(1_000_000_000.0), // 1e9
            'info' => $this->gtInfo(null), // mint_authority: null -> immutable
        ]);
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];

        // peak high 0.008 * 1e9 = 8M
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE, $evidence->status);
        $this->assertEqualsWithDelta(8_000_000.0, (float) $evidence->peak_value_usd, 1.0);
        $this->assertSame(HistoricalPeakEvidence::BASIS_FDV_TOTAL_SUPPLY, $evidence->evidence_basis);
        $this->assertSame('medium', $evidence->confidence);

        // Business rule: the estimate mirrors to the dedicated FDV column,
        // NEVER historical_peak_value, and never touches observed_peak_market_cap.
        $fresh = $token->fresh();
        $this->assertEqualsWithDelta(8_000_000.0, (float) $fresh?->historical_estimate_fdv_usd, 1.0);
        $this->assertNull($fresh?->historical_peak_value);
        $this->assertNull($fresh?->historical_peak_value_at);
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE, $fresh?->historical_peak_status);
        $this->assertSame(1_000_000.0, $fresh?->observed_peak_market_cap);
        $this->assertFalse($evidence->qualifies(5_000_000.0));
    }

    #[Test]
    public function a_verified_market_cap_mirrors_to_historical_peak_value_not_the_estimate_column(): void
    {
        $this->fakeProviders(cg: [
            'contract' => $this->cgContract('verified-mirror'),
            'market_chart' => $this->cgMarketChart([[$this->now->subDay()->getTimestampMs(), 9_000_000.0]]),
        ]);
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED, $evidence->status);

        $fresh = $token->fresh();
        $this->assertSame(9_000_000.0, $fresh?->historical_peak_value);
        $this->assertNull($fresh?->historical_estimate_fdv_usd);
        $this->assertTrue($evidence->qualifies(5_000_000.0));
    }

    #[Test]
    public function mutable_supply_is_rejected_as_unknown(): void
    {
        $this->fakeProviders(gt: [
            'pools' => $this->gtPools('PoolMUT'),
            'ohlcv' => $this->gtOhlcv([[$this->now->subDay()->getTimestamp(), 0.01, 0.9, 0.01, 0.5, 10.0]]),
            'token' => $this->gtToken(1_000_000_000.0),
            'info' => $this->gtInfo('SomeMintAuthorityAddress'), // mutable
        ]);
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $evidence->status);
        $this->assertStringContainsString('mutable', (string) $evidence->notes);
    }

    #[Test]
    public function missing_total_supply_is_rejected_as_unknown(): void
    {
        $this->fakeProviders(gt: [
            'pools' => $this->gtPools('PoolNS'),
            'ohlcv' => $this->gtOhlcv([[$this->now->subDay()->getTimestamp(), 0.01, 0.05, 0.01, 0.02, 10.0]]),
            'token' => $this->gtToken(null), // no supply
            'info' => $this->gtInfo(null),
        ]);
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $evidence->status);
        $this->assertStringContainsString('supply', (string) $evidence->notes);
    }

    #[Test]
    public function no_mint_signal_is_conservatively_rejected_unless_opted_in(): void
    {
        $this->fakeProviders(gt: [
            'pools' => $this->gtPools('PoolNoSig'),
            'ohlcv' => $this->gtOhlcv([[$this->now->subDay()->getTimestamp(), 0.01, 0.05, 0.01, 0.02, 10.0]]),
            'token' => $this->gtToken(1_000_000_000.0),
            'info' => $this->gtInfo(null, includeKey: false), // no mint_authority key at all
        ]);
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];
        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $evidence->status);

        // Now opt in -> low-confidence estimate allowed.
        config()->set('historical.geckoterminal.estimate.allow_without_mint_signal', true);
        HistoricalPeakEvidence::query()->delete();
        $token->update(['historical_peak_status' => null]);

        $evidence = $this->resolve($token->fresh())['evidence'][$token->id];
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE, $evidence->status);
        $this->assertSame('low', $evidence->confidence);
    }

    #[Test]
    public function unknown_never_qualifies(): void
    {
        $this->fakeProviders(); // everything empty
        $token = $this->makeToken(['observed_peak_market_cap' => 1_000_000.0]);

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $evidence->status);
        $this->assertFalse($evidence->qualifies(5_000_000.0));
        $this->assertNull($token->fresh()?->historical_peak_value);
    }

    #[Test]
    public function historical_evidence_never_overwrites_observed_peak_market_cap(): void
    {
        $this->fakeProviders(cg: [
            'contract' => $this->cgContract('verified-coin'),
            'market_chart' => $this->cgMarketChart([[$this->now->subDay()->getTimestampMs(), 9_000_000.0]]),
        ]);
        $token = $this->makeToken(['observed_peak_market_cap' => 1_000_000.0, 'observed_peak_market_cap_at' => $this->now->subDays(2)]);

        $this->resolve($token);

        $fresh = $token->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(1_000_000.0, $fresh->observed_peak_market_cap);
        $this->assertSame($this->now->subDays(2)->toIso8601String(), $fresh->observed_peak_market_cap_at?->toIso8601String());
        $this->assertSame(9_000_000.0, $fresh->historical_peak_value);
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED, $fresh->historical_peak_status);
    }

    #[Test]
    public function an_existing_observed_peak_above_threshold_stays_authoritative_current_observation(): void
    {
        $this->fakeProviders();
        // dump scenario: current low, our observed peak already >= $5M
        $token = $this->makeToken(['observed_peak_market_cap' => 7_000_000.0]);

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, $evidence->status);
        $this->assertSame(7_000_000.0, $evidence->peak_value_usd);
        Http::assertNothingSent();
    }

    #[Test]
    public function a_token_older_than_thirty_days_never_qualifies_via_the_read_api(): void
    {
        $token = $this->makeToken([
            'earliest_pair_created_at' => $this->now->subDays(45),
            'observed_peak_market_cap' => 1_000_000.0,
        ]);
        HistoricalPeakEvidence::query()->create([
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'peak_value_usd' => 50_000_000.0,
            'peak_observed_at' => $this->now->subDays(40),
            'evidence_source' => 'coingecko',
            'evidence_basis' => 'market_cap',
            'checked_at' => $this->now,
        ]);
        $token->update([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 50_000_000.0,
            'historical_peak_value_at' => $this->now->subDays(40),
        ]);

        $this->getJson('/api/memecoins')->assertOk()->assertJsonPath('meta.count', 0);
    }

    #[Test]
    public function the_lookup_cooldown_prevents_repeated_provider_requests(): void
    {
        $this->fakeProviders(); // -> UNKNOWN, checked_at = now
        $token = $this->makeToken(['observed_peak_market_cap' => 1_000_000.0]);

        $service = $this->service();
        $service->qualify([['token' => $token, 'chain_id' => 'solana', 'token_address' => $token->token_address]], $this->now);
        $callsAfterFirst = count(Http::recorded());
        $this->assertGreaterThan(0, $callsAfterFirst);

        // 2 hours later — still within the 6h cooldown.
        $later = $this->now->addHours(2);
        $service->qualify([['token' => $token->fresh(), 'chain_id' => 'solana', 'token_address' => $token->token_address]], $later);

        $this->assertCount($callsAfterFirst, Http::recorded(), 'no new provider calls within cooldown');
        $evidence = $token->fresh()?->historicalPeakEvidence;
        $this->assertSame($this->now->toIso8601String(), $evidence?->checked_at?->toIso8601String());
    }

    #[Test]
    public function the_cooldown_expires_and_an_unknown_is_re_evaluated(): void
    {
        // One fake, toggled: CoinGecko only "indexes" the token once $indexed flips.
        $indexed = false;
        Http::fake([
            'api.coingecko.com/api/v3/coins/*/contract/*' => function () use (&$indexed) {
                return $indexed
                    ? Http::response(['id' => 'now-indexed'])
                    : Http::response(['error' => 'coin not found'], 404);
            },
            'api.coingecko.com/api/v3/coins/*/market_chart/range*' => fn () => Http::response([
                'market_caps' => [[$this->now->subDays(2)->getTimestampMs(), 12_000_000.0]],
            ]),
            'api.geckoterminal.com/*' => fn () => Http::response(['data' => []]),
        ]);

        $token = $this->makeToken(['observed_peak_market_cap' => 1_000_000.0]);
        $this->resolve($token);
        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $token->fresh()?->historical_peak_status);

        // 7 hours later CoinGecko now indexes the token with a verified peak.
        $indexed = true;
        $later = $this->now->addHours(7);
        $result = $this->service()->qualify(
            [['token' => $token->fresh(), 'chain_id' => 'solana', 'token_address' => $token->token_address]],
            $later,
        );

        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED, $result['evidence'][$token->id]->status);
        $this->assertSame(12_000_000.0, $token->fresh()?->historical_peak_value);
    }

    #[Test]
    public function evidence_is_persisted_as_a_row(): void
    {
        $this->fakeProviders(cg: [
            'contract' => $this->cgContract('persist-coin'),
            'market_chart' => $this->cgMarketChart([[$this->now->subDay()->getTimestampMs(), 6_000_000.0]]),
        ]);
        $token = $this->makeToken();

        $this->resolve($token);

        $this->assertDatabaseHas('historical_peak_evidences', [
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'evidence_source' => 'coingecko',
            'evidence_basis' => 'market_cap',
        ]);
        $this->assertDatabaseCount('historical_peak_evidences', 1);
    }

    #[Test]
    public function a_below_threshold_estimate_does_not_leak_an_fdv_value_as_market_cap(): void
    {
        $this->fakeProviders(gt: [
            'pools' => $this->gtPools('PoolLow'),
            'ohlcv' => $this->gtOhlcv([[$this->now->subDay()->getTimestamp(), 0.001, 0.002, 0.001, 0.0015, 10.0]]),
            'token' => $this->gtToken(1_000_000_000.0), // 0.002 * 1e9 = 2M < 5M
            'info' => $this->gtInfo(null),
        ]);
        $token = $this->makeToken();

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $evidence->status);
        $this->assertNull($evidence->peak_value_usd);
        $this->assertNull($token->fresh()?->historical_peak_value);
        $this->assertNotSame(HistoricalPeakEvidence::BASIS_MARKET_CAP, $evidence->evidence_basis);
    }

    #[Test]
    public function evidence_source_and_basis_are_preserved_for_an_estimate(): void
    {
        $this->fakeProviders(gt: [
            'pools' => $this->gtPools('PoolPreserve'),
            'ohlcv' => $this->gtOhlcv([[$this->now->subDay()->getTimestamp(), 0.01, 0.02, 0.008, 0.012, 50.0]]),
            'token' => $this->gtToken(1_000_000_000.0),
            'info' => $this->gtInfo(null),
        ]);
        $token = $this->makeToken();

        $this->resolve($token);

        $this->assertDatabaseHas('historical_peak_evidences', [
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'evidence_source' => HistoricalPeakEvidence::SOURCE_GECKOTERMINAL,
            'evidence_basis' => HistoricalPeakEvidence::BASIS_FDV_TOTAL_SUPPLY,
        ]);
    }

    #[Test]
    public function different_chains_remain_distinct(): void
    {
        config()->set('historical.chain_map', [
            'solana' => ['coingecko' => 'solana', 'geckoterminal' => 'solana'],
            'ethereum' => ['coingecko' => 'ethereum', 'geckoterminal' => 'eth'],
        ]);
        $this->fakeProviders(cg: [
            'contract' => function (Request $request) {
                return str_contains($request->url(), '/ethereum/contract/')
                    ? Http::response(['id' => 'eth-coin'])
                    : Http::response(['error' => 'coin not found'], 404);
            },
            'market_chart' => $this->cgMarketChart([[$this->now->subDay()->getTimestampMs(), 9_000_000.0]]),
        ]);

        $sol = $this->makeToken(['chain_id' => 'solana', 'token_address' => 'SameAddr']);
        $eth = $this->makeToken(['chain_id' => 'ethereum', 'token_address' => 'SameAddr']);

        $solEv = $this->resolve($sol)['evidence'][$sol->id];
        $ethEv = $this->resolve($eth)['evidence'][$eth->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $solEv->status);
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED, $ethEv->status);
    }

    #[Test]
    public function a_provider_429_is_handled_safely_as_unknown(): void
    {
        config()->set('historical.coingecko.max_calls_per_run', 1);
        Http::fake([
            'api.coingecko.com/*' => Http::response(['status' => ['error_code' => 429]], 429),
            'api.geckoterminal.com/*' => Http::response('rate limited', 429),
        ]);
        $token = $this->makeToken(['observed_peak_market_cap' => 1_000_000.0]);

        $evidence = $this->resolve($token)['evidence'][$token->id];

        $this->assertSame(HistoricalPeakEvidence::STATUS_UNKNOWN, $evidence->status);
        $this->assertFalse($evidence->qualifies(5_000_000.0));
    }

    #[Test]
    public function the_read_api_exposes_qualification_fields_for_a_verified_token(): void
    {
        $token = $this->makeToken(['observed_peak_market_cap' => 1_000_000.0]);
        HistoricalPeakEvidence::query()->create([
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'peak_value_usd' => 8_200_000.0,
            'peak_observed_at' => $this->now->subDays(2),
            'evidence_source' => 'coingecko',
            'evidence_basis' => 'market_cap',
            'source_reference' => 'coingecko:x',
            'checked_at' => $this->now,
        ]);
        $token->update([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 8_200_000.0,
            'historical_peak_value_at' => $this->now->subDays(2),
        ]);

        $res = $this->getJson('/api/memecoins')->assertOk();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.qualification_status', 'HISTORICAL_VERIFIED');
        $res->assertJsonPath('data.0.qualification_peak_value', fn ($v) => (float) $v === 8_200_000.0);
        $res->assertJsonPath('data.0.qualification_source', 'coingecko');
        $res->assertJsonPath('data.0.qualification_basis', 'market_cap');
        $res->assertJsonPath('data.0.observed_peak_market_cap', fn ($v) => (float) $v === 1_000_000.0);
    }

    #[Test]
    public function the_read_api_derives_current_observation_when_no_evidence_row_exists(): void
    {
        $this->makeToken(['observed_peak_market_cap' => 9_000_000.0]);

        $res = $this->getJson('/api/memecoins')->assertOk();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.qualification_status', 'CURRENT_OBSERVATION');
        $res->assertJsonPath('data.0.qualification_basis', 'current_market_cap');
    }

    #[Test]
    public function cold_start_end_to_end_an_fdv_estimate_only_token_is_stored_but_not_on_the_main_list(): void
    {
        // DexScreener: token launched 2 days ago, currently $1M market cap.
        // GeckoTerminal: peak hourly high implies ~$8M FDV; supply immutable.
        config()->set('dexscreener.base_url', 'https://api.dexscreener.com');
        config()->set('dexscreener.search_terms', ['pepe']);
        config()->set('dexscreener.trending_meta_terms', 0);
        config()->set('dexscreener.cache.discovery_ttl', 0);
        config()->set('dexscreener.cache.enrichment_ttl', 0);
        config()->set('dexscreener.limits.max_candidates_to_enrich', 50);
        config()->set('dexscreener.limits.default_result_limit', 20);

        $addr = 'ColdStartAddr01';
        $pair = [
            'chainId' => 'solana',
            'dexId' => 'raydium',
            'pairAddress' => 'PAIRCS',
            'baseToken' => ['address' => $addr, 'name' => 'Crashed', 'symbol' => 'CRASH'],
            'quoteToken' => ['address' => 'USDC', 'symbol' => 'USDC'],
            'priceUsd' => '0.001',
            'liquidity' => ['usd' => 120_000.0],
            'volume' => ['h24' => 40_000.0],
            'priceChange' => ['h24' => -60.0],
            'txns' => ['h24' => ['buys' => 5, 'sells' => 30]],
            'fdv' => 1_000_000.0,
            'marketCap' => 1_000_000.0,
            'pairCreatedAt' => $this->now->subDays(2)->getTimestampMs(),
        ];

        Http::fake([
            'api.dexscreener.com/token-profiles/latest/v1' => Http::response([['chainId' => 'solana', 'tokenAddress' => $addr]]),
            'api.dexscreener.com/token-boosts/latest/v1' => Http::response([]),
            'api.dexscreener.com/token-boosts/top/v1' => Http::response([]),
            'api.dexscreener.com/metas/trending/v1' => Http::response([]),
            'api.dexscreener.com/latest/dex/search*' => Http::response(['pairs' => [$pair]]),
            'api.dexscreener.com/token-pairs/v1/*' => Http::response([$pair]),

            'api.coingecko.com/api/v3/coins/*/contract/*' => Http::response(['error' => 'coin not found'], 404),

            'api.geckoterminal.com/api/v2/networks/*/tokens/*/pools' => Http::response(['data' => [[
                'attributes' => ['address' => 'GTpoolCS', 'reserve_in_usd' => '120000'],
            ]]]),
            'api.geckoterminal.com/api/v2/networks/*/tokens/*/info' => Http::response(['data' => ['attributes' => ['mint_authority' => null]]]),
            'api.geckoterminal.com/api/v2/networks/*/pools/*/ohlcv/hour*' => Http::response(['data' => ['attributes' => ['ohlcv_list' => [
                [$this->now->subDays(2)->getTimestamp(), 0.004, 0.008, 0.003, 0.007, 900.0],
                [$this->now->subDay()->getTimestamp(), 0.007, 0.007, 0.001, 0.001, 800.0],
            ]]]]),
            'api.geckoterminal.com/api/v2/networks/*/tokens/*' => Http::response(['data' => ['attributes' => [
                'normalized_total_supply' => '1000000000',
            ]]]),
        ]);

        /** @var DexScreenerDiscoveryService $discovery */
        $discovery = app(DexScreenerDiscoveryService::class);
        $result = $discovery->discover(trigger: IngestionRun::TRIGGER_MANUAL);

        // Token persisted with our own $1M observed peak — never overwritten.
        $token = Token::query()->where('token_address', $addr)->firstOrFail();
        $this->assertSame(1_000_000.0, $token->observed_peak_market_cap);

        // Evidence PRESERVED: HISTORICAL_ESTIMATE, ~0.008 * 1e9 = $8M, FDV basis.
        $evidence = $token->historicalPeakEvidence()->firstOrFail();
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE, $evidence->status);
        $this->assertEqualsWithDelta(8_000_000.0, (float) $evidence->peak_value_usd, 1.0);
        $this->assertSame(HistoricalPeakEvidence::BASIS_FDV_TOTAL_SUPPLY, $evidence->evidence_basis);

        $fresh = $token->fresh();
        $this->assertSame(1_000_000.0, $fresh?->observed_peak_market_cap);
        // The estimate lands in the dedicated FDV column — NEVER historical_peak_value.
        $this->assertEqualsWithDelta(8_000_000.0, (float) $fresh?->historical_estimate_fdv_usd, 1.0);
        $this->assertNull($fresh?->historical_peak_value);
        $this->assertSame(HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE, $fresh?->historical_peak_status);

        // An FDV estimate does NOT qualify: absent from the discovery result AND the read API.
        $this->assertNotContains($addr, array_map(fn ($c) => $c->current->tokenAddress, $result->candidates));
        $this->assertGreaterThanOrEqual(1, (int) ($result->diagnostics['not_qualified_fdv_estimate_only'] ?? 0));

        $this->getJson('/api/memecoins')->assertOk()->assertJsonPath('meta.count', 0);
    }

    #[Test]
    public function the_per_run_lookup_budget_bounds_external_calls(): void
    {
        config()->set('historical.max_lookups_per_run', 2);
        $this->fakeProviders();

        $tokens = collect(range(1, 5))->map(fn ($i) => $this->makeToken([
            'observed_peak_market_cap' => 1_000_000.0,
            'token_address' => "Budget{$i}",
        ]));

        $result = $this->service()->qualify(
            $tokens->map(fn (Token $t) => ['token' => $t, 'chain_id' => 'solana', 'token_address' => $t->token_address])->all(),
            $this->now,
        );

        $this->assertSame(2, $result['stats']['historical_lookups_performed']);
        $this->assertSame(3, $result['stats']['historical_lookups_skipped_budget']);
        $this->assertCount(5, $result['evidence']);
    }
}
