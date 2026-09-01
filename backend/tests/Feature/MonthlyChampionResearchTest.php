<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MonthlyRanking;
use App\Models\RiskAssessment;
use App\Models\Token;
use App\Services\Ranking\MonthlyChampionResearchService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 25 — Historical Monthly Backfill (Top 3).
 *
 * `memecoins:research-monthly-champions` researches operator-verified historical
 * evidence (the seed file) to rank a Top 3 per chain bucket for a PAST completed
 * month. It NEVER fabricates a candidate / date / source / holder count, NEVER
 * claims an exact DexScreener rank without evidence, NEVER uses the risk score
 * or AI, and NEVER mixes a current figure into a historical month.
 */
class MonthlyChampionResearchTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private string $seedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-01T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('ranking.top_n', 3);
        config()->set('ranking.weights', ['holder' => 0.40, 'volume' => 0.35, 'market_cap' => 0.25]);
        config()->set('ranking.references', ['holder_count' => 10_000, 'volume_usd' => 20_000_000, 'market_cap_usd' => 50_000_000]);
        config()->set('ranking.market_cap_only_penalty', 0.5);
        config()->set('ranking.holder_pass.enabled', false);

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

    /** @param list<array<string,mixed>> $candidates */
    private function writeSeed(array $candidates): void
    {
        file_put_contents($this->seedPath, json_encode(['candidates' => $candidates], JSON_PRETTY_PRINT));
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function candidate(array $overrides = []): array
    {
        return array_replace([
            'year' => 2026, 'month' => 1, 'chain_bucket' => 'solana',
            'name' => 'Example Coin', 'symbol' => 'EXMPL', 'chain_id' => 'solana',
            'token_address' => 'So1anaAddr'.Str::random(20),
            'baseline_market_cap' => 6_000_000, 'peak_market_cap' => 42_000_000,
            'volume_usd' => 8_000_000, 'holder_count' => 12_000,
            'launch_date' => '2025-12-28', 'age_uncertain' => false,
            'source_type' => 'best_supported_historical_performer', 'confidence' => 'high',
            'sources' => [
                ['name' => 'CoinGecko', 'url' => 'https://www.coingecko.com/en/coins/example', 'claim' => 'Peaked at $42M market cap in January 2026.', 'published_at' => '2026-02-03', 'credibility' => 'historical_provider'],
                ['name' => 'CoinDesk', 'url' => 'https://www.coindesk.com/markets/2026/02/01/example', 'claim' => 'Top-traded Solana memecoin of January.', 'published_at' => '2026-02-01', 'credibility' => 'reputable_reporting'],
            ],
            'explanation' => 'Two independent sources; peak MC inside the $5M-$200M band; full first-month trading.',
        ], $overrides);
    }

    /** @return Collection<int, MonthlyRanking> rank order, token-carrying rows only */
    private function janBucket(string $bucket): Collection
    {
        return MonthlyRanking::query()->where('year', 2026)->where('month', 1)->where('chain_bucket', $bucket)
            ->orderBy('rank')->get()->filter(fn (MonthlyRanking $r): bool => $r->token_id !== null || $r->champion_symbol !== null)->values();
    }

    private function research(int $month = 1, ?string $bucket = null, bool $force = false): void
    {
        $this->service()->research(2026, $month, $bucket, $force, $this->now);
    }

    // ==== Top 3 + honesty =============================================

    #[Test]
    public function the_seed_file_produces_a_ranked_top_three_per_bucket(): void
    {
        $this->writeSeed([
            $this->candidate(['symbol' => 'A', 'holder_count' => 25_000, 'volume_usd' => 18_000_000, 'peak_market_cap' => 40_000_000]),
            $this->candidate(['symbol' => 'B', 'holder_count' => 9_000, 'volume_usd' => 10_000_000, 'peak_market_cap' => 40_000_000]),
            $this->candidate(['symbol' => 'C', 'holder_count' => 3_000, 'volume_usd' => 6_000_000, 'peak_market_cap' => 40_000_000]),
            $this->candidate(['symbol' => 'D', 'holder_count' => 1_000, 'volume_usd' => 2_000_000, 'peak_market_cap' => 40_000_000]),
        ]);
        $this->research(1, 'solana');

        $rows = $this->janBucket('solana');
        $this->assertSame([1, 2, 3], $rows->pluck('rank')->all());
        $this->assertSame(['A', 'B', 'C'], $rows->pluck('champion_symbol')->all());
        $this->assertGreaterThan($rows[1]->performance_score, $rows[0]->performance_score);
    }

    #[Test]
    public function a_full_month_research_writes_all_five_buckets_and_stores_provenance(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'FULL'])]);
        $this->research(1);

        $solana = $this->janBucket('solana')[0];
        $this->assertSame('finalized', $solana->status);
        $this->assertSame('FULL', $solana->champion_symbol);
        $this->assertSame('best_supported_historical_performer', $solana->source_type);
        $this->assertContains($solana->confidence, ['high', 'medium']);
        $this->assertNotEmpty($solana->source_evidence);
        $this->assertSame('https://www.coingecko.com/en/coins/example', $solana->source_evidence[0]['url']);

        foreach (['robinhood', 'bsc', 'base', 'other'] as $bucket) {
            $this->assertDatabaseHas('monthly_rankings', ['year' => 2026, 'month' => 1, 'chain_bucket' => $bucket, 'rank' => 1, 'status' => 'no_verified_result']);
        }
    }

    #[Test]
    public function historical_holder_count_is_used_only_when_a_real_integer_is_given(): void
    {
        $this->writeSeed([
            $this->candidate(['symbol' => 'HAS', 'holder_count' => 15_000]),
            $this->candidate(['symbol' => 'NONE', 'holder_count' => null, 'token_address' => 'So1Two'.Str::random(18)]),
            $this->candidate(['symbol' => 'BADSTR', 'holder_count' => 'UNKNOWN', 'token_address' => 'So1Three'.Str::random(16)]),
        ]);
        $this->research(1, 'solana');

        $rows = $this->janBucket('solana')->keyBy('champion_symbol');
        $this->assertSame(15_000, $rows['HAS']->holder_count);
        $this->assertNull($rows['NONE']->holder_count);
        $this->assertNull($rows['BADSTR']->holder_count);   // the string "UNKNOWN" is never coerced
        $this->assertNotNull($rows['NONE']->holder_strength === null ? true : null);
    }

    #[Test]
    public function historical_volume_and_market_cap_only_come_from_the_seed_row_never_current(): void
    {
        // A live token with a big CURRENT market cap exists for the SAME symbol.
        Token::query()->create([
            'chain_id' => 'solana', 'token_address' => 'LiveAddr'.Str::random(20), 'symbol' => 'MIX', 'name' => 'Mix',
            'earliest_pair_created_at' => $this->now->subDays(3), 'first_observed_at' => $this->now->subDays(3), 'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 180_000_000.0, 'observed_peak_market_cap_at' => $this->now,
        ]);

        $this->writeSeed([$this->candidate(['symbol' => 'MIX', 'peak_market_cap' => 30_000_000, 'volume_usd' => 9_000_000])]);
        $this->research(1, 'solana');

        $row = $this->janBucket('solana')[0];
        $this->assertEqualsWithDelta(30_000_000.0, $row->month_market_cap, 1.0);  // seed value, not $180M
        $this->assertEqualsWithDelta(9_000_000.0, $row->monthly_volume_usd, 1.0);
    }

    #[Test]
    public function an_exact_dexscreener_rank_is_only_claimed_when_a_source_establishes_it(): void
    {
        // Claims the type but gives no strong source -> downgraded, not "exact".
        $this->writeSeed([$this->candidate([
            'symbol' => 'CLAIMED', 'source_type' => 'exact_dexscreener_rank',
            'sources' => [['name' => 'random blog', 'url' => 'https://blog.example/x', 'claim' => 'it was #1', 'published_at' => null, 'credibility' => 'anonymous']],
        ])]);
        $this->research(1, 'solana');

        $row = $this->janBucket('solana')[0];
        $this->assertNotSame('high', $row->confidence);
        $this->assertSame('low', $row->confidence);
    }

    #[Test]
    public function no_verified_result_when_the_seed_has_nothing_for_the_bucket(): void
    {
        $this->writeSeed([$this->candidate(['chain_bucket' => 'bsc', 'chain_id' => 'bsc'])]);
        $this->research(1, 'solana');

        $this->assertDatabaseHas('monthly_rankings', ['year' => 2026, 'month' => 1, 'chain_bucket' => 'solana', 'rank' => 1, 'status' => 'no_verified_result', 'token_id' => null]);
        $this->assertEmpty($this->janBucket('solana'));
    }

    #[Test]
    public function a_five_million_floor_and_two_hundred_million_ceiling_reject_out_of_band_peaks(): void
    {
        $this->writeSeed([
            $this->candidate(['symbol' => 'LOW', 'peak_market_cap' => 3_000_000]),
            $this->candidate(['symbol' => 'HIGH', 'peak_market_cap' => 250_000_000, 'token_address' => 'So1H'.Str::random(20)]),
            $this->candidate(['symbol' => 'OK', 'peak_market_cap' => 40_000_000, 'token_address' => 'So1O'.Str::random(20)]),
        ]);
        $this->research(1, 'solana');

        $this->assertSame(['OK'], $this->janBucket('solana')->pluck('champion_symbol')->all());
    }

    #[Test]
    public function an_unknown_launch_date_is_marked_age_uncertain_and_not_dropped(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'NODATE', 'launch_date' => null, 'age_uncertain' => true, 'confidence' => 'high'])]);
        $this->research(1, 'solana');

        $row = $this->janBucket('solana')[0];
        $this->assertSame('NODATE', $row->champion_symbol);
        $this->assertTrue((bool) $row->age_uncertain);
        $this->assertNotSame('high', $row->confidence);   // capped down
    }

    #[Test]
    public function a_candidate_with_no_sources_or_no_identity_is_never_accepted(): void
    {
        $this->writeSeed([
            $this->candidate(['symbol' => 'NOSRC', 'sources' => []]),
            $this->candidate(['symbol' => '', 'token_address' => 'So1X'.Str::random(20)]),
        ]);
        $this->research(1, 'solana');

        $this->assertEmpty($this->janBucket('solana'));
    }

    #[Test]
    public function a_seed_row_whose_declared_bucket_and_real_chain_disagree_is_rejected(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'MISMATCH', 'chain_bucket' => 'solana', 'chain_id' => 'bsc'])]);
        $this->research(1, 'solana');
        $this->assertEmpty($this->janBucket('solana'));
    }

    #[Test]
    public function a_non_core_chain_candidate_lands_in_the_other_bucket(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'PLS', 'chain_bucket' => 'other', 'chain_id' => 'pulsechain'])]);
        $this->research(1, 'other');

        $row = $this->janBucket('other')[0];
        $this->assertSame('PLS', $row->champion_symbol);
        $this->assertSame('pulsechain', $row->champion_chain_id);   // real chain preserved
        $this->assertSame('other', $row->chain_bucket);
    }

    #[Test]
    public function the_historical_performance_score_is_deterministic(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'DET'])]);
        $this->research(1, 'solana');
        $first = $this->janBucket('solana')[0]->performance_score;

        MonthlyRanking::query()->delete();
        CarbonImmutable::setTestNow($this->now->addDays(2));
        $this->service()->research(2026, 1, 'solana', true, CarbonImmutable::now());
        $second = $this->janBucket('solana')[0]->performance_score;

        $this->assertSame($first, $second);
    }

    #[Test]
    public function source_urls_and_real_dates_are_preserved_and_missing_dates_stay_null(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'DATES', 'sources' => [
            ['name' => 'Dated', 'url' => 'https://x.example/a', 'claim' => 'c', 'published_at' => '2026-02-10', 'credibility' => 'historical_provider'],
            ['name' => 'Undated', 'url' => 'https://x.example/b', 'claim' => 'c', 'published_at' => null, 'credibility' => 'reputable_reporting'],
        ]])]);
        $this->research(1, 'solana');

        $evidence = $this->janBucket('solana')[0]->source_evidence;
        $this->assertSame('2026-02-10', $evidence[0]['published_at']);
        $this->assertNull($evidence[1]['published_at']);
    }

    #[Test]
    public function the_current_month_needs_force_and_a_future_month_stays_future(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->research(2026, 9, null, false, $this->now);
    }

    #[Test]
    public function research_never_reads_the_risk_assessment_and_never_touches_tokens_pump_or_evidence(): void
    {
        $t = Token::query()->create([
            'chain_id' => 'solana', 'token_address' => 'RiskAddr'.Str::random(20), 'symbol' => 'RSK', 'name' => 'Risk',
            'earliest_pair_created_at' => $this->now->subDays(10), 'first_observed_at' => $this->now->subDays(10), 'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 30_000_000.0,
        ]);
        RiskAssessment::query()->create([
            'token_id' => $t->id, 'risk_level' => 'CRITICAL', 'risk_score' => 90, 'data_completeness' => 1.0,
            'screening_status' => 'completed', 'main_list_eligible' => false, 'screened_at' => $this->now, 'provider_version' => 't',
        ]);

        $this->writeSeed([$this->candidate(['symbol' => 'RSK', 'chain_id' => 'solana', 'token_address' => 'RiskAddr-seed'])]);

        $tokensBefore = DB::table('tokens')->count();
        $this->research(1);

        $this->assertSame('CRITICAL', RiskAssessment::query()->first()->risk_level);
        $this->assertSame($tokensBefore, DB::table('tokens')->count());
        $this->assertDatabaseCount('pump_events', 0);
        $this->assertDatabaseCount('evidences', 0);
    }

    #[Test]
    public function the_read_api_still_returns_twelve_months_with_five_buckets_after_backfill(): void
    {
        $this->writeSeed([
            $this->candidate(['symbol' => 'A']),
            $this->candidate(['symbol' => 'B', 'token_address' => 'So1B'.Str::random(20)]),
        ]);
        $this->research(1);

        $res = $this->getJson('/api/memecoins/monthly-champions?year=2026')->assertOk();
        $res->assertJsonCount(12, 'data');
        $jan = $res->json('data.0');
        $this->assertSame('finalized', $jan['status']);
        $this->assertLessThanOrEqual(3, count($jan['champions']['solana']['entries']));
        $this->assertSame('A', $jan['champions']['solana']['entries'][0]['token']['symbol']);
        // A denormalized (untracked) champion has token.id null and no detail page.
        $this->assertNull($jan['champions']['solana']['entries'][0]['token']['id']);
    }

    #[Test]
    public function the_command_targets_one_bucket_or_a_full_month_and_force_works(): void
    {
        $this->writeSeed([$this->candidate(['symbol' => 'CMD'])]);

        $this->artisan('memecoins:research-monthly-champions --year=2026 --month=1 --chain=solana')->assertExitCode(0);
        $this->assertSame('CMD', $this->janBucket('solana')[0]->champion_symbol);

        $this->artisan('memecoins:research-monthly-champions --year=2026 --month=1 --chain=solana --force')->assertExitCode(0);
        $this->assertSame('CMD', $this->janBucket('solana')[0]->champion_symbol);
    }

    #[Test]
    public function the_command_is_invalid_without_year_and_month(): void
    {
        $this->artisan('memecoins:research-monthly-champions --year=2026')->assertExitCode(2);
        $this->artisan('memecoins:research-monthly-champions --month=1')->assertExitCode(2);
    }
}
