<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\QualificationEvent;
use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesRiskAssessments;
use Tests\TestCase;

/**
 * "🔥 Recently Crossed $5M" — GET /api/memecoins/recently-crossed.
 *
 * Read-only. PostgreSQL only — never DexScreener / CoinGecko / GeckoTerminal /
 * GoPlus. A memecoin appears only when it crossed $5M within the last 30 days
 * AND passes every deterministic quality gate (peak $5M–$1B, risk screen,
 * holder participation, 24h volume vs current MC, liquidity, active discovery).
 */
class RecentlyCrossedTest extends TestCase
{
    use CreatesRiskAssessments;
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-31T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 1_000_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);

        config()->set('dexscreener.recent_crossing.window_days', 30);
        config()->set('dexscreener.recent_crossing.discovery_freshness_hours', 48);
        // Calibrated thresholds (see docs/recently-crossed-calibration.md).
        config()->set('dexscreener.recent_crossing.min_holders_per_million_mcap', 25.0);
        config()->set('dexscreener.recent_crossing.require_holder_evidence', true);
        config()->set('dexscreener.recent_crossing.min_volume_to_mcap_ratio', 0.01);
        config()->set('dexscreener.recent_crossing.min_liquidity_to_mcap_ratio', 0.005);
        // Red-flag gates (2026-09 pippo incident).
        config()->set('dexscreener.recent_crossing.max_price_change_h24_pct', 250.0);
        config()->set('dexscreener.recent_crossing.collapse_lookback_hours', 72);
        config()->set('dexscreener.recent_crossing.collapse_floor_ratio', 0.35);
        config()->set('dexscreener.recent_crossing.min_age_hours', 0);

        config()->set('risk.liquidity.min_total_usd', 10_000.0);
        config()->set('risk.main_list.require_screening', true);
        config()->set('risk.min_data_completeness', 0.5);
        // Chains our security provider covers — anything else (e.g. robinhood) is
        // an unscreenable chain and is excluded from Recently Crossed.
        config()->set('risk.goplus_chain_map', [
            'ethereum' => '1', 'bsc' => '56', 'base' => '8453', 'solana' => 'solana',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /**
     * A fully-qualifying memecoin by default: recent pool, fresh observation,
     * peak in band, current MC $20M, $2M 24h volume, $800K liquidity, LOWER
     * risk, 8,000 holders. Override anything via `$attrs` / `$snapshot`; use
     * `$opts` to skip the risk assessment or the holder signal.
     *
     * @param  array<string,mixed>  $attrs
     * @param  array<string,mixed>  $snapshot
     * @param  array{skipRisk?:bool,skipHolders?:bool,holders?:?int,riskLevel?:string}  $opts
     */
    private function token(array $attrs = [], array $snapshot = [], array $opts = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'TKN',
            'name' => 'Test Token',
            'earliest_pair_created_at' => $this->now->subDays(10),
            'first_observed_at' => $this->now->subDays(5),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 25_000_000.0,
            // Default the observed peak WELL outside the collapse-lookback window
            // so the post-crossing-collapse red flag only fires where a test
            // deliberately sets a recent `observed_peak_market_cap_at`.
            'observed_peak_market_cap_at' => $this->now->subDays(10),
        ], $attrs));

        $token->marketSnapshots()->create(array_replace([
            'observed_at' => $this->now,
            'price_usd' => 0.02,
            'market_cap' => 20_000_000.0,
            'fdv' => 24_000_000.0,
            'liquidity_usd' => 800_000.0,
            'volume_h24' => 2_000_000.0,
            'price_change_h24' => 2.0,
            'txns_h24' => 3_000,
            'buys_h24' => 1_600,
            'sells_h24' => 1_400,
            'primary_pair_address' => 'pair-abc',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays(10),
        ], $snapshot));

        $token->refresh();

        $skipRisk = $opts['skipRisk'] ?? false;
        if (! $skipRisk) {
            $this->passRisk($token, $opts['riskLevel'] ?? RiskAssessment::LEVEL_LOWER);
        }
        // A holder signal hangs off the risk assessment — only attachable when
        // one exists. `skipRisk` tests build both by hand afterwards.
        if (! $skipRisk && ! ($opts['skipHolders'] ?? false)) {
            $this->setHolders($token, array_key_exists('holders', $opts) ? $opts['holders'] : 8_000);
        }

        return $token;
    }

    /** Add / replace the `holder_count` risk signal. `null` count = UNKNOWN. */
    private function setHolders(Token $token, ?int $count): void
    {
        $assessment = $token->riskAssessment()->firstOrFail();
        $assessment->signals()->where('signal_key', 'holder_count')->delete();
        $assessment->signals()->create([
            'token_id' => $token->id,
            'signal_key' => 'holder_count',
            'signal_group' => RiskSignal::GROUP_HOLDER_DISTRIBUTION,
            'state' => $count === null ? RiskSignal::STATE_UNKNOWN : RiskSignal::STATE_MEASURED,
            'value' => $count === null ? null : (string) $count,
            'numeric_value' => $count === null ? null : (float) $count,
            'unit' => $count === null ? null : 'holders',
            'severity' => RiskSignal::SEVERITY_NONE,
            'source' => 'goplus',
            'source_checked_at' => $this->now,
            'explanation' => $count === null ? 'Holder count unavailable.' : 'Holder count relative to market cap is in a normal range.',
        ]);
        $token->load('riskAssessment.signals');
    }

    private function crossing(Token $token, string $type, CarbonImmutable $crossedAt, float $value = 6_000_000.0): QualificationEvent
    {
        /** @var QualificationEvent $event */
        $event = QualificationEvent::query()->create([
            'token_id' => $token->id,
            'type' => $type,
            'crossed_at' => $crossedAt,
            'threshold_usd' => 5_000_000,
            'evidence_status' => $type,
            'source' => $type === QualificationEvent::TYPE_HISTORICAL_VERIFIED ? 'coingecko' : 'dexscreener',
            'market_cap_value' => $value,
        ]);

        return $event;
    }

    private function symbols(string $query = ''): array
    {
        return collect($this->getJson('/api/memecoins/recently-crossed'.$query)->assertOk()->json('data'))
            ->pluck('symbol')->all();
    }

    // --- crossing window ---------------------------------------------------

    #[Test]
    public function it_returns_memecoins_whose_crossing_is_within_the_last_thirty_days(): void
    {
        $recent = $this->token(['symbol' => 'RECENT']);
        $this->crossing($recent, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(12));

        $old = $this->token(['symbol' => 'OLD']);
        $this->crossing($old, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(31));

        $res = $this->getJson('/api/memecoins/recently-crossed')->assertOk();
        $res->assertJsonPath('meta.days', 30);
        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.symbol', 'RECENT');
    }

    #[Test]
    public function a_crossing_exactly_thirty_days_ago_is_eligible_and_thirty_one_days_is_not(): void
    {
        $in = $this->token(['symbol' => 'IN']);
        $this->crossing($in, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(30)->addMinutes(5));

        $out = $this->token(['symbol' => 'OUT']);
        $this->crossing($out, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(31));

        $this->assertSame(['IN'], $this->symbols());
    }

    #[Test]
    public function the_representative_crossing_drives_window_membership(): void
    {
        // CURRENT_OBSERVATION 6h ago, but the verified crossing was 35 days ago.
        $token = $this->token(['symbol' => 'VERIFIEDOLD', 'observed_peak_market_cap' => 15_000_000.0]);
        $token->update([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 15_000_000.0,
            'historical_peak_value_at' => $this->now->subDays(35),
        ]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));
        $this->crossing($token, QualificationEvent::TYPE_HISTORICAL_VERIFIED, $this->now->subDays(35));

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function an_age_over_thirty_days_token_is_excluded_even_with_a_recent_crossing(): void
    {
        // "crossed within 30 days" is NOT "pool younger than 30 days".
        $token = $this->token(['symbol' => 'AGED', 'earliest_pair_created_at' => $this->now->subDays(45)]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());
        $this->assertDatabaseCount('qualification_events', 1); // record kept
    }

    // --- market cap band -------------------------------------------------

    /**
     * `$5,000,000 <= peak < $1,000,000,000` — floor INCLUSIVE, ceiling EXCLUSIVE.
     */
    #[Test]
    public function the_market_cap_band_floor_is_inclusive_and_the_one_billion_ceiling_is_exclusive(): void
    {
        $cases = [
            ['UNDER5M', 4_999_999.0, false],
            ['AT5M', 5_000_000.0, true],
            ['AT200M', 200_000_000.0, true],
            ['AT500M', 500_000_000.0, true],
            ['UNDER1B', 999_999_999.0, true],
            ['AT1B', 1_000_000_000.0, false],
            ['OVER1B', 1_200_000_000.0, false],
        ];

        foreach ($cases as [$symbol, $peak, $_]) {
            $current = min($peak, 20_000_000.0);
            $token = $this->token([
                'symbol' => $symbol,
                'observed_peak_market_cap' => $peak,
            ], [
                'market_cap' => $current,
                'volume_h24' => $current * 0.1,
                'liquidity_usd' => max(50_000.0, $current * 0.02),
            ]);
            $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6), $peak);
        }

        $returned = $this->symbols();
        foreach ($cases as [$symbol, $_, $shouldQualify]) {
            $shouldQualify
                ? $this->assertContains($symbol, $returned, "$symbol should qualify")
                : $this->assertNotContains($symbol, $returned, "$symbol should NOT qualify");
        }
    }

    #[Test]
    public function a_cooled_token_below_five_million_now_still_appears(): void
    {
        $token = $this->token(
            ['symbol' => 'DUMPED', 'observed_peak_market_cap' => 9_000_000.0],
            ['market_cap' => 1_800_000.0, 'volume_h24' => 60_000.0, 'liquidity_usd' => 120_000.0],
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(3), 9_000_000.0);

        $res = $this->getJson('/api/memecoins/recently-crossed')->assertOk();
        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.symbol', 'DUMPED');
        $res->assertJsonPath('data.0.status', 'COOLED');
    }

    #[Test]
    public function an_estimate_only_token_never_appears(): void
    {
        $token = $this->token(['symbol' => 'ESTONLY', 'observed_peak_market_cap' => 2_000_000.0], [], ['skipRisk' => true, 'skipHolders' => true]);
        HistoricalPeakEvidence::query()->create([
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'peak_value_usd' => 40_000_000.0,
            'evidence_source' => 'geckoterminal',
            'evidence_basis' => 'fdv_total_supply',
            'checked_at' => $this->now,
        ]);

        $this->assertSame([], $this->symbols());
    }

    // --- risk / security gate ------------------------------------------

    #[Test]
    public function a_high_risk_token_is_rejected(): void
    {
        $token = $this->token(['symbol' => 'MINT'], [], ['skipRisk' => true]);
        $this->failRisk($token, RiskAssessment::LEVEL_HIGH);
        $this->setHolders($token, 8_000);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function a_critical_honeypot_token_is_rejected(): void
    {
        $token = $this->token(['symbol' => 'TRAP'], [], ['skipRisk' => true]);
        $this->failRisk($token, RiskAssessment::LEVEL_CRITICAL);
        $this->setHolders($token, 8_000);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function a_risk_unknown_token_is_rejected(): void
    {
        $token = $this->token(['symbol' => 'DARK'], [], ['skipRisk' => true]);
        $this->failRisk($token, RiskAssessment::LEVEL_UNKNOWN);
        $this->setHolders($token, 8_000);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function an_unscreened_token_is_rejected(): void
    {
        $token = $this->token(['symbol' => 'NOSCAN'], [], ['skipRisk' => true, 'skipHolders' => true]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function a_medium_risk_token_passes_the_risk_gate(): void
    {
        $token = $this->token(['symbol' => 'MED'], [], ['riskLevel' => RiskAssessment::LEVEL_MEDIUM]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame(['MED'], $this->symbols());
    }

    // --- holder participation -----------------------------------------

    #[Test]
    public function an_extreme_holder_anomaly_is_rejected(): void
    {
        // $50M current MC, 20 holders => 0.4 per $1M.
        $token = $this->token(['symbol' => 'FEW'], ['market_cap' => 50_000_000.0, 'volume_h24' => 5_000_000.0, 'liquidity_usd' => 1_000_000.0], ['holders' => 20]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6), 50_000_000.0);

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function a_reasonable_holder_to_market_cap_ratio_passes(): void
    {
        // $50M current MC, 8,000 holders => 160 per $1M.
        $token = $this->token(['symbol' => 'MANY'], ['market_cap' => 50_000_000.0, 'volume_h24' => 5_000_000.0, 'liquidity_usd' => 1_000_000.0], ['holders' => 8_000]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6), 50_000_000.0);

        $this->assertSame(['MANY'], $this->symbols());
    }

    #[Test]
    public function missing_holder_evidence_is_rejected_by_default(): void
    {
        // Default (reverted after the pippo incident): `require_holder_evidence`
        // is true — a covered chain always has a holder count.
        $token = $this->token(['symbol' => 'NOHOLD'], [], ['holders' => null]); // UNKNOWN holder_count signal
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());

        // ...but allowed when an operator relaxes the policy.
        config()->set('dexscreener.recent_crossing.require_holder_evidence', false);
        $this->assertSame(['NOHOLD'], $this->symbols());
    }

    #[Test]
    public function a_measured_holder_count_below_the_calibrated_floor_is_still_rejected(): void
    {
        // 25 holders per $1M is the calibrated floor. $20M MC + 400 holders =
        // 20 per $1M -> reject.
        $token = $this->token(['symbol' => 'THINHOLD'], ['market_cap' => 20_000_000.0], ['holders' => 400]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());

        // 600 holders = 30 per $1M -> clears the floor.
        $ok = $this->token(['symbol' => 'OKHOLD'], ['market_cap' => 20_000_000.0], ['holders' => 600]);
        $this->crossing($ok, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertContains('OKHOLD', $this->symbols());
    }

    // --- unscreenable chain (reverted after the pippo incident) -------

    #[Test]
    public function a_token_on_an_unscreenable_chain_is_excluded(): void
    {
        // robinhood is absent from risk.goplus_chain_map — we cannot rule out a
        // honeypot / mint / blacklist, so it is not listed regardless of how
        // clean everything else looks.
        $token = $this->token(
            ['symbol' => 'RHOOD', 'chain_id' => 'robinhood'],
            ['market_cap' => 20_000_000.0, 'volume_h24' => 900_000.0, 'liquidity_usd' => 400_000.0],
            ['skipRisk' => true, 'skipHolders' => true],
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols('?chain=robinhood'));

        // Even a (defensively) clean risk assessment does not help.
        $this->passRisk($token, RiskAssessment::LEVEL_LOWER);
        $this->setHolders($token, 8_000);
        $this->assertSame([], $this->symbols('?chain=robinhood'));
    }

    #[Test]
    public function a_covered_chain_risk_unknown_token_is_still_rejected(): void
    {
        $token = $this->token(['symbol' => 'SOLDARK', 'chain_id' => 'solana'], [], ['skipRisk' => true, 'skipHolders' => true]);
        $this->failRisk($token, RiskAssessment::LEVEL_UNKNOWN);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());
    }

    // --- 24h volume vs current market cap -----------------------------

    #[Test]
    public function the_fifty_million_market_cap_with_seven_thousand_dollar_volume_is_rejected(): void
    {
        $token = $this->token(
            ['symbol' => 'DEADVOL'],
            ['market_cap' => 50_000_000.0, 'volume_h24' => 7_200.0, 'liquidity_usd' => 900_000.0],
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6), 50_000_000.0);

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function zero_or_missing_24h_volume_is_rejected(): void
    {
        $zero = $this->token(['symbol' => 'ZEROVOL'], ['volume_h24' => 0.0]);
        $this->crossing($zero, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $missing = $this->token(['symbol' => 'NULLVOL'], ['volume_h24' => null]);
        $this->crossing($missing, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function a_healthy_volume_to_market_cap_ratio_passes_and_very_high_volume_is_not_rejected(): void
    {
        $healthy = $this->token(['symbol' => 'HEALTHY'], ['market_cap' => 30_000_000.0, 'volume_h24' => 900_000.0]); // 0.03
        $this->crossing($healthy, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6), 30_000_000.0);

        $huge = $this->token(['symbol' => 'HUGE'], ['market_cap' => 30_000_000.0, 'volume_h24' => 120_000_000.0]); // 4x MC
        $this->crossing($huge, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6), 30_000_000.0);

        $this->assertEqualsCanonicalizing(['HEALTHY', 'HUGE'], $this->symbols());
    }

    // --- liquidity ---------------------------------------------------

    #[Test]
    public function zero_or_clearly_insufficient_liquidity_is_rejected(): void
    {
        $zero = $this->token(['symbol' => 'NOLIQ'], ['liquidity_usd' => 0.0]);
        $this->crossing($zero, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        // $20M MC, $5K liquidity — below the $10K absolute floor.
        $thin = $this->token(['symbol' => 'THINLIQ'], ['market_cap' => 20_000_000.0, 'liquidity_usd' => 5_000.0]);
        $this->crossing($thin, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        // $50M MC, $20K liquidity — clears the absolute floor but fails the
        // relative floor ($50M × 0.001 = $50K).
        $relThin = $this->token(['symbol' => 'RELTHIN'], ['market_cap' => 50_000_000.0, 'volume_h24' => 5_000_000.0, 'liquidity_usd' => 20_000.0], ['holders' => 8_000]);
        $this->crossing($relThin, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6), 50_000_000.0);

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function sufficient_liquidity_passes(): void
    {
        $token = $this->token(['symbol' => 'DEEP'], ['liquidity_usd' => 450_000.0]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame(['DEEP'], $this->symbols());
    }

    // --- discovery freshness --------------------------------------

    #[Test]
    public function a_token_the_discovery_pipeline_has_not_observed_recently_is_rejected(): void
    {
        $stale = $this->token(['symbol' => 'STALE', 'last_observed_at' => $this->now->subDays(4)]);
        $this->crossing($stale, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $fresh = $this->token(['symbol' => 'FRESH', 'last_observed_at' => $this->now->subHours(2)]);
        $this->crossing($fresh, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame(['FRESH'], $this->symbols());
    }

    // --- combined regression fixture ----------------------------

    #[Test]
    public function the_combined_bad_fixture_cannot_appear(): void
    {
        // $50M current MC, $7.2K 24h volume, only 60 holders.
        $token = $this->token(
            ['symbol' => 'JUNK', 'observed_peak_market_cap' => 55_000_000.0],
            ['market_cap' => 50_000_000.0, 'volume_h24' => 7_200.0, 'liquidity_usd' => 15_000.0],
            ['holders' => 60],
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(2), 50_000_000.0);

        $this->assertSame([], $this->symbols());
    }

    // --- endpoint contract -----------------------------------

    #[Test]
    public function a_token_at_or_above_five_million_now_shows_as_active(): void
    {
        $token = $this->token(['symbol' => 'HOT'], ['market_cap' => 8_800_000.0, 'volume_h24' => 400_000.0, 'liquidity_usd' => 200_000.0]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(2), 8_800_000.0);

        $this->getJson('/api/memecoins/recently-crossed')->assertOk()
            ->assertJsonPath('data.0.symbol', 'HOT')
            ->assertJsonPath('data.0.status', 'ACTIVE');
    }

    #[Test]
    public function results_are_sorted_newest_crossing_first(): void
    {
        $a = $this->token(['symbol' => 'A']);
        $this->crossing($a, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(20));
        $b = $this->token(['symbol' => 'B']);
        $this->crossing($b, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(2));
        $c = $this->token(['symbol' => 'C']);
        $this->crossing($c, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(5));

        $this->assertSame(['B', 'C', 'A'], $this->symbols());
    }

    #[Test]
    public function the_endpoint_is_read_only_and_makes_no_provider_calls(): void
    {
        Http::fake();
        $token = $this->token();
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(3));

        DB::enableQueryLog();
        $this->getJson('/api/memecoins/recently-crossed')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        Http::assertNothingSent();
        $this->assertLessThanOrEqual(8, count($queries));
        $this->assertDatabaseCount('qualification_events', 1);
    }

    #[Test]
    public function the_chain_filter_narrows_the_results(): void
    {
        $sol = $this->token(['symbol' => 'SOL', 'chain_id' => 'solana']);
        $this->crossing($sol, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));
        $bsc = $this->token(['symbol' => 'BNB', 'chain_id' => 'bsc']);
        $this->crossing($bsc, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame(['SOL'], $this->symbols('?chain=solana'));
    }

    // --- empirical reference-set compatibility -------------------------

    /**
     * The 5 COVERED-CHAIN survivors from the 9-token reference set, shaped to
     * their observed metrics and forced to age ≤ 30d, MUST clear the calibrated
     * profile. Excluded: #5 (WBNB — MC $1.2B, ~3y, violates the fixed bands) and
     * the 3 Robinhood tokens (CASHCAT / Juggernaut / AI) — Robinhood is
     * unscreenable, so after the pippo incident it is correctly rejected.
     * Bicat's peak is old (`observedPeakAt`) so it shows as COOLED, not a
     * post-crossing collapse.
     *
     * @return array<string, array{0:string,1:string,2:float,3:float,4:float,5:?int,6:float,7:int}>
     */
    public static function referenceProfiles(): array
    {
        // name, chain, currentMc, liquidity, volume24, holders, observedPeak, observedPeakDaysAgo
        return [
            'apeonfone (fone) / solana' => ['fone', 'solana', 18_200_000, 748_000, 11_600_000, 27_726, 22_000_000, 4],
            'MarsCoin / bsc' => ['MARS', 'bsc', 66_700_000, 1_250_000, 4_210_000, 36_817, 90_000_000, 20],
            'Catecoin (CATE) / solana' => ['CATE', 'solana', 33_900_000, 1_760_000, 4_510_000, 117_971, 45_000_000, 12],
            '牛来 (NiuLai) / bsc' => ['NIULAI', 'bsc', 72_600_000, 1_200_000, 2_530_000, 52_625, 89_000_000, 6],
            'Bicat / bsc (COOLED)' => ['BICAT', 'bsc', 160_000, 50_600, 119_000, 4_166, 9_000_000, 20],
        ];
    }

    #[Test]
    #[DataProvider('referenceProfiles')]
    public function every_covered_chain_reference_survivor_clears_the_calibrated_profile(
        string $symbol,
        string $chain,
        float $currentMc,
        float $liquidity,
        float $volume,
        int $holders,
        float $observedPeak,
        int $observedPeakDaysAgo,
    ): void {
        $token = $this->token(
            [
                'symbol' => $symbol,
                'chain_id' => $chain,
                'earliest_pair_created_at' => $this->now->subDays(15), // force ≤ 30d
                'observed_peak_market_cap' => $observedPeak,
                'observed_peak_market_cap_at' => $this->now->subDays($observedPeakDaysAgo),
            ],
            [
                'market_cap' => $currentMc,
                'volume_h24' => $volume,
                'liquidity_usd' => $liquidity,
                'price_change_h24' => -8.0,
            ],
            ['riskLevel' => RiskAssessment::LEVEL_LOWER, 'holders' => $holders],
        );

        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(10), $observedPeak);

        $this->assertContains(
            $symbol,
            $this->symbols(),
            "reference survivor {$symbol} was rejected by the Recently Crossed profile",
        );
    }

    #[Test]
    public function the_three_robinhood_reference_survivors_are_now_excluded(): void
    {
        // CASHCAT / Juggernaut / Artificial Inu — mature, healthy, but on an
        // unscreenable chain. Accepted trade-off of the pippo-incident revert.
        foreach ([
            ['CASHCAT', 258_000_000.0, 4_490_000.0, 9_490_000.0, 300_000_000.0],
            ['JUGGERNAUT', 6_950_000.0, 511_000.0, 1_980_000.0, 12_000_000.0],
            ['AI', 217_000_000.0, 5_280_000.0, 31_200_000.0, 260_000_000.0],
        ] as [$symbol, $mc, $liq, $vol, $peak]) {
            $token = $this->token(
                ['symbol' => $symbol, 'chain_id' => 'robinhood', 'observed_peak_market_cap' => $peak],
                ['market_cap' => $mc, 'liquidity_usd' => $liq, 'volume_h24' => $vol, 'price_change_h24' => -5.0],
                ['skipRisk' => true, 'skipHolders' => true],
            );
            $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(10), $peak);
        }

        $this->assertSame([], $this->symbols('?chain=robinhood'));
    }

    // --- red-flag gates (2026-09 pippo incident) ----------------------

    #[Test]
    public function extreme_positive_24h_momentum_is_rejected(): void
    {
        $pump = $this->token(['symbol' => 'PUMP'], ['price_change_h24' => 400.0]);
        $this->crossing($pump, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(2));

        $calm = $this->token(['symbol' => 'CALM'], ['price_change_h24' => 180.0]);
        $this->crossing($calm, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(2));

        $this->assertSame(['CALM'], $this->symbols());
    }

    #[Test]
    public function a_calm_or_negative_24h_move_still_passes(): void
    {
        $token = $this->token(['symbol' => 'DOWN'], ['price_change_h24' => -19.9]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(3));

        $this->assertSame(['DOWN'], $this->symbols());
    }

    #[Test]
    public function a_missing_24h_change_is_not_treated_as_a_red_flag(): void
    {
        $token = $this->token(['symbol' => 'NOCHG'], ['price_change_h24' => null]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(3));

        $this->assertSame(['NOCHG'], $this->symbols());
    }

    #[Test]
    public function a_post_crossing_collapse_within_the_lookback_is_rejected(): void
    {
        // pippo: $12.4M peak ~1h ago -> $1,300 now.
        $token = $this->token(
            [
                'symbol' => 'RUG',
                'observed_peak_market_cap' => 12_400_000.0,
                'observed_peak_market_cap_at' => $this->now->subHours(1),
            ],
            ['market_cap' => 1_300.0, 'volume_h24' => 900_000.0, 'liquidity_usd' => 120_000.0, 'price_change_h24' => -2.0],
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(1), 12_400_000.0);

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function a_peak_older_than_the_lookback_still_shows_as_cooled(): void
    {
        $token = $this->token(
            [
                'symbol' => 'OLDCOOL',
                'observed_peak_market_cap' => 12_000_000.0,
                'observed_peak_market_cap_at' => $this->now->subDays(6), // > 72h
            ],
            ['market_cap' => 900_000.0, 'volume_h24' => 40_000.0, 'liquidity_usd' => 90_000.0, 'price_change_h24' => -3.0],
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(5), 12_000_000.0);

        $res = $this->getJson('/api/memecoins/recently-crossed')->assertOk();
        $res->assertJsonPath('data.0.symbol', 'OLDCOOL');
        $res->assertJsonPath('data.0.status', 'COOLED');
    }

    #[Test]
    public function a_shallow_pullback_from_a_recent_peak_still_passes(): void
    {
        // 0.72 of a peak reached 6h ago — a normal wobble, not a collapse.
        $token = $this->token(
            [
                'symbol' => 'WOBBLE',
                'observed_peak_market_cap' => 25_000_000.0,
                'observed_peak_market_cap_at' => $this->now->subHours(6),
            ],
            ['market_cap' => 18_000_000.0, 'volume_h24' => 2_000_000.0, 'liquidity_usd' => 800_000.0, 'price_change_h24' => -12.0],
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->assertSame(['WOBBLE'], $this->symbols());
    }

    /**
     * @return array<string, array{0:string,1:array<string,mixed>,2:array<string,mixed>}>
     */
    public static function pumpAndDumpProfiles(): array
    {
        return [
            // Liquidity bumped over the $10K floor so the COLLAPSE gate (not the
            // liquidity gate) is what does the work here.
            'PIPPO — collapsed' => ['PIPPO', ['observed_peak_market_cap' => 12_398_995.0], ['market_cap' => 1_299.0, 'liquidity_usd' => 120_000.0, 'volume_h24' => 895_118.0, 'price_change_h24' => -1.0]],
            'FAMI — momentum' => ['FAMI', ['observed_peak_market_cap' => 35_953_388.0], ['market_cap' => 18_199_829.0, 'liquidity_usd' => 230_467.0, 'volume_h24' => 8_215_193.0, 'price_change_h24' => 307.0]],
            'JINQIAN — momentum' => ['JINQIAN', ['observed_peak_market_cap' => 48_838_745.0], ['market_cap' => 14_025_438.0, 'liquidity_usd' => 394_491.0, 'volume_h24' => 6_544_723.0, 'price_change_h24' => 2_689.0]],
        ];
    }

    #[Test]
    #[DataProvider('pumpAndDumpProfiles')]
    public function pump_and_dump_reference_profiles_are_all_rejected(string $symbol, array $attrs, array $snapshot): void
    {
        // Even hypothetically on a COVERED chain (so the unscreenable-chain gate
        // does not do the work) the momentum / collapse gates catch them.
        $token = $this->token(
            array_replace(['symbol' => $symbol, 'chain_id' => 'bsc', 'observed_peak_market_cap_at' => $this->now->subHours(1)], $attrs),
            $snapshot,
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(1), (float) $attrs['observed_peak_market_cap']);

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function the_min_age_hours_lever_is_off_by_default_and_can_be_set(): void
    {
        $young = $this->token(['symbol' => 'FRESH', 'earliest_pair_created_at' => $this->now->subHours(3)]);
        $this->crossing($young, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(2));

        // Default (0) — a 3-hour-old pair is fine on its own.
        $this->assertSame(['FRESH'], $this->symbols());

        // Operator sets a 6h floor.
        config()->set('dexscreener.recent_crossing.min_age_hours', 6);
        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function two_records_of_the_same_ticker_collapse_to_the_saner_one(): void
    {
        // Same (chain, symbol, name), different contracts. The "real" one has a
        // deeper relative-liquidity structure; the copycat is thin.
        $real = $this->token(
            ['symbol' => 'DUPE', 'name' => 'Dupe Coin', 'token_address' => 'RealAddr1', 'observed_peak_market_cap' => 40_000_000.0],
            ['market_cap' => 30_000_000.0, 'liquidity_usd' => 1_200_000.0, 'volume_h24' => 900_000.0],
        );
        $this->crossing($real, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(4), 40_000_000.0);

        $copycat = $this->token(
            ['symbol' => 'DUPE', 'name' => 'Dupe Coin', 'token_address' => 'CopyAddr2', 'observed_peak_market_cap' => 8_000_000.0],
            ['market_cap' => 6_000_000.0, 'liquidity_usd' => 90_000.0, 'volume_h24' => 200_000.0],
        );
        $this->crossing($copycat, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(2), 8_000_000.0);

        $rows = $this->getJson('/api/memecoins/recently-crossed')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($real->id, $rows[0]['id']);
    }
}
