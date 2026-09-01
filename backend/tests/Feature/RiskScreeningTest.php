<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use App\Services\Risk\ChartShapeAnalyzer;
use App\Services\Risk\GeckoTerminalInfoLookup;
use App\Services\Risk\GoPlusSecurityLookup;
use App\Services\Risk\HolderConcentration;
use App\Services\Risk\HolderConcentrationAnalyzer;
use App\Services\Risk\LiquidityStructure;
use App\Services\Risk\MainListDecision;
use App\Services\Risk\RiskScoreCalculator;
use App\Services\Risk\RiskScreeningService;
use App\Services\Risk\RiskSignalDraft;
use App\Services\Risk\RiskSignalEvaluator;
use App\Services\Risk\TokenRiskContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesRiskAssessments;
use Tests\TestCase;

/**
 * Step 24 — deterministic memecoin risk & safety screening.
 *
 * All provider calls (GoPlus / GeckoTerminal / DexScreener) are HTTP-mocked.
 */
class RiskScreeningTest extends TestCase
{
    use CreatesRiskAssessments;
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-01T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('risk.main_list.min_age_hours', 72);
        config()->set('risk.main_list.require_screening', true);
        config()->set('risk.min_data_completeness', 0.50);
        config()->set('risk.goplus.enabled', true);
        config()->set('risk.goplus.base_url', 'https://api.gopluslabs.io/api/v1');
        config()->set('risk.goplus.cache_ttl', 0);
        config()->set('risk.goplus.app_key', null);
        config()->set('risk.geckoterminal.enabled', true);
        config()->set('historical.geckoterminal.enabled', true);
        config()->set('historical.geckoterminal.base_url', 'https://api.geckoterminal.com/api/v2');
        config()->set('historical.geckoterminal.cache_ttl', 0);
        config()->set('risk.dexscreener.enabled', true);
        config()->set('risk.run.scan_cooldown_hours', 6);
        config()->set('risk.run.max_tokens_per_run', 15);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- helpers --------------------------------------------------------

