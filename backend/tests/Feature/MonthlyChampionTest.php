<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\HistoricalPeakEvidence;
use App\Models\MonthlyRanking;
use App\Models\PumpEvent;
use App\Models\Token;
use App\Services\Ranking\ChainBucket;
use App\Services\Ranking\MonthlyChampionService;
use App\Services\Ranking\MonthWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 22 (corrected) — "Monthly Top Memecoins".
 *
 * For EVERY calendar month the top-1 performing memecoin inside each of FIVE
 * fixed buckets: solana, robinhood, bsc, base, other. At most one champion per
 * (year, month, chain_bucket). NO global monthly winner. The winner is scored
 * primarily on observed market-cap growth (baseline -> peak within the month) —
 * NOT the biggest / highest-cap / first to $5M. Risk score and AI are never
 * used.
 */
class MonthlyChampionTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    /** July 2026 — the previous completed month relative to `$now`. */
    private MonthWindow $july;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-31T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        $this->july = MonthWindow::of(2026, 7);

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('ranking.observation_interval_minutes', 4320); // 3 days — a few spread snapshots clear coverage
        config()->set('ranking.min_observation_coverage', 0.25);
        config()->set('ranking.weights', ['growth' => 0.60, 'expansion' => 0.25, 'activity' => 0.15]);
        config()->set('ranking.growth_reference', 20.0);
        config()->set('ranking.expansion_reference', 25.0);
        config()->set('ranking.research.providers', ['internal']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- fixtures --------------------------------------------------------

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function token(string $chainId = 'solana', array $attrs = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => $chainId,
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'TKN'.Str::random(3),
            'name' => 'Test Token',
            'image_url' => 'https://img.example/x.png',
            'earliest_pair_created_at' => $this->july->start->addDay(),
            'first_observed_at' => $this->july->start->addDay(),
            'last_observed_at' => $this->july->endExclusive->subDay(),
            'observed_peak_market_cap' => 40_000_000.0,
            'observed_peak_market_cap_at' => $this->july->start->addDays(10),
        ], $attrs));

        HistoricalPeakEvidence::query()->updateOrCreate(['token_id' => $token->id], array_replace([
            'status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
            'peak_value_usd' => (float) ($attrs['observed_peak_market_cap'] ?? 40_000_000.0),
            'peak_observed_at' => $this->july->start->addDays(10),
            'evidence_source' => 'dexscreener',
            'evidence_basis' => 'current_market_cap',
            'checked_at' => $this->now,
        ], $attrs['evidence'] ?? []));

        return $token->refresh();
    }

    private function trajectory(Token $token, float $from, float $to, int $count = 8, array $snapshotOverrides = []): void
    {
        $spanSeconds = $this->july->endExclusive->getTimestamp() - $this->july->start->getTimestamp();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $frac = $count === 1 ? 0.0 : $i / ($count - 1);
            $mc = $from + ($to - $from) * $frac;
            $rows[] = array_replace([
                'token_id' => $token->id,
                'observed_at' => $this->july->start->addSeconds((int) round($spanSeconds * ($i + 0.5) / $count)),
                'price_usd' => 0.01,
                'market_cap' => $mc,
                'fdv' => $mc * 1.2,
                'liquidity_usd' => 400_000.0,
                'volume_h24' => 1_500_000.0,
                'price_change_h24' => 5.0,
                'txns_h24' => 3_000,
                'buys_h24' => 1_700,
                'sells_h24' => 1_300,
                'primary_pair_address' => 'pair',
                'primary_dex_id' => 'raydium',
                'earliest_pair_created_at' => $token->earliest_pair_created_at,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ], $snapshotOverrides);
        }
        DB::table('market_snapshots')->insert($rows);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotRow(Token $token, string $observedAt, float $marketCap): array
    {
        return [
            'token_id' => $token->id,
            'observed_at' => CarbonImmutable::parse($observedAt),
            'price_usd' => 0.01,
            'market_cap' => $marketCap,
            'fdv' => $marketCap * 1.2,
            'liquidity_usd' => 400_000.0,
            'volume_h24' => 1_500_000.0,
            'price_change_h24' => 5.0,
            'txns_h24' => 3_000,
            'buys_h24' => 1_700,
            'sells_h24' => 1_300,
            'primary_pair_address' => 'pair',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $token->earliest_pair_created_at,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
    }

    private function service(): MonthlyChampionService
    {
        return app(MonthlyChampionService::class);
    }

    /** @return Collection<int, MonthlyRanking> */
    private function finalizeJuly(bool $force = false): Collection
    {
        return $this->service()->finalizeMonth(2026, 7, $force, $this->now)->keyBy('chain_bucket');
    }

    private function julyBucket(string $bucket, bool $force = false): MonthlyRanking
    {
        return $this->finalizeJuly($force)->get($bucket);
    }

    // ==== 10: chain-bucket mapping ====================================

    #[Test]
    public function chain_bucket_mapping_is_deterministic(): void
    {
        $this->assertSame(ChainBucket::SOLANA, ChainBucket::forChain('solana'));
        $this->assertSame(ChainBucket::SOLANA, ChainBucket::forChain('SOLANA'));
        $this->assertSame(ChainBucket::ROBINHOOD, ChainBucket::forChain('robinhood'));
        $this->assertSame(ChainBucket::BSC, ChainBucket::forChain('bsc'));
        $this->assertSame(ChainBucket::BASE, ChainBucket::forChain('base'));
        $this->assertSame(ChainBucket::OTHER, ChainBucket::forChain('arbitrum'));
        $this->assertSame(ChainBucket::OTHER, ChainBucket::forChain('ethereum'));
        $this->assertSame(ChainBucket::OTHER, ChainBucket::forChain(null));
        $this->assertSame(['solana', 'robinhood', 'bsc', 'base', 'other'], ChainBucket::ALL);
    }

    // ==== 3, 4-9: bucket isolation ===================================

    #[Test]
    public function each_bucket_gets_its_own_isolated_champion(): void
    {
        $sol = $this->token('solana', ['symbol' => 'SOL1']);
        $this->trajectory($sol, 8_000_000, 40_000_000); // +400%
        $rh = $this->token('robinhood', ['symbol' => 'RH1']);
        $this->trajectory($rh, 6_000_000, 30_000_000); // +400%
        $bsc = $this->token('bsc', ['symbol' => 'BSC1']);
        $this->trajectory($bsc, 5_000_000, 15_000_000); // +200%
        $base = $this->token('base', ['symbol' => 'BASE1']);
        $this->trajectory($base, 8_000_000, 16_000_000); // +100%
        $arb = $this->token('arbitrum', ['symbol' => 'ARB1']);
        $this->trajectory($arb, 7_000_000, 21_000_000); // +200%, "other"

        $buckets = $this->finalizeJuly();

        $this->assertSame($sol->id, $buckets->get('solana')->token_id);
        $this->assertSame($rh->id, $buckets->get('robinhood')->token_id);
        $this->assertSame($bsc->id, $buckets->get('bsc')->token_id);
        $this->assertSame($base->id, $buckets->get('base')->token_id);
        $this->assertSame($arb->id, $buckets->get('other')->token_id, 'a non-core chain lands in "other"');
        $this->assertSame('arbitrum', $buckets->get('other')->token->chain_id, 'the token keeps its real chain_id');
    }

    #[Test]
    public function the_other_bucket_ranks_across_all_non_core_chains(): void
    {
        $arb = $this->token('arbitrum', ['symbol' => 'ARBWIN']);
        $this->trajectory($arb, 6_000_000, 36_000_000); // +500%
        $poly = $this->token('polygon', ['symbol' => 'POLY']);
        $this->trajectory($poly, 8_000_000, 16_000_000); // +100%

        $other = $this->julyBucket('other');

        $this->assertSame($arb->id, $other->token_id);
        // The core buckets get nothing from these non-core tokens.
        $this->assertNull($this->julyBucket('solana')->token_id);
    }

    #[Test]
    public function only_one_row_exists_per_month_per_bucket(): void
    {
        $sol = $this->token('solana');
        $this->trajectory($sol, 8_000_000, 40_000_000);

        $this->finalizeJuly();
        $this->finalizeJuly(force: true);
        $this->finalizeJuly(force: true);

        $this->assertSame(1, MonthlyRanking::query()->where('year', 2026)->where('month', 7)->where('chain_bucket', 'solana')->count());
        $this->assertSame(5, MonthlyRanking::query()->where('year', 2026)->where('month', 7)->count(), 'exactly five bucket rows');
    }

    #[Test]
    public function the_same_symbol_on_different_chains_is_handled_separately(): void
    {
        $sol = $this->token('solana', ['symbol' => 'SAME', 'token_address' => 'AddrOne']);
        $this->trajectory($sol, 8_000_000, 40_000_000);
        $base = $this->token('base', ['symbol' => 'SAME', 'token_address' => 'AddrOne']);
        $this->trajectory($base, 6_000_000, 12_000_000);

        $buckets = $this->finalizeJuly();

        $this->assertSame($sol->id, $buckets->get('solana')->token_id);
        $this->assertSame($base->id, $buckets->get('base')->token_id);
        $this->assertSame('solana', $buckets->get('solana')->token->chain_id);
        $this->assertSame('base', $buckets->get('base')->token->chain_id);
    }

    // ==== 11-14: scoring ===========================================

    #[Test]
    public function the_score_is_deterministic_across_runs(): void
    {
        $token = $this->token('solana');
        $this->trajectory($token, 8_000_000, 40_000_000);

        $a = $this->service()->computeCandidates($this->july, 'solana', $this->now);
        $b = $this->service()->computeCandidates($this->july, 'solana', $this->now);

        $this->assertSame($a[0]->performanceScore, $b[0]->performanceScore);
        $this->assertSame($a[0]->activityScore, $b[0]->activityScore);
        $this->assertSame($a[0]->marketCapGrowthPct, $b[0]->marketCapGrowthPct);
    }

    #[Test]
    public function market_cap_growth_and_peak_expansion_are_computed_correctly(): void
    {
        $token = $this->token('solana', ['symbol' => 'GROW']);
        $this->trajectory($token, 8_000_000, 40_000_000); // baseline 8M, peak 40M

        $row = $this->julyBucket('solana');

        $this->assertEqualsWithDelta(8_000_000.0, $row->baseline_market_cap, 1);
        $this->assertEqualsWithDelta(40_000_000.0, $row->peak_market_cap, 1);
        $this->assertEqualsWithDelta(400.0, $row->market_cap_growth_pct, 0.5);
        $this->assertEqualsWithDelta(5.0, $row->peak_expansion_ratio, 0.01);
        $this->assertGreaterThan(0, $row->performance_score);
        $this->assertLessThanOrEqual(100, $row->performance_score);
    }

    #[Test]
    public function stronger_relative_growth_beats_a_bigger_but_flatter_token(): void
    {
        $flatBig = $this->token('solana', ['symbol' => 'BIGFLAT', 'observed_peak_market_cap' => 120_000_000.0]);
        $this->trajectory($flatBig, 100_000_000, 120_000_000); // +20%
        $grower = $this->token('solana', ['symbol' => 'GROWER', 'observed_peak_market_cap' => 30_000_000.0]);
        $this->trajectory($grower, 6_000_000, 30_000_000); // +400%

        $this->assertSame($grower->id, $this->julyBucket('solana')->token_id);
    }

    #[Test]
    public function activity_never_lets_a_flat_token_beat_a_grower(): void
    {
        $flat = $this->token('solana', ['symbol' => 'FLATBUSY']);
        $this->trajectory($flat, 10_000_000, 10_500_000, 8, [
            'volume_h24' => 50_000_000.0, 'liquidity_usd' => 20_000_000.0,
            'txns_h24' => 200_000, 'price_change_h24' => 90.0,
        ]);
        $grower = $this->token('solana', ['symbol' => 'QUIETGROW']);
        $this->trajectory($grower, 6_000_000, 24_000_000, 8, [
            'volume_h24' => 300_000.0, 'liquidity_usd' => 150_000.0,
            'txns_h24' => 800, 'price_change_h24' => 3.0,
        ]);

        $this->assertSame($grower->id, $this->julyBucket('solana')->token_id);
    }

    #[Test]
    public function the_tie_break_is_deterministic(): void
    {
        $a = $this->token('solana', ['symbol' => 'TIEA']);
        $this->trajectory($a, 8_000_000, 24_000_000);
        $b = $this->token('solana', ['symbol' => 'TIEB']);
        $this->trajectory($b, 8_000_000, 24_000_000);

        $this->assertSame(min($a->id, $b->id), $this->julyBucket('solana')->token_id);
    }

    // ==== 15-20: eligibility =======================================

    #[Test]
    public function a_token_observed_too_sparsely_is_a_best_supported_candidate_not_finalized(): void
    {
        config()->set('ranking.min_observation_coverage', 0.5);

        $sparse = $this->token('solana', ['symbol' => 'SPARSE']);
        DB::table('market_snapshots')->insert([
            $this->snapshotRow($sparse, '2026-07-03T00:00:00Z', 8_000_000),
            $this->snapshotRow($sparse, '2026-07-04T00:00:00Z', 40_000_000), // +400%
        ]);

        $row = $this->julyBucket('solana');

        $this->assertSame($sparse->id, $row->token_id);
        $this->assertSame(MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE, $row->status);
        $this->assertSame(MonthlyRanking::CONFIDENCE_LOW, $row->confidence);
    }

    #[Test]
    public function a_sparse_grower_never_beats_a_densely_observed_eligible_token(): void
    {
        config()->set('ranking.min_observation_coverage', 0.5);

        $sparse = $this->token('solana', ['symbol' => 'SPARSE']);
        DB::table('market_snapshots')->insert([
            $this->snapshotRow($sparse, '2026-07-03T00:00:00Z', 8_000_000),
            $this->snapshotRow($sparse, '2026-07-04T00:00:00Z', 80_000_000), // +900%
        ]);
        $dense = $this->token('solana', ['symbol' => 'DENSE']);
        $this->trajectory($dense, 8_000_000, 20_000_000, 8); // full coverage, +150%

        $row = $this->julyBucket('solana');
        $this->assertSame($dense->id, $row->token_id);
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $row->status);
    }

    #[Test]
    public function snapshots_while_the_token_is_older_than_thirty_days_are_ignored(): void
    {
        $token = $this->token('solana', [
            'symbol' => 'AGES',
            'earliest_pair_created_at' => CarbonImmutable::parse('2026-06-20T00:00:00Z'),
            'first_observed_at' => CarbonImmutable::parse('2026-07-01T00:00:00Z'),
        ]);
        DB::table('market_snapshots')->insert([
            $this->snapshotRow($token, '2026-07-05T00:00:00Z', 6_000_000),
            $this->snapshotRow($token, '2026-07-10T00:00:00Z', 8_000_000),
            $this->snapshotRow($token, '2026-07-18T00:00:00Z', 10_000_000),
            $this->snapshotRow($token, '2026-07-28T00:00:00Z', 150_000_000), // age > 30d — must be ignored
        ]);

        $row = $this->julyBucket('solana');

        $this->assertSame($token->id, $row->token_id);
        $this->assertEqualsWithDelta(10_000_000.0, $row->peak_market_cap, 1);
    }

    #[Test]
    public function the_five_million_floor_is_enforced(): void
    {
        $token = $this->token('solana', ['symbol' => 'SMALLMONTH', 'observed_peak_market_cap' => 9_000_000.0]);
        $this->trajectory($token, 2_000_000, 4_500_000); // never reaches $5M in July

        $this->assertNull($this->julyBucket('solana')->token_id);
        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $this->julyBucket('solana')->status);
    }

    #[Test]
    public function the_two_hundred_million_ceiling_is_enforced(): void
    {
        $token = $this->token('solana', ['symbol' => 'HUGE', 'observed_peak_market_cap' => 250_000_000.0]);
        $this->trajectory($token, 8_000_000, 250_000_000);

        $this->assertNull($this->julyBucket('solana')->token_id);
    }

    #[Test]
    public function a_historical_estimate_only_token_is_excluded(): void
    {
        $token = $this->token('solana', [
            'symbol' => 'ESTONLY',
            'observed_peak_market_cap' => 2_000_000.0,
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'historical_estimate_fdv_usd' => 60_000_000.0,
            'evidence' => [
                'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
                'peak_value_usd' => 60_000_000.0,
                'evidence_source' => 'geckoterminal',
                'evidence_basis' => 'fdv_total_supply',
            ],
        ]);
        $this->trajectory($token, 8_000_000, 40_000_000);

        $this->assertNull($this->julyBucket('solana')->token_id);
    }

    #[Test]
    public function an_unknown_token_is_excluded(): void
    {
        $token = $this->token('solana', [
            'symbol' => 'UNK',
            'observed_peak_market_cap' => 1_000_000.0,
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_UNKNOWN,
            'evidence' => [
                'status' => HistoricalPeakEvidence::STATUS_UNKNOWN,
                'peak_value_usd' => null, 'evidence_source' => null, 'evidence_basis' => null,
            ],
        ]);
        $this->trajectory($token, 8_000_000, 40_000_000);

        $this->assertNull($this->julyBucket('solana')->token_id);
    }

    #[Test]
    public function a_historical_verified_token_is_eligible(): void
    {
        $token = $this->token('bsc', [
            'symbol' => 'VERIFIED',
            'observed_peak_market_cap' => 3_000_000.0,
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 12_000_000.0,
            'historical_peak_value_at' => $this->july->start->addDays(5),
            'evidence' => [
                'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
                'peak_value_usd' => 12_000_000.0, 'evidence_source' => 'coingecko', 'evidence_basis' => 'market_cap',
            ],
        ]);
        $this->trajectory($token, 6_000_000, 12_000_000);

        $this->assertSame($token->id, $this->julyBucket('bsc')->token_id);
    }

    // ==== 21-26: provisional / finalized / future ==================

    #[Test]
    public function the_current_month_is_provisional_per_bucket(): void
    {
        $token = $this->token('solana', [
            'symbol' => 'AUG',
            'earliest_pair_created_at' => CarbonImmutable::parse('2026-08-05T00:00:00Z'),
            'first_observed_at' => CarbonImmutable::parse('2026-08-06T00:00:00Z'),
        ]);
        $august = MonthWindow::of(2026, 8);
        $span = $august->endExclusive->getTimestamp() - CarbonImmutable::parse('2026-08-06T00:00:00Z')->getTimestamp();
        for ($i = 0; $i < 8; $i++) {
            DB::table('market_snapshots')->insert([$this->snapshotRow(
                $token,
                CarbonImmutable::parse('2026-08-06T00:00:00Z')->addSeconds((int) ($span * $i / 9))->toIso8601String(),
                8_000_000 + $i * 2_000_000,
            )]);
        }

        $row = $this->service()->computeAndStoreBucket($august, 'solana', finalize: false, force: false, now: $this->now);

        $this->assertSame(MonthlyRanking::STATUS_PROVISIONAL, $row->status);
        $this->assertNull($row->finalized_at);
        $this->assertSame($token->id, $row->token_id);
    }

    #[Test]
    public function a_future_bucket_is_recorded_as_future_with_no_token(): void
    {
        $row = $this->service()->computeAndStoreBucket(MonthWindow::of(2026, 12), 'solana', finalize: true, force: false, now: $this->now);

        $this->assertSame(MonthlyRanking::STATUS_FUTURE, $row->status);
        $this->assertNull($row->token_id);
    }

    #[Test]
    public function a_completed_month_is_finalized(): void
    {
        $token = $this->token('solana', ['symbol' => 'FINAL']);
        $this->trajectory($token, 8_000_000, 40_000_000);

        $row = $this->julyBucket('solana');
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $row->status);
        $this->assertNotNull($row->finalized_at);
    }

    #[Test]
    public function a_settled_bucket_is_stable_on_a_normal_rerun_and_force_recomputes(): void
    {
        $token = $this->token('solana', ['symbol' => 'STABLE']);
        $this->trajectory($token, 8_000_000, 40_000_000);

        $first = $this->julyBucket('solana');
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $first->status);
        $finalizedAt = $first->finalized_at;

        $newcomer = $this->token('solana', ['symbol' => 'LATE']);
        $this->trajectory($newcomer, 6_000_000, 60_000_000); // wildly better

        $rerun = $this->service()->computeAndStoreBucket($this->july, 'solana', finalize: true, force: false, now: $this->now);
        $this->assertSame($token->id, $rerun->token_id, 'a normal rerun must not replace a settled bucket');
        $this->assertTrue($rerun->finalized_at->equalTo($finalizedAt));

        $forced = $this->julyBucket('solana', force: true);
        $this->assertSame($newcomer->id, $forced->token_id, '--force recomputes');
    }

    #[Test]
    public function finalize_refuses_an_incomplete_month_without_force(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->finalizeMonth(2026, 8, force: false, now: $this->now); // August is current
    }

    #[Test]
    public function no_verified_champion_when_a_bucket_has_no_data(): void
    {
        $rows = $this->service()->finalizeMonth(2026, 6, force: true, now: $this->now);

        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $row->status);
            $this->assertNull($row->token_id);
        }
    }

    #[Test]
    public function the_daily_pass_settles_the_previous_month_and_leaves_the_current_one_provisional(): void
    {
        $julyToken = $this->token('robinhood', ['symbol' => 'JULYWIN']);
        $this->trajectory($julyToken, 8_000_000, 40_000_000);

        $this->service()->refresh($this->now, backfillMonths: 2);

        $julyRh = MonthlyRanking::query()->where('year', 2026)->where('month', 7)->where('chain_bucket', 'robinhood')->sole();
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $julyRh->status);
        $this->assertSame($julyToken->id, $julyRh->token_id);

        $augustRows = MonthlyRanking::query()->where('year', 2026)->where('month', 8)->get();
        $this->assertCount(5, $augustRows);
        foreach ($augustRows as $row) {
            $this->assertNotSame(MonthlyRanking::STATUS_FINALIZED, $row->status);
        }

        // July's other four buckets settled too (no data -> no_verified_champion).
        $this->assertSame(5, MonthlyRanking::query()->where('year', 2026)->where('month', 7)->count());
    }

    #[Test]
    public function the_command_defaults_to_the_previous_completed_month(): void
    {
        $token = $this->token('bsc', ['symbol' => 'CMD']);
        $this->trajectory($token, 8_000_000, 40_000_000);

        $this->artisan('memecoins:finalize-monthly-champion')->assertExitCode(0);

        $july = MonthlyRanking::query()->where('year', 2026)->where('month', 7)->where('chain_bucket', 'bsc')->sole();
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $july->status);
        // September (future) is never created.
        $this->assertSame(0, MonthlyRanking::query()->where('year', 2026)->where('month', 9)->count());
        // August (current) is not finalized.
        foreach (MonthlyRanking::query()->where('year', 2026)->where('month', 8)->get() as $row) {
            $this->assertNotSame(MonthlyRanking::STATUS_FINALIZED, $row->status);
        }
    }

    // ==== 30: historical source metadata ===========================

    #[Test]
    public function historical_source_metadata_is_preserved(): void
    {
        $token = $this->token('solana', ['symbol' => 'SRC']);
        $this->trajectory($token, 8_000_000, 40_000_000);

        $row = $this->julyBucket('solana');

        $this->assertSame(MonthlyRanking::SOURCE_INTERNAL_OBSERVED, $row->source_type);
        $this->assertNotNull($row->source_reference);
        $this->assertContains($row->confidence, [MonthlyRanking::CONFIDENCE_HIGH, MonthlyRanking::CONFIDENCE_MEDIUM]);

        // Survives a re-read.
        $this->assertSame(MonthlyRanking::SOURCE_INTERNAL_OBSERVED, $row->fresh()->source_type);
    }

    // ==== 1, 2, 27, 28, 29: API ===================================

    #[Test]
    public function the_api_returns_exactly_twelve_months_each_with_all_five_buckets(): void
    {
        $res = $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();

        $res->assertJsonCount(12, 'data');
        $res->assertJsonPath('meta.count', 12);
        $res->assertJsonPath('meta.buckets', ['solana', 'robinhood', 'bsc', 'base', 'other']);

        foreach ($res->json('data') as $month) {
            $this->assertSame(['solana', 'robinhood', 'bsc', 'base', 'other'], array_keys($month['champions']));
            foreach ($month['champions'] as $bucket => $entry) {
                $this->assertSame($bucket, $entry['chain_bucket']);
                $this->assertArrayHasKey('status', $entry);
                $this->assertArrayHasKey('token', $entry);
            }
        }
    }

    #[Test]
    public function the_api_exposes_stored_bucket_champions_and_synthesizes_the_rest(): void
    {
        $sol = $this->token('solana', ['symbol' => 'APISOL']);
        $this->trajectory($sol, 8_000_000, 40_000_000);
        $bsc = $this->token('bsc', ['symbol' => 'APIBSC']);
        $this->trajectory($bsc, 6_000_000, 18_000_000);
        $this->finalizeJuly();

        $res = $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();

        // data[6] = July.
        $res->assertJsonPath('data.6.month', 7);
        $res->assertJsonPath('data.6.status', 'finalized');
        $res->assertJsonPath('data.6.champions.solana.status', 'finalized');
        $res->assertJsonPath('data.6.champions.solana.token.symbol', 'APISOL');
        $res->assertJsonPath('data.6.champions.solana.token.chain_bucket', 'solana');
        $res->assertJsonPath('data.6.champions.solana.source_type', 'internal_observed');
        $res->assertJsonPath('data.6.champions.solana.performance.market_cap_growth_pct', fn ($v) => abs($v - 400) < 1);
        $res->assertJsonPath('data.6.champions.bsc.token.symbol', 'APIBSC');
        // Buckets with no data -> no_verified_champion, null token.
        $res->assertJsonPath('data.6.champions.base.status', 'no_verified_champion');
        $res->assertJsonPath('data.6.champions.base.token', null);
        // August (current) -> month provisional, buckets synthesized provisional.
        $res->assertJsonPath('data.7.status', 'provisional');
        $res->assertJsonPath('data.7.champions.solana.status', 'provisional');
        // September onward -> future.
        $res->assertJsonPath('data.8.status', 'future');
        $res->assertJsonPath('data.8.champions.solana.status', 'future');
    }

    #[Test]
    public function the_api_is_read_only_and_makes_no_provider_calls(): void
    {
        Http::fake();
        $token = $this->token('solana');
        $this->trajectory($token, 8_000_000, 40_000_000);
        $this->finalizeJuly();

        DB::enableQueryLog();
        $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        Http::assertNothingSent();
        $this->assertLessThanOrEqual(3, count($queries));
        foreach ($queries as $q) {
            $this->assertStringNotContainsString('market_snapshots', $q['query']);
        }
        // Calling GET did not create or alter any ranking row.
        $this->assertSame(5, MonthlyRanking::query()->count());
    }

    // ==== isolation ==============================================

    #[Test]
    public function existing_pump_events_and_evidence_and_peak_are_untouched(): void
    {
        $token = $this->token('solana', ['symbol' => 'ISO']);
        $this->trajectory($token, 8_000_000, 40_000_000);

        $event = $token->pumpEvents()->create([
            'started_at' => $this->july->start->addDay(), 'peak_at' => $this->july->start->addDays(2),
            'start_market_cap' => 8_000_000.0, 'peak_market_cap' => 20_000_000.0,
            'start_price_usd' => 0.01, 'peak_price_usd' => 0.025,
            'market_cap_change_pct' => 150.0, 'price_change_pct' => 150.0,
            'volume_h24_change_ratio' => 2.0, 'txns_h24_change_ratio' => 1.8,
            'duration_minutes' => 60, 'detection_score' => 70,
            'confidence' => PumpEvent::CONFIDENCE_MEDIUM, 'status' => PumpEvent::STATUS_COMPLETED,
        ]);
        Evidence::query()->create([
            'pump_event_id' => $event->id, 'token_id' => $token->id,
            'category' => Evidence::CATEGORY_MARKET, 'source' => 'internal',
            'title' => 'x', 'summary' => 'y', 'observed_at' => $this->july->start->addDay(),
            'relevance_score' => 50, 'confidence' => 'medium', 'raw_reference' => 'x',
            'dedupe_hash' => Str::random(40), 'collected_at' => $this->now,
        ]);

        $pumpBefore = $event->only(['peak_market_cap', 'detection_score']);
        $peakBefore = $token->observed_peak_market_cap;

        $this->finalizeJuly();

        $this->assertSame($pumpBefore, $event->fresh()->only(['peak_market_cap', 'detection_score']));
        $this->assertSame(1, Evidence::query()->count());
        $this->assertSame($peakBefore, $token->fresh()->observed_peak_market_cap);
    }
}
