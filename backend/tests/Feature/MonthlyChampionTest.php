<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\HistoricalPeakEvidence;
use App\Models\MonthlyRanking;
use App\Models\PumpEvent;
use App\Models\RiskAssessment;
use App\Models\Token;
use App\Services\Ranking\ChainBucket;
use App\Services\Ranking\MonthlyChampionService;
use App\Services\Ranking\MonthlyPerformanceCalculator;
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
 * Step 25 — "Monthly Top Memecoins" (Top 3, participation score).
 *
 * For EVERY calendar month, the TOP 3 memecoins inside each of the FIVE fixed
 * buckets (solana / robinhood / bsc / base / other), unique on
 * `(year, month, chain_bucket, rank)`. Ranked by real participation — holder
 * count (0.40) + representative monthly volume (0.35) + month-peak
 * observed/verified market cap (0.25), log-normalized, renormalized over the
 * KNOWN components. Market cap is supporting evidence and cannot dominate. Risk
 * score, AI and social sentiment are never used.
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
        config()->set('ranking.observation_interval_minutes', 4320); // 3 days
        config()->set('ranking.min_observation_coverage', 0.25);
        config()->set('ranking.top_n', 3);
        config()->set('ranking.weights', ['holder' => 0.40, 'volume' => 0.35, 'market_cap' => 0.25]);
        config()->set('ranking.references', ['holder_count' => 10_000, 'volume_usd' => 20_000_000, 'market_cap_usd' => 50_000_000]);
        config()->set('ranking.market_cap_only_penalty', 0.5);
        // Holder pass never fires for a past month; disable outright for determinism.
        config()->set('ranking.holder_pass.enabled', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    // ---- fixtures --------------------------------------------------------

    /** @param array<string,mixed> $attrs */
    private function token(string $chainId = 'solana', array $attrs = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => $chainId,
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'TKN'.Str::random(3),
            'name' => 'Test Token',
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

    /**
     * Insert `$count` spread in-month snapshots. `$mc` is the flat market cap
     * (also the month peak). `$volume` is the flat `volume_h24`.
     */
    private function trajectory(Token $token, float $mc, float $volume = 1_500_000.0, int $count = 8, array $overrides = []): void
    {
        $spanSeconds = $this->july->endExclusive->getTimestamp() - $this->july->start->getTimestamp();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = array_replace([
                'token_id' => $token->id,
                'observed_at' => $this->july->start->addSeconds((int) round($spanSeconds * ($i + 0.5) / $count)),
                'price_usd' => 0.01,
                'market_cap' => $mc,
                'fdv' => $mc * 1.2,
                'liquidity_usd' => 400_000.0,
                'volume_h24' => $volume,
                'price_change_h24' => 5.0,
                'txns_h24' => 3_000,
                'buys_h24' => 1_700,
                'sells_h24' => 1_300,
                'primary_pair_address' => 'pair',
                'primary_dex_id' => 'raydium',
                'earliest_pair_created_at' => $token->earliest_pair_created_at,
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ], $overrides);
        }
        DB::table('market_snapshots')->insert($rows);
    }

    private function service(): MonthlyChampionService
    {
        return app(MonthlyChampionService::class);
    }

    /** @return Collection<int, MonthlyRanking> keyed "$bucket:$rank" */
    private function finalizeJuly(bool $force = false): Collection
    {
        return $this->service()->finalizeMonth(2026, 7, $force, $this->now)
            ->keyBy(fn (MonthlyRanking $r): string => $r->chain_bucket.':'.$r->rank);
    }

    /** @return Collection<int, MonthlyRanking> the TOKEN-carrying ranked rows for one July bucket, rank order */
    private function julyBucket(string $bucket, bool $force = false): Collection
    {
        return $this->finalizeJuly($force)
            ->filter(fn (MonthlyRanking $r): bool => $r->chain_bucket === $bucket && $r->token_id !== null)
            ->sortBy('rank')->values();
    }

    // ==== structure ====================================================

    #[Test]
    public function each_bucket_returns_its_own_top_three_ranked_1_to_3(): void
    {
        // 4 Solana candidates, strongest -> weakest by volume (same MC, no holders).
        foreach ([18_000_000, 12_000_000, 6_000_000, 2_000_000] as $i => $vol) {
            $this->trajectory($this->token('solana', ['symbol' => "SOL{$i}"]), 30_000_000.0, (float) $vol);
        }
        $rows = $this->julyBucket('solana');

        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], $rows->pluck('rank')->all());
        $this->assertSame(['SOL0', 'SOL1', 'SOL2'], $rows->map(fn (MonthlyRanking $r) => $r->token->symbol)->all());
        $this->assertGreaterThan($rows[1]->performance_score, $rows[0]->performance_score);
        $this->assertGreaterThan($rows[2]->performance_score, $rows[1]->performance_score);
    }

    #[Test]
    public function the_unique_key_is_year_month_chain_bucket_rank_and_a_token_appears_once_per_bucket(): void
    {
        foreach (range(0, 3) as $i) {
            $this->trajectory($this->token('bsc', ['symbol' => "B{$i}"]), 20_000_000.0, (float) (10_000_000 - $i * 1_000_000));
        }
        $this->finalizeJuly();

        $dupes = DB::table('monthly_rankings')
            ->select('year', 'month', 'chain_bucket', 'rank')
            ->groupBy('year', 'month', 'chain_bucket', 'rank')
            ->havingRaw('count(*) > 1')->get();
        $this->assertCount(0, $dupes);

        $maxRank = (int) DB::table('monthly_rankings')->max('rank');
        $this->assertLessThanOrEqual(3, $maxRank);

        foreach (ChainBucket::ALL as $bucket) {
            $tokenIds = DB::table('monthly_rankings')->where('chain_bucket', $bucket)->whereNotNull('token_id')->pluck('token_id');
            $this->assertSame($tokenIds->count(), $tokenIds->unique()->count(), "duplicate token in {$bucket}");
        }
    }

    #[Test]
    public function a_bucket_with_one_or_two_eligible_tokens_produces_exactly_that_many_rows(): void
    {
        $this->trajectory($this->token('base', ['symbol' => 'ONLYBASE']), 25_000_000.0, 8_000_000.0);
        $this->trajectory($this->token('bsc', ['symbol' => 'B1']), 25_000_000.0, 9_000_000.0);
        $this->trajectory($this->token('bsc', ['symbol' => 'B2']), 25_000_000.0, 3_000_000.0);
        $this->finalizeJuly();

        $this->assertCount(1, $this->julyBucket('base'));
        $this->assertCount(2, $this->julyBucket('bsc'));
        $this->assertDatabaseMissing('monthly_rankings', ['chain_bucket' => 'bsc', 'rank' => 3]);
    }

    #[Test]
    public function the_other_bucket_ranks_across_all_non_core_chains(): void
    {
        $this->trajectory($this->token('pulsechain', ['symbol' => 'PLS']), 20_000_000.0, 12_000_000.0);
        $this->trajectory($this->token('sui', ['symbol' => 'SUI']), 20_000_000.0, 4_000_000.0);
        $rows = $this->julyBucket('other');

        $this->assertSame(['PLS', 'SUI'], $rows->map(fn (MonthlyRanking $r) => $r->token->symbol)->all());
        // The token keeps its real chain_id — only chain_bucket says "other".
        $this->assertSame('pulsechain', $rows[0]->token->chain_id);
        $this->assertSame('other', $rows[0]->chain_bucket);
    }

    #[Test]
    public function the_same_symbol_on_two_chains_is_ranked_in_separate_buckets(): void
    {
        $this->trajectory($this->token('solana', ['symbol' => 'DOG', 'token_address' => 'SolDog']), 20_000_000.0, 6_000_000.0);
        $this->trajectory($this->token('bsc', ['symbol' => 'DOG', 'token_address' => 'BscDog']), 20_000_000.0, 6_000_000.0);

        $this->assertSame('DOG', $this->julyBucket('solana')[0]->token->symbol);
        $this->assertSame('DOG', $this->julyBucket('bsc')[0]->token->symbol);
        $this->assertNotSame($this->julyBucket('solana')[0]->token_id, $this->julyBucket('bsc')[0]->token_id);
    }

    // ==== the participation score =====================================

    /** @return array{0:float,1:?float,2:float,3:float} [score, holderStrength, volumeStrength, mcStrength] */
    private function scoreParts(?int $holders, float $volume, float $mc): array
    {
        $c = app(MonthlyPerformanceCalculator::class)->scoreHistorical(null, $mc, $volume, $holders);

        return [$c['performance_score'], $c['holder_strength'], $c['volume_strength'], $c['market_cap_strength']];
    }

    #[Test]
    public function holder_volume_and_market_cap_strengths_are_each_deterministic_capped_log(): void
    {
        $ln = fn (float $x, float $ref): float => min(1.0, log(1 + $x) / log(1 + $ref));

        [, $h, $v, $mc] = $this->scoreParts(4_000, 8_000_000.0, 30_000_000.0);
        $this->assertEqualsWithDelta($ln(4_000, 10_000), $h, 0.001);
        $this->assertEqualsWithDelta($ln(8_000_000, 20_000_000), $v, 0.001);
        $this->assertEqualsWithDelta($ln(30_000_000, 50_000_000), $mc, 0.001);
    }

    #[Test]
    public function the_combined_score_applies_the_configured_weights(): void
    {
        [$score, $h, $v, $mc] = $this->scoreParts(4_000, 8_000_000.0, 30_000_000.0);
        $expected = 100.0 * (0.40 * $h + 0.35 * $v + 0.25 * $mc) / (0.40 + 0.35 + 0.25);
        $this->assertEqualsWithDelta($expected, $score, 0.05);

        // Re-run with a different weighting -> different score.
        config()->set('ranking.weights', ['holder' => 0.10, 'volume' => 0.10, 'market_cap' => 0.80]);
        [$score2] = $this->scoreParts(4_000, 8_000_000.0, 30_000_000.0);
        $this->assertNotEqualsWithDelta($score, $score2, 0.5);
    }

    #[Test]
    public function an_unknown_holder_count_drops_out_and_the_weights_renormalize(): void
    {
        [$withHolders, $h] = $this->scoreParts(20_000, 8_000_000.0, 30_000_000.0);
        [$withoutHolders, $hNull, $v, $mc] = $this->scoreParts(null, 8_000_000.0, 30_000_000.0);

        $this->assertNull($hNull);
        $renorm = 100.0 * (0.35 * $v + 0.25 * $mc) / (0.35 + 0.25);
        $this->assertEqualsWithDelta($renorm, $withoutHolders, 0.05);
        // Strong holders lift the score; UNKNOWN is not treated as 0.
        $this->assertGreaterThan($withoutHolders, $withHolders);
    }

    #[Test]
    public function market_cap_alone_cannot_dominate_a_token_with_real_holder_and_volume_participation(): void
    {
        // $150M token, market cap ONLY known.
        [$huge] = $this->scoreParts(null, 0.0, 150_000_000.0);
        // $20M token, strong holders + strong volume.
        [$small] = $this->scoreParts(30_000, 25_000_000.0, 20_000_000.0);

        $this->assertGreaterThan($huge, $small, 'a $20M token with strong holders + volume must beat a $150M size-only token');
        // The size-only score is penalized (config market_cap_only_penalty 0.5).
        $this->assertLessThanOrEqual(50.0, $huge);
    }

    #[Test]
    public function a_researched_candidate_with_no_volume_and_no_market_cap_is_not_scorable(): void
    {
        [$score] = $this->scoreParts(5_000, 0.0, 0.0);
        $this->assertNull($score);
    }

    // ==== eligibility =================================================

    #[Test]
    public function the_five_million_floor_is_enforced(): void
    {
        $t = $this->token('solana', ['symbol' => 'LOWMC', 'observed_peak_market_cap' => 4_000_000.0]);
        $this->trajectory($t, 4_000_000.0, 9_000_000.0);
        $this->assertCount(0, $this->julyBucket('solana'));
        $this->assertDatabaseHas('monthly_rankings', ['chain_bucket' => 'solana', 'rank' => 1, 'status' => 'no_verified_result']);
    }

    #[Test]
    public function the_two_hundred_million_ceiling_is_enforced_even_for_a_single_in_month_spike(): void
    {
        $t = $this->token('solana', ['symbol' => 'HUGE', 'observed_peak_market_cap' => 60_000_000.0]);
        $this->trajectory($t, 60_000_000.0, 9_000_000.0, 6);
        // one snapshot spikes above $200M
        DB::table('market_snapshots')->insert([
            'token_id' => $t->id, 'observed_at' => $this->july->start->addDays(9),
            'price_usd' => 0.02, 'market_cap' => 210_000_000.0, 'fdv' => 250_000_000.0,
            'liquidity_usd' => 400_000.0, 'volume_h24' => 9_000_000.0, 'price_change_h24' => 5.0,
            'txns_h24' => 3_000, 'buys_h24' => 1_700, 'sells_h24' => 1_300,
            'primary_pair_address' => 'pair', 'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $t->earliest_pair_created_at, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $this->assertCount(0, $this->julyBucket('solana'));
    }

    #[Test]
    public function a_token_older_than_thirty_days_at_the_snapshot_is_not_eligible(): void
    {
        $t = $this->token('solana', ['symbol' => 'OLD', 'earliest_pair_created_at' => $this->july->start->subDays(40)]);
        $this->trajectory($t, 30_000_000.0, 9_000_000.0);
        $this->assertCount(0, $this->julyBucket('solana'));
    }

    #[Test]
    public function a_historical_estimate_only_token_is_excluded(): void
    {
        $t = $this->token('solana', ['symbol' => 'ESTONLY', 'observed_peak_market_cap' => null, 'evidence' => [
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'peak_value_usd' => 60_000_000.0, 'evidence_source' => 'geckoterminal', 'evidence_basis' => 'fdv_total_supply',
        ]]);
        $t->forceFill(['historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE, 'historical_estimate_fdv_usd' => 60_000_000.0])->save();
        $this->trajectory($t->refresh(), 60_000_000.0, 9_000_000.0);
        $this->assertCount(0, $this->julyBucket('solana'));
    }

    #[Test]
    public function an_unknown_token_is_excluded_and_unknown_is_not_coerced_to_a_number(): void
    {
        $t = $this->token('solana', ['symbol' => 'UNK', 'observed_peak_market_cap' => null, 'evidence' => [
            'status' => HistoricalPeakEvidence::STATUS_UNKNOWN, 'peak_value_usd' => null,
            'evidence_source' => null, 'evidence_basis' => null,
        ]]);
        $t->forceFill(['historical_peak_status' => HistoricalPeakEvidence::STATUS_UNKNOWN, 'historical_peak_value' => null])->save();
        $this->trajectory($t->refresh(), 30_000_000.0, 9_000_000.0);
        $this->assertCount(0, $this->julyBucket('solana'));
    }

    #[Test]
    public function a_historical_verified_token_is_eligible(): void
    {
        $t = $this->token('solana', ['symbol' => 'VER', 'observed_peak_market_cap' => null, 'evidence' => [
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED, 'peak_value_usd' => 30_000_000.0,
            'evidence_source' => 'coingecko', 'evidence_basis' => 'market_cap',
        ]]);
        $t->forceFill([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 30_000_000.0, 'historical_peak_value_at' => $this->july->start->addDays(5),
        ])->save();
        $this->trajectory($t->refresh(), 30_000_000.0, 9_000_000.0);
        $this->assertSame('VER', $this->julyBucket('solana')[0]->token->symbol);
    }

    #[Test]
    public function a_token_observed_too_sparsely_is_ranked_with_low_confidence_not_dropped(): void
    {
        config()->set('ranking.observation_interval_minutes', 10); // strict expectation
        $t = $this->token('solana', ['symbol' => 'SPARSE']);
        // only 2 snapshots across the month -> coverage well below 0.25
        DB::table('market_snapshots')->insert([
            $this->one($t, $this->july->start->addDays(2), 30_000_000.0),
            $this->one($t, $this->july->start->addDays(20), 30_000_000.0),
        ]);
        $rows = $this->julyBucket('solana');
        $this->assertCount(1, $rows);
        $this->assertSame('low', $rows[0]->confidence);
    }

    /** @return array<string,mixed> */
    private function one(Token $t, CarbonImmutable $at, float $mc): array
    {
        return [
            'token_id' => $t->id, 'observed_at' => $at, 'price_usd' => 0.01, 'market_cap' => $mc, 'fdv' => $mc * 1.2,
            'liquidity_usd' => 400_000.0, 'volume_h24' => 9_000_000.0, 'price_change_h24' => 5.0,
            'txns_h24' => 3_000, 'buys_h24' => 1_700, 'sells_h24' => 1_300,
            'primary_pair_address' => 'pair', 'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $t->earliest_pair_created_at, 'created_at' => $this->now, 'updated_at' => $this->now,
        ];
    }

    // ==== statuses ====================================================

    #[Test]
    public function the_current_month_is_provisional_and_a_settled_past_month_is_stable(): void
    {
        $this->trajectory($this->token('solana', ['symbol' => 'JUL']), 30_000_000.0, 9_000_000.0);
        $this->service()->refresh($this->now);

        $this->assertDatabaseHas('monthly_rankings', ['year' => 2026, 'month' => 7, 'chain_bucket' => 'solana', 'rank' => 1, 'status' => 'finalized']);
        // August (current) is provisional (no data -> synthesized, no row).
        $this->assertDatabaseMissing('monthly_rankings', ['year' => 2026, 'month' => 8, 'chain_bucket' => 'solana', 'status' => 'finalized']);

        // A settled July row is not re-touched on a normal re-run.
        $before = MonthlyRanking::query()->where('month', 7)->where('chain_bucket', 'solana')->first()->computed_at;
        CarbonImmutable::setTestNow($this->now->addDay());
        $this->service()->refresh(CarbonImmutable::now());
        $after = MonthlyRanking::query()->where('month', 7)->where('chain_bucket', 'solana')->first()->computed_at;
        $this->assertEquals($before, $after);
    }

    #[Test]
    public function force_recomputes_a_settled_month(): void
    {
        $this->trajectory($this->token('solana', ['symbol' => 'A']), 30_000_000.0, 9_000_000.0);
        $this->finalizeJuly();
        $original = MonthlyRanking::query()->where('month', 7)->where('chain_bucket', 'solana')->first()->computed_at;

        CarbonImmutable::setTestNow($this->now->addDays(3));
        $this->service()->finalizeMonth(2026, 7, force: true, now: CarbonImmutable::now());
        $recomputed = MonthlyRanking::query()->where('month', 7)->where('chain_bucket', 'solana')->first()->computed_at;
        $this->assertNotEquals($original, $recomputed);
    }

    #[Test]
    public function finalize_refuses_an_incomplete_month_without_force(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->finalizeMonth(2026, 8, force: false, now: $this->now);
    }

    #[Test]
    public function a_completed_bucket_with_no_data_is_no_verified_result_with_no_token(): void
    {
        $this->finalizeJuly();
        $row = MonthlyRanking::query()->where('month', 7)->where('chain_bucket', 'robinhood')->firstOrFail();
        $this->assertSame(1, $row->rank);
        $this->assertSame('no_verified_result', $row->status);
        $this->assertNull($row->token_id);
        $this->assertNotNull($row->finalized_at);
        $this->assertEmpty($this->julyBucket('robinhood'));
    }

    // ==== API =========================================================

    #[Test]
    public function the_api_returns_twelve_months_each_with_five_buckets_and_up_to_three_entries(): void
    {
        foreach ([12_000_000, 6_000_000, 3_000_000] as $i => $vol) {
            $this->trajectory($this->token('solana', ['symbol' => "S{$i}"]), 30_000_000.0, (float) $vol);
        }
        $this->finalizeJuly();

        $res = $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();
        $res->assertJsonCount(12, 'data');
        $res->assertJsonPath('meta.top_n', 3);
        $res->assertJsonPath('meta.buckets', ['solana', 'robinhood', 'bsc', 'base', 'other']);

        foreach ($res->json('data') as $month) {
            $this->assertSame(['solana', 'robinhood', 'bsc', 'base', 'other'], array_keys($month['champions']));
            foreach ($month['champions'] as $bucket => $payload) {
                $this->assertSame($bucket, $payload['chain_bucket']);
                $this->assertArrayHasKey('status', $payload);
                $this->assertArrayHasKey('entries', $payload);
                $this->assertLessThanOrEqual(3, count($payload['entries']));
            }
        }

        $july = $res->json('data.6'); // index 6 = July
        $this->assertSame(7, $july['month']);
        $this->assertSame('finalized', $july['champions']['solana']['status']);
        $entries = $july['champions']['solana']['entries'];
        $this->assertCount(3, $entries);
        $this->assertSame([1, 2, 3], array_column($entries, 'rank'));
        $this->assertSame('S0', $entries[0]['token']['symbol']);
        $this->assertArrayHasKey('holder_count', $entries[0]['performance']);
        $this->assertArrayHasKey('monthly_volume', $entries[0]['performance']);
        $this->assertArrayHasKey('market_cap', $entries[0]['performance']);
    }

    #[Test]
    public function a_future_month_and_a_no_verified_result_bucket_have_no_entries(): void
    {
        $this->finalizeJuly();
        $res = $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();

        $this->assertSame('future', $res->json('data.11.status'));           // December
        $this->assertSame([], $res->json('data.11.champions.solana.entries'));
        $this->assertSame('no_verified_result', $res->json('data.6.champions.bsc.status'));
        $this->assertSame([], $res->json('data.6.champions.bsc.entries'));
    }

    #[Test]
    public function the_api_is_read_only_and_makes_no_provider_calls(): void
    {
        Http::fake();
        $this->trajectory($this->token('solana', ['symbol' => 'RO']), 30_000_000.0, 9_000_000.0);
        $this->finalizeJuly();

        $before = DB::table('monthly_rankings')->count();
        $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();
        $this->assertSame($before, DB::table('monthly_rankings')->count());
        Http::assertNothingSent();
    }

    // ==== detail page + non-interference ==============================

    #[Test]
    public function a_ranked_tracked_token_shows_its_rank_and_participation_on_the_detail_page(): void
    {
        $t = $this->token('solana', ['symbol' => 'DETAIL']);
        $this->trajectory($t, 30_000_000.0, 12_000_000.0);
        $this->trajectory($this->token('solana', ['symbol' => 'RUNNER']), 30_000_000.0, 4_000_000.0);
        $this->finalizeJuly();

        $res = $this->getJson("/api/memecoins/{$t->chain_id}/{$t->token_address}")->assertOk();
        $res->assertJsonPath('data.monthly_champion.is_champion', true);
        $champ = $res->json('data.monthly_champion.championships.0');
        $this->assertSame(1, $champ['rank']);
        $this->assertSame(7, $champ['month']);
        $this->assertArrayHasKey('holder_count', $champ);
        $this->assertArrayHasKey('monthly_volume', $champ);
        $this->assertArrayHasKey('market_cap', $champ);
    }

    #[Test]
    public function ranking_never_touches_risk_pump_evidence_qualification_or_tokens(): void
    {
        $t = $this->token('solana', ['symbol' => 'INTACT']);
        $this->trajectory($t, 30_000_000.0, 9_000_000.0);
        RiskAssessment::query()->create([
            'token_id' => $t->id, 'risk_level' => 'HIGH', 'risk_score' => 60, 'data_completeness' => 1.0,
            'screening_status' => 'completed', 'main_list_eligible' => false, 'screened_at' => $this->now, 'provider_version' => 't',
        ]);
        $pe = PumpEvent::query()->create([
            'token_id' => $t->id, 'started_at' => $this->july->start->addDay(), 'peak_at' => $this->july->start->addDays(2),
            'start_market_cap' => 6_000_000.0, 'peak_market_cap' => 30_000_000.0, 'start_price_usd' => 0.001, 'peak_price_usd' => 0.005,
            'market_cap_change_pct' => 400, 'price_change_pct' => 400, 'duration_minutes' => 2880, 'detection_score' => 90, 'confidence' => 'low', 'status' => 'completed',
        ]);
        Evidence::query()->create([
            'pump_event_id' => $pe->id, 'token_id' => $t->id, 'category' => 'MARKET', 'source' => 'internal',
            'observed_at' => $this->july->start->addDay(), 'relevance_score' => 100, 'confidence' => 'high',
            'summary' => 'x', 'dedupe_hash' => 'h1', 'collected_at' => $this->now,
        ]);

        $riskBefore = RiskAssessment::query()->first()->risk_level;
        $peakBefore = $t->observed_peak_market_cap;

        $this->finalizeJuly();

        $this->assertSame($riskBefore, RiskAssessment::query()->first()->risk_level);
        $this->assertSame($peakBefore, $t->fresh()->observed_peak_market_cap);
        $this->assertDatabaseCount('pump_events', 1);
        $this->assertDatabaseCount('evidences', 1);
    }
}
