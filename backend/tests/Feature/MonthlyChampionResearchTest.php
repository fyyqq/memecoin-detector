<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\MonthlyRanking;
use App\Models\Token;
use App\Services\Ranking\MonthlyChampionResearchService;
use App\Services\Ranking\MonthWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 25 — Historical Monthly Champion Backfill.
 *
 * `memecoins:research-monthly-champions` researches external / historical
 * market evidence (operator-verified via the seed file) to identify the
 * best-supported #1 performer per chain bucket for a PAST completed month —
 * instead of returning "no champion" just because our detector did not exist
 * yet. It NEVER fabricates a winner / date / source, NEVER claims an exact
 * DexScreener rank without evidence, and NEVER uses the risk score or AI.
 */
class MonthlyChampionResearchTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private MonthWindow $january;

    private string $seedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-01T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();
        $this->january = MonthWindow::of(2026, 1);

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('ranking.observation_interval_minutes', 4320);
        config()->set('ranking.min_observation_coverage', 0.25);
        config()->set('ranking.weights', ['growth' => 0.60, 'expansion' => 0.25, 'activity' => 0.15]);
        config()->set('ranking.growth_reference', 20.0);
        config()->set('ranking.expansion_reference', 25.0);

        // Isolated seed file per test.
        $this->seedPath = storage_path('framework/testing/monthly-seed-'.Str::random(8).'.json');
        config()->set('ranking.research.providers', ['internal_observed', 'seed_file']);
        config()->set('ranking.research.seed_path', $this->seedPath);
        config()->set('ranking.research.web.enabled', false);
    }

    protected function tearDown(): void
    {
        if (is_file($this->seedPath)) {
            @unlink($this->seedPath);
        }
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    private function service(): MonthlyChampionResearchService
    {
        return app(MonthlyChampionResearchService::class);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     */
    private function writeSeed(array $candidates): void
    {
        file_put_contents($this->seedPath, json_encode(['candidates' => $candidates], JSON_PRETTY_PRINT));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function candidate(array $overrides = []): array
    {
        return array_replace([
            'year' => 2026, 'month' => 1, 'chain_bucket' => 'solana',
            'name' => 'Example Coin', 'symbol' => 'EXMPL', 'chain_id' => 'solana',
            'token_address' => 'So1anaAddr'.Str::random(20),
            'baseline_market_cap' => 6_000_000, 'peak_market_cap' => 42_000_000,
            'volume_usd' => 8_000_000,
            'launch_date' => '2025-12-28', 'age_uncertain' => false,
            'source_type' => 'best_supported_historical_performer',
            'confidence' => 'medium',
            'sources' => [
                ['name' => 'CoinGecko', 'url' => 'https://www.coingecko.com/en/coins/example', 'claim' => 'Peaked at $42M market cap in January 2026, up from ~$6M.', 'published_at' => '2026-02-03', 'credibility' => 'historical_provider'],
                ['name' => 'CoinDesk', 'url' => 'https://www.coindesk.com/markets/2026/02/01/example', 'claim' => 'Named the top-performing Solana memecoin of January.', 'published_at' => '2026-02-01', 'credibility' => 'reputable_reporting'],
            ],
            'explanation' => 'Two independent sources agree it led Solana memecoins on market-cap growth in January; peak MC inside the $5M-$200M band.',
        ], $overrides);
    }

    private function januaryBucket(string $bucket): MonthlyRanking
    {
        return MonthlyRanking::query()->where('year', 2026)->where('month', 1)->where('chain_bucket', $bucket)->sole();
    }

    // ==== 1: historical research populates a completed month ==========

    #[Test]
    public function historical_research_from_the_seed_file_populates_a_completed_month(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'JANWIN'])]);

        $result = $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);

        $this->assertSame(1, $result->finalized);
        $row = $this->januaryBucket('solana');
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $row->status);
        $this->assertSame('JANWIN', $row->champion_symbol);
        $this->assertSame('solana', $row->champion_chain_id);
        $this->assertNull($row->token_id, 'a historical champion not in our tokens table has no token_id');
        $this->assertEqualsWithDelta(600.0, $row->market_cap_growth_pct, 1.0);
    }

    // ==== 2, 3, 4, 5, 6: month + buckets + provenance ===============

    #[Test]
    public function a_full_month_research_writes_all_five_buckets_and_stores_source_and_confidence(): void
    {
        $this->writeSeed([
            $this->candidate(['chain_bucket' => 'solana', 'chain_id' => 'solana', 'symbol' => 'SOLJAN']),
            $this->candidate(['chain_bucket' => 'bsc', 'chain_id' => 'bsc', 'symbol' => 'BSCJAN', 'confidence' => 'low',
                'sources' => [['name' => 'a small blog', 'url' => 'https://blog.example/x', 'claim' => 'led BSC', 'published_at' => null, 'credibility' => 'low_quality']]]),
        ]);

        $this->service()->research(2026, 1, null, force: true, now: $this->now);

        $rows = MonthlyRanking::query()->where('year', 2026)->where('month', 1)->get()->keyBy('chain_bucket');
        $this->assertCount(5, $rows);
        $this->assertEqualsCanonicalizing(['solana', 'robinhood', 'bsc', 'base', 'other'], $rows->keys()->all());

        $sol = $rows->get('solana');
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $sol->status);
        $this->assertSame(MonthlyRanking::SOURCE_BEST_SUPPORTED_HISTORICAL, $sol->source_type);
        $this->assertContains($sol->confidence, ['high', 'medium']);
        $this->assertNotEmpty($sol->source_evidence);
        $this->assertSame('https://www.coingecko.com/en/coins/example', $sol->source_evidence[0]['url']);
        $this->assertSame('2026-02-03', $sol->source_evidence[0]['published_at']);

        // Weak single low-quality source => best_supported_candidate / low.
        $bsc = $rows->get('bsc');
        $this->assertSame(MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE, $bsc->status);
        $this->assertSame(MonthlyRanking::CONFIDENCE_LOW, $bsc->confidence);

        // Untouched buckets -> no_verified_champion.
        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $rows->get('robinhood')->status);
    }

    #[Test]
    public function the_api_still_returns_twelve_months_with_five_buckets_after_backfill(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'APIJAN'])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);

        $res = $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();
        $res->assertJsonCount(12, 'data');
        foreach ($res->json('data') as $month) {
            $this->assertSame(['solana', 'robinhood', 'bsc', 'base', 'other'], array_keys($month['champions']));
        }
        $res->assertJsonPath('data.0.champions.solana.status', 'finalized');
        $res->assertJsonPath('data.0.champions.solana.token.symbol', 'APIJAN');
        $res->assertJsonPath('data.0.champions.solana.token.chain_bucket', 'solana');
        $res->assertJsonPath('data.0.champions.solana.source_type', 'best_supported_historical_performer');
        $this->assertNotEmpty($res->json('data.0.champions.solana.source_evidence'));
    }

    // ==== 7: exact DexScreener rank not claimed without evidence =====

    #[Test]
    public function an_exact_dexscreener_rank_is_only_claimed_when_a_source_establishes_it(): void
    {
        // A seed row that merely asserts best-supported, even with strong
        // sources, is NEVER upgraded to exact_dexscreener_rank.
        $this->writeSeed([$this->candidate(['symbol' => 'NOTEXACT'])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertSame(MonthlyRanking::SOURCE_BEST_SUPPORTED_HISTORICAL, $this->januaryBucket('solana')->source_type);

        // An explicit exact_dexscreener_rank claim, backed by a strong source.
        MonthlyRanking::query()->delete();
        $this->writeSeed([$this->candidate([
            'symbol' => 'REALEXACT',
            'source_type' => 'exact_dexscreener_rank',
            'sources' => [['name' => 'DexScreener (archived)', 'url' => 'https://web.archive.org/web/2026/https://dexscreener.com/solana', 'claim' => 'Ranked #1 by trendingScoreH24 on the Solana chain page for the month.', 'published_at' => '2026-01-31', 'credibility' => 'archived_dexscreener']],
        ])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertSame(MonthlyRanking::SOURCE_EXACT_DEXSCREENER_RANK, $this->januaryBucket('solana')->source_type);
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $this->januaryBucket('solana')->status);
    }

    // ==== 8: best-supported status ==================================

    #[Test]
    public function an_incomplete_candidate_is_a_best_supported_candidate_with_low_confidence(): void
    {
        $this->writeSeed([$this->candidate([
            'symbol' => 'THIN',
            'baseline_market_cap' => null, // no baseline -> no real growth figure
            'age_uncertain' => true,
            'confidence' => 'medium', // provider suggests medium, service caps at low
            'sources' => [['name' => 'CoinGecko', 'url' => 'https://www.coingecko.com/en/coins/thin', 'claim' => 'Reached ~$30M market cap.', 'published_at' => '2026-02-10', 'credibility' => 'historical_provider']],
        ])]);

        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);

        $row = $this->januaryBucket('solana');
        $this->assertSame(MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE, $row->status);
        $this->assertSame(MonthlyRanking::CONFIDENCE_LOW, $row->confidence);
        $this->assertTrue($row->age_uncertain);
        $this->assertNull($row->market_cap_growth_pct);
    }

    // ==== 9: no verified champion ===================================

    #[Test]
    public function no_verified_champion_when_the_seed_file_has_nothing_for_the_bucket(): void
    {
        $result = $this->service()->research(2026, 1, 'base', force: true, now: $this->now);

        $this->assertSame(1, $result->noVerifiedChampion);
        $row = $this->januaryBucket('base');
        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $row->status);
        $this->assertNull($row->token_id);
        $this->assertNull($row->champion_symbol);
    }

    // ==== 10, 11, 12: chain mapping + Other + shared symbols =========

    #[Test]
    public function a_non_core_chain_candidate_lands_in_the_other_bucket(): void
    {
        $this->writeSeed([$this->candidate([
            'chain_bucket' => 'other', 'chain_id' => 'arbitrum', 'symbol' => 'ARBJAN',
            'token_address' => '0x'.Str::random(40),
        ])]);

        $this->service()->research(2026, 1, 'other', force: true, now: $this->now);

        $row = $this->januaryBucket('other');
        $this->assertSame('ARBJAN', $row->champion_symbol);
        $this->assertSame('arbitrum', $row->champion_chain_id, 'the token keeps its real chain_id');
        // The solana bucket must not have picked it up.
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertNull($this->januaryBucket('solana')->token_id);
        $this->assertNull($this->januaryBucket('solana')->champion_symbol);
    }

    #[Test]
    public function a_seed_row_whose_declared_bucket_and_real_chain_disagree_is_rejected(): void
    {
        // chain_bucket says solana but chain_id is base -> not accepted for solana.
        $this->writeSeed([$this->candidate(['chain_bucket' => 'solana', 'chain_id' => 'base', 'symbol' => 'MISMATCH'])]);

        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);

        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $this->januaryBucket('solana')->status);
    }

    #[Test]
    public function the_same_symbol_on_two_chains_is_kept_in_separate_buckets(): void
    {
        $this->writeSeed([
            $this->candidate(['chain_bucket' => 'solana', 'chain_id' => 'solana', 'symbol' => 'SAME', 'token_address' => 'SolSame']),
            $this->candidate(['chain_bucket' => 'bsc', 'chain_id' => 'bsc', 'symbol' => 'SAME', 'token_address' => '0xbscsame'.Str::random(30)]),
        ]);

        $this->service()->research(2026, 1, null, force: true, now: $this->now);

        $this->assertSame('SolSame', $this->januaryBucket('solana')->champion_token_address);
        $this->assertSame('bsc', $this->januaryBucket('bsc')->champion_chain_id);
    }

    // ==== 13, 14, 15, 16, 17, 18: performance + eligibility =========

    #[Test]
    public function the_historical_performance_score_is_deterministic(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'DET'])]);

        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $first = $this->januaryBucket('solana')->only(['performance_score', 'market_cap_growth_pct', 'peak_expansion_ratio', 'activity_score']);

        MonthlyRanking::query()->delete();
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $second = $this->januaryBucket('solana')->only(['performance_score', 'market_cap_growth_pct', 'peak_expansion_ratio', 'activity_score']);

        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_peak_market_cap_below_five_million_is_rejected(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'TINY', 'peak_market_cap' => 3_000_000, 'baseline_market_cap' => 1_000_000])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $this->januaryBucket('solana')->status);
    }

    #[Test]
    public function a_peak_market_cap_above_two_hundred_million_is_rejected(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'HUGE', 'peak_market_cap' => 500_000_000, 'baseline_market_cap' => 10_000_000])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $this->januaryBucket('solana')->status);
    }

    #[Test]
    public function a_launch_date_whose_30_day_window_misses_the_month_is_rejected(): void
    {
        // Launched 2025-11-01 -> 30-day window ends 2025-12-01, well before January.
        $this->writeSeed([$this->candidate(['symbol' => 'OLD', 'launch_date' => '2025-11-01', 'age_uncertain' => false])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $this->januaryBucket('solana')->status);
    }

    #[Test]
    public function an_unknown_launch_date_is_marked_age_uncertain_and_not_dropped(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'AGEQ', 'launch_date' => null, 'age_uncertain' => true])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);

        $row = $this->januaryBucket('solana');
        $this->assertContains($row->status, [MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE, MonthlyRanking::STATUS_FINALIZED]);
        $this->assertTrue($row->age_uncertain);
        // age_uncertain caps a would-be finalize at best_supported.
        $this->assertSame(MonthlyRanking::STATUS_BEST_SUPPORTED_CANDIDATE, $row->status);
    }

    #[Test]
    public function a_candidate_with_no_sources_is_never_accepted(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'NOSRC', 'sources' => []])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $this->januaryBucket('solana')->status);
    }

    #[Test]
    public function a_candidate_with_no_name_or_symbol_is_never_accepted(): void
    {
        $this->writeSeed([$this->candidate(['name' => '', 'symbol' => ''])]);
        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertSame(MonthlyRanking::STATUS_NO_VERIFIED_CHAMPION, $this->januaryBucket('solana')->status);
    }

    // ==== 19: risk score does not affect selection ==================

    #[Test]
    public function the_research_service_never_reads_the_risk_assessment(): void
    {
        // A tracked token with a CRITICAL risk assessment is still a valid
        // historical performer if the evidence supports it.
        $token = Token::query()->create([
            'chain_id' => 'solana', 'token_address' => 'RiskySol'.Str::random(12), 'symbol' => 'RISKY', 'name' => 'Risky',
            'earliest_pair_created_at' => $this->january->start->addDay(),
            'first_observed_at' => $this->january->start->addDay(),
            'last_observed_at' => $this->january->endExclusive->subDay(),
            'observed_peak_market_cap' => 40_000_000.0, 'observed_peak_market_cap_at' => $this->january->start->addDays(10),
        ]);
        HistoricalPeakEvidence::query()->create([
            'token_id' => $token->id, 'status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
            'peak_value_usd' => 40_000_000.0, 'peak_observed_at' => $this->january->start->addDays(10),
            'evidence_source' => 'dexscreener', 'evidence_basis' => 'current_market_cap', 'checked_at' => $this->now,
        ]);
        $token->riskAssessment()->create([
            'risk_level' => 'CRITICAL', 'risk_score' => 95, 'data_completeness' => 1.0,
            'screening_status' => 'completed', 'hard_override_signal' => 'is_honeypot',
            'main_list_eligible' => false, 'screened_at' => $this->now,
        ]);
        foreach (range(0, 7) as $i) {
            DB::table('market_snapshots')->insert([
                'token_id' => $token->id, 'observed_at' => $this->january->start->addDays($i * 3),
                'price_usd' => 0.01, 'market_cap' => 8_000_000 + $i * 4_000_000, 'fdv' => 20_000_000,
                'liquidity_usd' => 400_000.0, 'volume_h24' => 1_000_000.0, 'price_change_h24' => 3.0,
                'txns_h24' => 2_000, 'buys_h24' => 1_100, 'sells_h24' => 900,
                'primary_pair_address' => 'p', 'primary_dex_id' => 'd',
                'earliest_pair_created_at' => $token->earliest_pair_created_at,
                'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
        }

        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);

        $row = $this->januaryBucket('solana');
        $this->assertSame($token->id, $row->token_id);
        $this->assertSame(MonthlyRanking::STATUS_FINALIZED, $row->status);
        $this->assertSame(MonthlyRanking::SOURCE_INTERNAL_OBSERVED, $row->source_type);
    }

    // ==== 20, 21: current + future =================================

    #[Test]
    public function the_current_month_is_not_backfilled_without_force(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->research(2026, 9, null, force: false, now: $this->now); // September is current
    }

    #[Test]
    public function a_future_month_stays_future_with_no_token(): void
    {
        $result = $this->service()->research(2026, 12, null, force: true, now: $this->now);

        $this->assertSame(5, $result->future);
        foreach (MonthlyRanking::query()->where('year', 2026)->where('month', 12)->get() as $row) {
            $this->assertSame(MonthlyRanking::STATUS_FUTURE, $row->status);
            $this->assertNull($row->token_id);
        }
    }

    // ==== 22: read API never triggers research =====================

    #[Test]
    public function the_read_api_never_triggers_research(): void
    {
        Http::fake();
        $before = MonthlyRanking::query()->count();
        $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();
        $this->assertSame($before, MonthlyRanking::query()->count());
        Http::assertNothingSent();
    }

    // ==== 23, 24, 25: command targeting + force ====================

    #[Test]
    public function the_command_can_target_one_bucket_or_a_full_month_and_force_works(): void
    {
        $this->writeSeed([
            $this->candidate(['chain_bucket' => 'solana', 'chain_id' => 'solana', 'symbol' => 'CMDSOL']),
            $this->candidate(['chain_bucket' => 'bsc', 'chain_id' => 'bsc', 'symbol' => 'CMDBSC']),
        ]);

        // One bucket.
        $this->artisan('memecoins:research-monthly-champions --year=2026 --month=1 --chain=solana')
            ->expectsOutputToContain('researching')
            ->assertExitCode(0);
        $this->assertSame(1, MonthlyRanking::query()->where('year', 2026)->where('month', 1)->count());
        $this->assertSame('solana', MonthlyRanking::query()->where('year', 2026)->where('month', 1)->sole()->chain_bucket);

        // Full month (5 buckets).
        $this->artisan('memecoins:research-monthly-champions --year=2026 --month=1')->assertExitCode(0);
        $this->assertSame(5, MonthlyRanking::query()->where('year', 2026)->where('month', 1)->count());

        // Without --force a finalized bucket is skipped.
        $result = $this->service()->research(2026, 1, 'solana', force: false, now: $this->now);
        $this->assertSame(1, $result->skipped);

        // --force re-researches.
        $result = $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);
        $this->assertSame(0, $result->skipped);
    }

    #[Test]
    public function the_command_is_invalid_without_year_and_month(): void
    {
        $this->artisan('memecoins:research-monthly-champions')->assertExitCode(2);
        $this->artisan('memecoins:research-monthly-champions --year=2026')->assertExitCode(2);
        $this->artisan('memecoins:research-monthly-champions --year=2026 --month=13')->assertExitCode(2);
        $this->artisan('memecoins:research-monthly-champions --year=2026 --month=1 --chain=bogus')->assertExitCode(2);
    }

    // ==== 26, 27: source URL preserved, no fabricated date =========

    #[Test]
    public function source_urls_and_real_dates_are_preserved_and_missing_dates_stay_null(): void
    {
        $this->writeSeed([$this->candidate([
            'symbol' => 'SRC',
            'sources' => [
                ['name' => 'CoinGecko', 'url' => 'https://www.coingecko.com/en/coins/src', 'claim' => 'peaked $40M', 'published_at' => '2026-02-05', 'credibility' => 'historical_provider'],
                ['name' => 'Forum post', 'url' => 'https://forum.example/t/123', 'claim' => 'community discussion', 'published_at' => null, 'credibility' => 'low_quality'],
            ],
        ])]);

        $this->service()->research(2026, 1, 'solana', force: true, now: $this->now);

        $evidence = $this->januaryBucket('solana')->source_evidence;
        $this->assertSame('https://www.coingecko.com/en/coins/src', $evidence[0]['url']);
        $this->assertSame('2026-02-05', $evidence[0]['published_at']);
        $this->assertNull($evidence[1]['published_at'], 'a missing publication date is never fabricated');
    }

    // ==== isolation ==============================================

    #[Test]
    public function research_does_not_touch_tokens_pump_events_or_evidence(): void
    {
        $token = Token::query()->create([
            'chain_id' => 'solana', 'token_address' => 'Iso'.Str::random(12), 'symbol' => 'ISO', 'name' => 'Iso',
            'earliest_pair_created_at' => $this->january->start, 'first_observed_at' => $this->january->start,
            'last_observed_at' => $this->january->endExclusive->subDay(),
            'observed_peak_market_cap' => 12_000_000.0,
        ]);
        $peakBefore = $token->observed_peak_market_cap;

        $this->writeSeed([$this->candidate(['symbol' => 'X'])]);
        $this->service()->research(2026, 1, null, force: true, now: $this->now);

        $this->assertSame($peakBefore, $token->fresh()->observed_peak_market_cap);
        $this->assertSame('solana', $token->fresh()->chain_id);
    }
}