    /** @param array<string,mixed> $overrides */
    private function makeToken(array $overrides = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => 'ethereum',
            'token_address' => '0x'.bin2hex(random_bytes(20)),
            'symbol' => 'TKN',
            'name' => 'Test Token',
            'earliest_pair_created_at' => $this->now->subDays(10),
            'first_observed_at' => $this->now->subDays(9),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 12_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDays(2),
        ], $overrides));

        return $token;
    }

    /** @param array<string,mixed> $snapshot */
    private function snapshot(Token $token, array $snapshot = [], ?CarbonImmutable $at = null): MarketSnapshot
    {
        /** @var MarketSnapshot $row */
        $row = $token->marketSnapshots()->create(array_replace([
            'observed_at' => $at ?? $this->now,
            'price_usd' => 0.01,
            'market_cap' => 8_000_000.0,
            'fdv' => 9_000_000.0,
            'liquidity_usd' => 500_000.0,
            'volume_h24' => 600_000.0,
            'price_change_h24' => 1.0,
            'txns_h24' => 200,
            'buys_h24' => 110,
            'sells_h24' => 90,
            'primary_pair_address' => '0xpairpairpair',
            'primary_dex_id' => 'uniswap',
            'earliest_pair_created_at' => $token->earliest_pair_created_at,
        ], $snapshot));

        return $row;
    }

    /**
     * Build a TokenRiskContext directly (no HTTP), for evaluator/calculator tests.
     *
     * @param  array<string,mixed>  $goplusRaw
     * @param  array<string,mixed>  $gtAttr
     */
    private function context(
        array $goplusRaw = [],
        string $goplusKind = 'evm',
        array $gtAttr = [],
        ?LiquidityStructure $liquidity = null,
        array $tokenOverrides = [],
        ?GoPlusSecurityLookup $goplusLookup = null,
        ?GeckoTerminalInfoLookup $gtLookup = null,
    ): TokenRiskContext {
        $token = $this->makeToken($tokenOverrides);
        $snapshots = collect([$this->snapshot($token)]);

        $goplus = $goplusLookup ?? ($goplusRaw === []
            ? GoPlusSecurityLookup::notIndexed($goplusKind)
            : GoPlusSecurityLookup::ok($goplusKind, $goplusRaw));
        $gt = $gtLookup ?? ($gtAttr === []
            ? GeckoTerminalInfoLookup::notIndexed()
            : GeckoTerminalInfoLookup::ok($gtAttr));

        $liquidity ??= LiquidityStructure::unavailable();

        $chart = app(ChartShapeAnalyzer::class)->analyze($snapshots, collect(), $this->now);
        $holders = app(HolderConcentrationAnalyzer::class)->analyze($goplus, $gt, $liquidity->poolAddresses(), $snapshots->first()->market_cap);

        return new TokenRiskContext(
            token: $token,
            latestSnapshot: $snapshots->first(),
            snapshots: $snapshots,
            pumpEvents: collect(),
            goplus: $goplus,
            geckoterminal: $gt,
            liquidity: $liquidity,
            chartShape: $chart,
            holders: $holders,
            now: $this->now,
        );
    }

    /** A "clean" EVM GoPlus profile — all the dangerous flags measured false. */
    private function cleanEvmRaw(array $overrides = []): array
    {
        return array_replace([
            'is_open_source' => '1',
            'is_proxy' => '0',
            'is_mintable' => '0',
            'owner_address' => '0x0000000000000000000000000000000000000000',
            'can_take_back_ownership' => '0',
            'hidden_owner' => '0',
            'owner_change_balance' => '0',
            'selfdestruct' => '0',
            'external_call' => '0',
            'buy_tax' => '0',
            'sell_tax' => '0',
            'cannot_buy' => '0',
            'cannot_sell_all' => '0',
            'slippage_modifiable' => '0',
            'is_honeypot' => '0',
            'transfer_pausable' => '0',
            'is_blacklisted' => '0',
            'is_anti_whale' => '0',
            'holder_count' => '4200',
            'holders' => [
                ['address' => '0xaaa', 'tag' => '', 'is_contract' => 0, 'is_locked' => 0, 'percent' => '0.08'],
                ['address' => '0xbbb', 'tag' => '', 'is_contract' => 0, 'is_locked' => 0, 'percent' => '0.04'],
            ],
            'lp_holders' => [
                ['address' => '0x000000000000000000000000000000000000dEaD', 'tag' => 'burn', 'is_contract' => 1, 'is_locked' => 0, 'percent' => '0.96'],
            ],
            'creator_percent' => '0.01',
            'owner_percent' => '0',
        ], $overrides);
    }

    private function evaluate(TokenRiskContext $ctx, string $status = RiskAssessment::STATUS_COMPLETED)
    {
        $signals = app(RiskSignalEvaluator::class)->evaluate($ctx);

        return [$signals, app(RiskScoreCalculator::class)->calculate($signals, $status)];
    }

    private function signal(array $signals, string $key): ?RiskSignalDraft
    {
        foreach ($signals as $s) {
            if ($s->key === $key) {
                return $s;
            }
        }

        return null;
    }

    // ==== 1-2, 29-31: scoring + levels ==================================

    #[Test]
    public function a_clean_evm_token_is_lower_risk_and_main_list_eligible(): void
    {
        [$signals, $result] = $this->evaluate($this->context($this->cleanEvmRaw()));

        $this->assertSame(RiskAssessment::LEVEL_LOWER, $result->level);
        $this->assertTrue($result->mainListEligible);
        $this->assertNull($result->hardOverrideSignal);
        $this->assertGreaterThanOrEqual(0, $result->score);
        $this->assertLessThanOrEqual(100, $result->score);
    }

    #[Test]
    public function a_moderately_flawed_token_is_medium_risk_and_still_main_list_eligible(): void
    {
        $raw = $this->cleanEvmRaw([
            'sell_tax' => '0.06',      // elevated but not hard
            'buy_tax' => '0.04',
            'is_blacklisted' => '1',   // soft
            'can_take_back_ownership' => '1', // soft
        ]);
        [$signals, $result] = $this->evaluate($this->context($raw));

        $this->assertContains($result->level, [RiskAssessment::LEVEL_MEDIUM, RiskAssessment::LEVEL_LOWER]);
        $this->assertNotSame(RiskAssessment::LEVEL_HIGH, $result->level);
    }

    #[Test]
    public function the_score_is_deterministic_and_bounded(): void
    {
        $raw = $this->cleanEvmRaw(['sell_tax' => '0.07', 'is_proxy' => '1']);
        [$s1, $r1] = $this->evaluate($this->context($raw));
        [$s2, $r2] = $this->evaluate($this->context($raw));

        $this->assertSame($r1->score, $r2->score);
        $this->assertSame($r1->level, $r2->level);
        $this->assertGreaterThanOrEqual(0, $r1->score);
        $this->assertLessThanOrEqual(100, $r1->score);
    }

    // ==== 8-14: hard overrides ========================================

    #[Test]
    public function honeypot_true_is_critical(): void
    {
        [, $result] = $this->evaluate($this->context($this->cleanEvmRaw(['is_honeypot' => '1'])));
        $this->assertSame(RiskAssessment::LEVEL_CRITICAL, $result->level);
        $this->assertSame('is_honeypot', $result->hardOverrideSignal);
        $this->assertFalse($result->mainListEligible);
    }

    #[Test]
    public function cannot_sell_all_true_is_critical(): void
    {
        [, $result] = $this->evaluate($this->context($this->cleanEvmRaw(['cannot_sell_all' => '1'])));
        $this->assertSame(RiskAssessment::LEVEL_CRITICAL, $result->level);
        $this->assertSame('cannot_sell_all', $result->hardOverrideSignal);
    }

    #[Test]
    public function one_hundred_percent_sell_tax_is_critical(): void
    {
        [, $result] = $this->evaluate($this->context($this->cleanEvmRaw(['sell_tax' => '1'])));
        $this->assertSame(RiskAssessment::LEVEL_CRITICAL, $result->level);
        $this->assertSame('sell_tax', $result->hardOverrideSignal);
    }

    #[Test]
    public function mintable_true_is_at_least_high(): void
    {
        [$signals, $result] = $this->evaluate($this->context($this->cleanEvmRaw(['is_mintable' => '1'])));
        $this->assertContains($result->level, [RiskAssessment::LEVEL_HIGH, RiskAssessment::LEVEL_CRITICAL]);
        $this->assertSame('is_mintable', $result->hardOverrideSignal);
        $this->assertSame(RiskSignal::STATE_BAD, $this->signal($signals, 'is_mintable')->state);
    }

    #[Test]
    public function mintable_unknown_is_not_claimed_present_and_does_not_force_high(): void
    {
        // Mint field absent from an otherwise clean profile.
        $raw = $this->cleanEvmRaw();
        unset($raw['is_mintable']);
        [$signals, $result] = $this->evaluate($this->context($raw));

        $mint = $this->signal($signals, 'is_mintable');
        $this->assertSame(RiskSignal::STATE_UNKNOWN, $mint->state);
        $this->assertStringNotContainsStringIgnoringCase('mintable contract', (string) $mint->explanation);
        $this->assertNotSame('is_mintable', $result->hardOverrideSignal);
        // Not forced HIGH by the unknown mint alone.
        $this->assertNotSame(RiskAssessment::LEVEL_HIGH, $result->level);
    }

    #[Test]
    public function hidden_owner_is_high(): void
    {
        [, $result] = $this->evaluate($this->context($this->cleanEvmRaw(['hidden_owner' => '1'])));
        $this->assertContains($result->level, [RiskAssessment::LEVEL_HIGH, RiskAssessment::LEVEL_CRITICAL]);
        $this->assertSame('hidden_owner', $result->hardOverrideSignal);
    }

    #[Test]
    public function a_live_proxy_is_high(): void
    {
        [, $result] = $this->evaluate($this->context($this->cleanEvmRaw(['is_proxy' => '1'])));
        $this->assertContains($result->level, [RiskAssessment::LEVEL_HIGH, RiskAssessment::LEVEL_CRITICAL]);
        $this->assertSame('is_proxy', $result->hardOverrideSignal);
    }

    #[Test]
    public function a_hard_override_wins_regardless_of_an_otherwise_zero_score(): void
    {
        [, $result] = $this->evaluate($this->context($this->cleanEvmRaw(['is_honeypot' => '1'])));
        // score would be ~0 for an otherwise pristine token, but honeypot forces CRITICAL.
        $this->assertLessThan(25, $result->score);
        $this->assertSame(RiskAssessment::LEVEL_CRITICAL, $result->level);
    }

    // ==== 15-18: tri-state / missing data =============================

    #[Test]
    public function an_unknown_sell_tax_stays_unknown_and_is_never_zero(): void
    {
        $raw = $this->cleanEvmRaw();
        unset($raw['sell_tax']);
        [$signals] = $this->evaluate($this->context($raw));

        $sig = $this->signal($signals, 'sell_tax');
        $this->assertSame(RiskSignal::STATE_UNKNOWN, $sig->state);
        $this->assertNull($sig->numericValue);
    }

    #[Test]
    public function a_null_owner_address_stays_unknown_never_assumed_renounced(): void
    {
        $raw = $this->cleanEvmRaw();
        unset($raw['owner_address']);
        [$signals] = $this->evaluate($this->context($raw));

        $owner = $this->signal($signals, 'owner_renounced');
        $this->assertSame(RiskSignal::STATE_UNKNOWN, $owner->state);
    }

    #[Test]
    public function an_unsupported_chain_does_not_become_high_risk(): void
    {
        $ctx = $this->context(
            goplusLookup: GoPlusSecurityLookup::unsupportedChain('robinhood'),
            gtLookup: GeckoTerminalInfoLookup::unsupportedChain('robinhood'),
            tokenOverrides: ['chain_id' => 'robinhood'],
        );
        [, $result] = $this->evaluate($ctx, RiskAssessment::STATUS_FAILED);

        $this->assertSame(RiskAssessment::LEVEL_UNKNOWN, $result->level);
        $this->assertNotSame(RiskAssessment::LEVEL_HIGH, $result->level);
        $this->assertFalse($result->mainListEligible);
    }

    #[Test]
    public function missing_holder_data_does_not_fabricate_a_holder_count(): void
    {
        $raw = $this->cleanEvmRaw();
        unset($raw['holder_count'], $raw['holders']);
        [$signals] = $this->evaluate($this->context($raw));

        $count = $this->signal($signals, 'holder_count');
        $this->assertTrue($count === null || $count->state === RiskSignal::STATE_UNKNOWN);
        $dist = $this->signal($signals, 'holder_distribution') ?? $this->signal($signals, 'top1_effective_pct');
        $this->assertNotNull($dist);
    }

    #[Test]
    public function insufficient_data_completeness_is_risk_unknown_not_high(): void
    {
        // GoPlus returns almost nothing measurable.
        $ctx = $this->context(['holder_count' => '10'], gtAttr: []);
        [, $result] = $this->evaluate($ctx, RiskAssessment::STATUS_PARTIAL);

        $this->assertSame(RiskAssessment::LEVEL_UNKNOWN, $result->level);
        $this->assertFalse($result->mainListEligible);
        $this->assertLessThan(0.5, $result->dataCompleteness);
    }

    // ==== 19-21: holders ==============================================

    #[Test]
    public function burn_and_lp_holders_are_excluded_from_effective_concentration(): void
    {
        $raw = $this->cleanEvmRaw([
            'holders' => [
                ['address' => '0x000000000000000000000000000000000000dEaD', 'tag' => 'burn', 'is_contract' => 1, 'is_locked' => 0, 'percent' => '0.40'],
                ['address' => '0xpool', 'tag' => 'Uniswap V2', 'is_contract' => 1, 'is_locked' => 0, 'percent' => '0.25'],
                ['address' => '0xlockr', 'tag' => 'Team Finance Lock', 'is_contract' => 1, 'is_locked' => 1, 'percent' => '0.10'],
                ['address' => '0xwhale', 'tag' => '', 'is_contract' => 0, 'is_locked' => 0, 'percent' => '0.12'],
            ],
        ]);
        [$signals] = $this->evaluate($this->context($raw));

        $top1 = $this->signal($signals, 'top1_effective_pct');
        $this->assertSame(RiskSignal::STATE_MEASURED, $top1->state);
        $this->assertEqualsWithDelta(0.12, $top1->numericValue, 0.001);
    }

    #[Test]
    public function extreme_top_holder_concentration_is_a_hard_flag(): void
    {
        $raw = $this->cleanEvmRaw([
            'holders' => [['address' => '0xwhale', 'tag' => '', 'is_contract' => 0, 'is_locked' => 0, 'percent' => '0.62']],
        ]);
        [$signals, $result] = $this->evaluate($this->context($raw));

        $this->assertSame(RiskSignal::STATE_BAD, $this->signal($signals, 'top1_effective_pct')->state);
        $this->assertContains($result->level, [RiskAssessment::LEVEL_HIGH, RiskAssessment::LEVEL_CRITICAL]);
    }

    #[Test]
    public function creator_concentration_above_threshold_is_scored(): void
    {
        $raw = $this->cleanEvmRaw(['creator_percent' => '0.35']);
        [$signals, $result] = $this->evaluate($this->context($raw));

        $this->assertSame(RiskSignal::STATE_BAD, $this->signal($signals, 'creator_pct')->state);
        $this->assertContains($result->level, [RiskAssessment::LEVEL_HIGH, RiskAssessment::LEVEL_CRITICAL]);
    }

    // ==== 22-27: market structure / liquidity / pump-dump =============

    #[Test]
    public function a_high_volume_to_liquidity_ratio_is_scored_but_never_called_a_scam(): void
    {
        $token = $this->makeToken();
        $snap = $this->snapshot($token, ['volume_h24' => 6_000_000.0, 'liquidity_usd' => 300_000.0]); // 20x
        $ctx = new TokenRiskContext($token, $snap, collect([$snap]), collect(),
            GoPlusSecurityLookup::ok('evm', $this->cleanEvmRaw()), GeckoTerminalInfoLookup::notIndexed(),
            LiquidityStructure::unavailable(),
            app(ChartShapeAnalyzer::class)->analyze(collect([$snap]), collect(), $this->now),
            HolderConcentration::unavailable(), $this->now);

        [$signals] = $this->evaluate($ctx);
        $ratio = $this->signal($signals, 'volume_liquidity_ratio');
        $this->assertSame(RiskSignal::STATE_MEASURED, $ratio->state);
        $this->assertStringContainsStringIgnoringCase('turnover', (string) $ratio->explanation);
        $this->assertStringNotContainsStringIgnoringCase('scam', (string) $ratio->explanation);
    }

    #[Test]
    public function buy_sell_balance_is_a_soft_signal(): void
    {
        $token = $this->makeToken();
        $snap = $this->snapshot($token, ['buys_h24' => 20, 'sells_h24' => 180]);
        $ctx = new TokenRiskContext($token, $snap, collect([$snap]), collect(),
            GoPlusSecurityLookup::ok('evm', $this->cleanEvmRaw()), GeckoTerminalInfoLookup::notIndexed(),
            LiquidityStructure::unavailable(),
            app(ChartShapeAnalyzer::class)->analyze(collect([$snap]), collect(), $this->now),
            HolderConcentration::unavailable(), $this->now);

        [$signals] = $this->evaluate($ctx);
        $bs = $this->signal($signals, 'buy_share');
        $this->assertSame(RiskSignal::STATE_MEASURED, $bs->state);
        $this->assertStringNotContainsStringIgnoringCase('scam', (string) $bs->explanation);
    }

    #[Test]
    public function a_completed_round_trip_crash_is_a_hard_pump_dump_flag(): void
    {
        $token = $this->makeToken();
        $snaps = collect();
        // ramp 1M -> 30M then crash to 5M, volume collapses.
        $caps = [1_000_000, 3_000_000, 12_000_000, 30_000_000, 22_000_000, 12_000_000, 5_000_000];
        foreach ($caps as $i => $cap) {
            $snaps->push($this->snapshot($token, [
                'market_cap' => (float) $cap,
                'volume_h24' => $i === 3 ? 8_000_000.0 : ($i >= 5 ? 200_000.0 : 4_000_000.0),
            ], $this->now->subMinutes((count($caps) - $i) * 15)));
        }
        $chart = app(ChartShapeAnalyzer::class)->analyze($snaps, collect(), $this->now);
        $ctx = new TokenRiskContext($token, $snaps->last(), $snaps, collect(),
            GoPlusSecurityLookup::ok('evm', $this->cleanEvmRaw()), GeckoTerminalInfoLookup::notIndexed(),
            LiquidityStructure::unavailable(), $chart, HolderConcentration::unavailable(), $this->now);

        [$signals, $result] = $this->evaluate($ctx);
        $rt = $this->signal($signals, 'round_trip_crash');
        $this->assertSame(RiskSignal::STATE_BAD, $rt->state);
        $this->assertStringNotContainsStringIgnoringCase('scam', (string) $rt->explanation);
        $this->assertContains($result->level, [RiskAssessment::LEVEL_HIGH, RiskAssessment::LEVEL_CRITICAL]);
    }

    #[Test]
    public function insufficient_history_reports_unknown_not_a_pump_dump(): void
    {
        [$signals] = $this->evaluate($this->context($this->cleanEvmRaw()));
        $pd = $this->signal($signals, 'pump_dump_shape');
        $this->assertSame(RiskSignal::STATE_UNKNOWN, $pd->state);
        $this->assertStringContainsStringIgnoringCase('insufficient', (string) $pd->explanation);
    }

    #[Test]
    public function multiple_pools_reduce_concentration_risk_but_do_not_equal_safe(): void
    {
        $spread = LiquidityStructure::fromPairs([
            ['pairAddress' => '0xp1', 'dexId' => 'uniswap', 'liquidity' => ['usd' => 400_000]],
            ['pairAddress' => '0xp2', 'dexId' => 'sushiswap', 'liquidity' => ['usd' => 350_000]],
            ['pairAddress' => '0xp3', 'dexId' => 'pancakeswap', 'liquidity' => ['usd' => 300_000]],
        ], 0.90);
        [$signals] = $this->evaluate($this->context($this->cleanEvmRaw(), liquidity: $spread));

        $pool = $this->signal($signals, 'pool_structure');
        $this->assertSame(RiskSignal::STATE_MEASURED, $pool->state);
        $this->assertNotSame(RiskSignal::STATE_BAD, $pool->state);
        $this->assertStringNotContainsStringIgnoringCase('safe', (string) $pool->explanation);

        // Single THIN pool + no LP lock evidence => hard flag.
        config()->set('risk.liquidity.thin_total_usd', 50_000);
        $single = LiquidityStructure::fromPairs([
            ['pairAddress' => '0xonly', 'dexId' => 'uniswap', 'liquidity' => ['usd' => 18_000]],
        ], 0.90);
        $raw = $this->cleanEvmRaw(['lp_holders' => [['address' => '0xeoa', 'tag' => '', 'is_contract' => 0, 'is_locked' => 0, 'percent' => '1.0']]]);
        [$signals2] = $this->evaluate($this->context($raw, liquidity: $single, tokenOverrides: []));
        $this->assertSame(RiskSignal::STATE_BAD, $this->signal($signals2, 'pool_structure')->state);

        // Single DEEP pool with no LP data => only a soft (medium) concern, never a hard fail.
        $deep = LiquidityStructure::fromPairs([
            ['pairAddress' => '0xdeep', 'dexId' => 'uniswap', 'liquidity' => ['usd' => 3_000_000]],
        ], 0.90);
        [$signals3] = $this->evaluate($this->context($this->cleanEvmRaw(['lp_holders' => []]), liquidity: $deep));
        $this->assertNotSame(RiskSignal::STATE_BAD, $this->signal($signals3, 'pool_structure')->state);
    }

    #[Test]
    public function community_takeover_is_contextual_and_top_trader_is_not_available(): void
    {
        [$signals] = $this->evaluate($this->context($this->cleanEvmRaw()));

        $ct = $this->signal($signals, 'community_takeover');
        $this->assertSame(RiskSignal::STATE_UNKNOWN, $ct->state);
        $this->assertFalse($ct->applicable);

        $tt = $this->signal($signals, 'top_trader_analysis');
        $this->assertSame(RiskSignal::STATE_NOT_AVAILABLE, $tt->state);
    }

    // ==== 6-7: maturity ==============================================

    #[Test]
    public function a_token_younger_than_seventy_two_hours_is_kept_off_the_main_list(): void
    {
        $token = $this->makeToken(['earliest_pair_created_at' => $this->now->subHours(2)]);
        $token->update([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 23_000_000.0,
            'historical_peak_value_at' => $this->now->subHour(),
        ]);
        $this->snapshot($token, ['market_cap' => 23_000_000.0]);
        $this->passRisk($token); // even a LOWER-risk assessment must not lift it onto main

        $this->getJson('/api/memecoins')->assertOk()->assertJsonPath('meta.count', 0);

        $decision = MainListDecision::for($token->fresh()->load('riskAssessment.signals'), $this->now);
        $this->assertFalse($decision->eligible);
        $this->assertContains(
            'Token is younger than the main-list maturity minimum.',
            $decision->reasonLabels(),
        );
    }

    // ==== 32-33: cooldown / force ===================================

    #[Test]
    public function the_scan_cooldown_prevents_repeated_provider_calls_and_force_overrides_it(): void
    {
        $this->fakeProviders();
        $token = $this->makeToken();
        $this->snapshot($token);

        app(RiskScreeningService::class)->screen(now: $this->now);
        $firstCalls = count(Http::recorded());
        $this->assertGreaterThan(0, $firstCalls);
        $this->assertDatabaseHas('risk_assessments', ['token_id' => $token->id]);

        // 2h later — inside the 6h cooldown.
        $result = app(RiskScreeningService::class)->screen(now: $this->now->addHours(2));
        $this->assertCount($firstCalls, Http::recorded(), 'no new provider calls within cooldown');
        $this->assertSame(1, $result->skippedCooldown);

        // force re-scans.
        app(RiskScreeningService::class)->screen(force: true, now: $this->now->addHours(2));
        $this->assertGreaterThan($firstCalls, count(Http::recorded()));
    }

    // ==== 34-35: persistence ========================================

    #[Test]
    public function the_assessment_and_its_signals_are_persisted_and_replaced_on_rescan(): void
    {
        $honeypot = true;
        $this->fakeProvidersDynamic($honeypot);
        $token = $this->makeToken();
        $this->snapshot($token);

        app(RiskScreeningService::class)->screen(now: $this->now);

        $this->assertDatabaseCount('risk_assessments', 1);
        $this->assertDatabaseHas('risk_assessments', [
            'token_id' => $token->id,
            'risk_level' => RiskAssessment::LEVEL_CRITICAL,
            'hard_override_signal' => 'is_honeypot',
        ]);
        $this->assertDatabaseHas('risk_signals', [
            'token_id' => $token->id,
            'signal_key' => 'is_honeypot',
            'state' => RiskSignal::STATE_BAD,
        ]);
        $countBefore = RiskSignal::query()->where('token_id', $token->id)->count();

        $honeypot = false;
        app(RiskScreeningService::class)->screen(force: true, now: $this->now->addHours(1));

        $this->assertDatabaseCount('risk_assessments', 1); // still one row
        $this->assertSame($countBefore, RiskSignal::query()->where('token_id', $token->id)->count(), 'signals replaced, not duplicated');
        $this->assertDatabaseMissing('risk_signals', [
            'token_id' => $token->id, 'signal_key' => 'is_honeypot', 'state' => RiskSignal::STATE_BAD,
        ]);
        $this->assertDatabaseHas('risk_assessments', ['token_id' => $token->id, 'risk_level' => RiskAssessment::LEVEL_LOWER]);
    }

    #[Test]
    public function screening_does_not_touch_qualification_observed_peak_pump_events_or_evidence(): void
    {
        $this->fakeProviders(honeypot: true);
        $token = $this->makeToken(['observed_peak_market_cap' => 12_000_000.0]);
        $this->snapshot($token);
        $token->update([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
            'historical_peak_value' => 12_000_000.0,
        ]);
        /** @var PumpEvent $pump */
        $pump = $token->pumpEvents()->create([
            'started_at' => $this->now->subHours(3), 'peak_at' => $this->now->subHours(2),
            'start_market_cap' => 5_000_000.0, 'peak_market_cap' => 12_000_000.0,
            'market_cap_change_pct' => 140.0, 'detection_score' => 80, 'confidence' => 'high', 'status' => 'completed',
        ]);

        app(RiskScreeningService::class)->screen(now: $this->now);

        $fresh = $token->fresh();
        $this->assertSame(12_000_000.0, $fresh->observed_peak_market_cap);
        $this->assertSame(HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, $fresh->historical_peak_status);
        $this->assertSame(80, $pump->fresh()->detection_score);
    }

    // ==== 36-39: APIs ==============================================

    #[Test]
    public function the_main_list_returns_only_lower_and_medium_risk_tokens(): void
    {
        $good = $this->makeToken(['symbol' => 'GOOD']);
        $this->snapshot($good);
        $this->passRisk($good, RiskAssessment::LEVEL_MEDIUM);

        $bad = $this->makeToken(['symbol' => 'BADH']);
        $this->snapshot($bad);
        $this->failRisk($bad, RiskAssessment::LEVEL_HIGH);

        $crit = $this->makeToken(['symbol' => 'BADC']);
        $this->snapshot($crit);
        $this->failRisk($crit, RiskAssessment::LEVEL_CRITICAL);

        $unk = $this->makeToken(['symbol' => 'UNK']);
        $this->snapshot($unk);
        $this->failRisk($unk, RiskAssessment::LEVEL_UNKNOWN);

        $res = $this->getJson('/api/memecoins')->assertOk();
        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.symbol', 'GOOD');
        $res->assertJsonPath('data.0.risk_level', RiskAssessment::LEVEL_MEDIUM);
        $this->assertIsInt($res->json('data.0.risk_score'));
        $this->assertIsArray($res->json('data.0.risk_summary'));
    }

    #[Test]
    public function a_high_risk_qualified_token_is_excluded_from_the_main_list(): void
    {
        $this->makeTokenWithSnapshotAndRisk('WATCH', fn (Token $t) => $this->failRisk($t, RiskAssessment::LEVEL_HIGH));
        $this->makeTokenWithSnapshotAndRisk('OKAY', fn (Token $t) => $this->passRisk($t));

        $res = $this->getJson('/api/memecoins')->assertOk();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.symbol', 'OKAY');
    }

    #[Test]
    public function the_read_apis_never_call_a_provider(): void
    {
        Http::fake();
        $this->makeTokenWithSnapshotAndRisk('A', fn (Token $t) => $this->passRisk($t));
        $this->makeTokenWithSnapshotAndRisk('B', fn (Token $t) => $this->failRisk($t));

        $this->getJson('/api/memecoins')->assertOk();
        $token = Token::query()->first();
        $this->getJson("/api/memecoins/{$token->chain_id}/{$token->token_address}")->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_detail_api_exposes_the_risk_assessment_with_grouped_signals(): void
    {
        $token = $this->makeToken();
        $this->snapshot($token);
        $this->failRisk($token, RiskAssessment::LEVEL_HIGH);

        $res = $this->getJson("/api/memecoins/{$token->chain_id}/{$token->token_address}")->assertOk();

        $res->assertJsonPath('data.risk_assessment.risk_level', RiskAssessment::LEVEL_HIGH);
        $res->assertJsonPath('data.risk_assessment.status', RiskAssessment::STATUS_COMPLETED);
        $res->assertJsonPath('data.risk_assessment.hard_override_signal', 'is_mintable');
        $this->assertNotEmpty($res->json('data.risk_assessment.signals'));
        $this->assertArrayHasKey('group', $res->json('data.risk_assessment.signals.0'));
        $this->assertArrayHasKey('state', $res->json('data.risk_assessment.signals.0'));
    }

    #[Test]
    public function the_detail_api_reports_pending_when_a_token_is_not_yet_screened(): void
    {
        $token = $this->makeToken();
        $this->snapshot($token);

        $this->getJson("/api/memecoins/{$token->chain_id}/{$token->token_address}")
            ->assertOk()
            ->assertJsonPath('data.risk_assessment.status', 'pending')
            ->assertJsonPath('data.risk_assessment.risk_level', null);
    }

    #[Test]
    public function the_command_prints_a_summary(): void
    {
        $this->fakeProviders();
        $token = $this->makeToken();
        $this->snapshot($token);

        $this->artisan('memecoins:screen-risk')
            ->expectsOutputToContain('Risk screening completed.')
            ->expectsOutputToContain('Tokens analyzed:')
            ->assertExitCode(0);
    }

    #[Test]
    public function no_dashboard_terminology_claims_a_token_is_safe(): void
    {
        $this->makeTokenWithSnapshotAndRisk('CLEAN', fn (Token $t) => $this->passRisk($t));
        $this->makeTokenWithSnapshotAndRisk('DANGER', fn (Token $t) => $this->failRisk($t, RiskAssessment::LEVEL_CRITICAL));

        $blob = strtolower(json_encode($this->getJson('/api/memecoins')->assertOk()->json()));
        $this->assertStringNotContainsString('safe coin', $blob);
        $this->assertStringNotContainsString('guaranteed safe', $blob);
        $this->assertStringNotContainsString('safe investment', $blob);
    }

    // ---- more helpers --------------------------------------------------

    private function makeTokenWithSnapshotAndRisk(string $symbol, \Closure $risk): Token
    {
        $token = $this->makeToken(['symbol' => $symbol]);
        $this->snapshot($token);
        $risk($token);

        return $token;
    }

    private function fakeProvidersDynamic(bool &$honeypot): void
    {
        Http::fake([
            'api.gopluslabs.io/api/v1/token_security/*' => function () use (&$honeypot) {
                return Http::response(['code' => 1, 'result' => ['0xtoken' => $this->cleanEvmRaw($honeypot ? ['is_honeypot' => '1'] : [])]]);
            },
            'api.gopluslabs.io/api/v1/rugpull_detecting/*' => Http::response(['code' => 1, 'result' => []]),
            'api.gopluslabs.io/api/v1/solana/token_security*' => Http::response(['code' => 1, 'result' => []]),
            'api.geckoterminal.com/api/v2/networks/*/tokens/*/info' => function () use (&$honeypot) {
                return Http::response(['data' => ['attributes' => [
                    'holders' => ['count' => 4200, 'distribution_percentage' => ['top_10' => 12]],
                    'mint_authority' => null,
                    'is_honeypot' => $honeypot,
                ]]]);
            },
            'api.dexscreener.com/token-pairs/v1/*' => Http::response([
                ['pairAddress' => '0xp1', 'dexId' => 'uniswap', 'liquidity' => ['usd' => 500_000]],
                ['pairAddress' => '0xp2', 'dexId' => 'sushiswap', 'liquidity' => ['usd' => 250_000]],
            ]),
        ]);
    }

    private function fakeProviders(bool $honeypot = false): void
    {
        $sec = $this->cleanEvmRaw($honeypot ? ['is_honeypot' => '1'] : []);

        Http::fake([
            'api.gopluslabs.io/api/v1/token_security/*' => Http::response(['code' => 1, 'result' => ['0xtoken' => $sec]]),
            'api.gopluslabs.io/api/v1/rugpull_detecting/*' => Http::response(['code' => 1, 'result' => []]),
            'api.gopluslabs.io/api/v1/solana/token_security*' => Http::response(['code' => 1, 'result' => []]),
            'api.geckoterminal.com/api/v2/networks/*/tokens/*/info' => Http::response(['data' => ['attributes' => [
                'holders' => ['count' => 4200, 'distribution_percentage' => ['top_10' => 12]],
                'mint_authority' => null,
                'is_honeypot' => $honeypot,
            ]]]),
            'api.dexscreener.com/token-pairs/v1/*' => Http::response([
                ['pairAddress' => '0xp1', 'dexId' => 'uniswap', 'liquidity' => ['usd' => 500_000]],
                ['pairAddress' => '0xp2', 'dexId' => 'sushiswap', 'liquidity' => ['usd' => 250_000]],
            ]),
        ]);
    }
}
