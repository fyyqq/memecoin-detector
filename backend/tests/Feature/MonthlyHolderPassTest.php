<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\MonthlyRanking;
use App\Models\Token;
use App\Services\Ranking\MonthlyChampionService;
use App\Services\Ranking\MonthWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 25 — the monthly holder pass.
 *
 * For the CURRENT provisional month, `MonthlyHolderCollector` polls GeckoTerminal
 * `/info` for the eligible candidates and records the monthly-MAX holder count on
 * the ranking rows. No `market_snapshots` change. GeckoTerminal returning nothing
 * → `holder_count` is `null` (UNKNOWN) and the score renormalizes. A completed
 * PAST month never runs the pass (no live holder history).
 */
class MonthlyHolderPassTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private MonthWindow $september;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-15T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();
        $this->september = MonthWindow::of(2026, 9);

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('ranking.observation_interval_minutes', 4320);
        config()->set('ranking.top_n', 3);
        config()->set('ranking.weights', ['holder' => 0.40, 'volume' => 0.35, 'market_cap' => 0.25]);
        config()->set('ranking.references', ['holder_count' => 10_000, 'volume_usd' => 20_000_000, 'market_cap_usd' => 50_000_000]);

        config()->set('ranking.holder_pass.enabled', true);
        config()->set('ranking.holder_pass.max_tokens_per_run', 25);
        config()->set('ranking.holder_pass.cooldown_hours', 20);
        config()->set('risk.geckoterminal.enabled', true);
        config()->set('historical.geckoterminal.enabled', true);
        config()->set('historical.geckoterminal.base_url', 'https://api.geckoterminal.com/api/v2');
        config()->set('historical.geckoterminal.cache_ttl', 0);
        config()->set('historical.chain_map.solana', ['coingecko' => 'solana', 'geckoterminal' => 'solana']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    private function token(string $symbol, string $address): Token
    {
        /** @var Token $t */
        $t = Token::query()->create([
            'chain_id' => 'solana', 'token_address' => $address, 'symbol' => $symbol, 'name' => $symbol,
            'earliest_pair_created_at' => $this->september->start->addDay(),
            'first_observed_at' => $this->september->start->addDay(),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 30_000_000.0, 'observed_peak_market_cap_at' => $this->september->start->addDays(3),
        ]);
        HistoricalPeakEvidence::query()->create([
            'token_id' => $t->id, 'status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
            'peak_value_usd' => 30_000_000.0, 'peak_observed_at' => $this->september->start->addDays(3),
            'evidence_source' => 'dexscreener', 'evidence_basis' => 'current_market_cap', 'checked_at' => $this->now,
        ]);
        $spanSeconds = $this->now->getTimestamp() - $this->september->start->getTimestamp();
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = [
                'token_id' => $t->id, 'observed_at' => $this->september->start->addSeconds((int) round($spanSeconds * ($i + 0.5) / 6)),
                'price_usd' => 0.01, 'market_cap' => 30_000_000.0, 'fdv' => 36_000_000.0,
                'liquidity_usd' => 400_000.0, 'volume_h24' => 9_000_000.0, 'price_change_h24' => 5.0,
                'txns_h24' => 3_000, 'buys_h24' => 1_700, 'sells_h24' => 1_300,
                'primary_pair_address' => 'pair', 'primary_dex_id' => 'raydium',
                'earliest_pair_created_at' => $t->earliest_pair_created_at, 'created_at' => $this->now, 'updated_at' => $this->now,
            ];
        }
        DB::table('market_snapshots')->insert($rows);

        return $t->refresh();
    }

    private function fakeGecko(?int $holders): void
    {
        Http::fake([
            'api.geckoterminal.com/api/v2/networks/solana/tokens/*/info*' => Http::response([
                'data' => ['attributes' => $holders === null ? [] : ['holders' => ['count' => $holders]]],
            ], 200),
        ]);
    }

    #[Test]
    public function the_provisional_month_records_a_holder_count_from_geckoterminal(): void
    {
        $this->fakeGecko(42_000);
        $this->token('HOLD', 'So1Hold'.Str::random(20));

        app(MonthlyChampionService::class)->computeAndStoreBucket($this->september, 'solana', finalize: false, force: false, now: $this->now);

        $row = MonthlyRanking::query()->where('month', 9)->where('chain_bucket', 'solana')->where('rank', 1)->firstOrFail();
        $this->assertSame(42_000, $row->holder_count);
        $this->assertNotNull($row->holder_strength);
        $this->assertNotNull($row->holder_checked_at);
    }

    #[Test]
    public function geckoterminal_returning_nothing_leaves_holder_count_null_and_renormalizes_the_score(): void
    {
        $this->fakeGecko(null);
        $this->token('NOHOLD', 'So1No'.Str::random(20));

        app(MonthlyChampionService::class)->computeAndStoreBucket($this->september, 'solana', finalize: false, force: false, now: $this->now);

        $row = MonthlyRanking::query()->where('month', 9)->where('chain_bucket', 'solana')->where('rank', 1)->firstOrFail();
        $this->assertNull($row->holder_count);
        $this->assertNull($row->holder_strength);
        $this->assertNotNull($row->performance_score);   // still scored on volume + market cap
        $this->assertNull($row->scoring_breakdown['holder_strength'] ?? null);
    }

    #[Test]
    public function the_monthly_max_is_carried_forward_across_daily_runs(): void
    {
        config()->set('ranking.holder_pass.cooldown_hours', 1);
        $holders = ['n' => 30_000];
        Http::fake([
            'api.geckoterminal.com/api/v2/networks/solana/tokens/*/info*' => function () use (&$holders) {
                return Http::response(['data' => ['attributes' => ['holders' => ['count' => $holders['n']]]]], 200);
            },
        ]);
        $this->token('MAX', 'So1Max'.Str::random(20));
        $count = fn (): ?int => MonthlyRanking::query()->where('month', 9)->where('rank', 1)->first()->holder_count;

        app(MonthlyChampionService::class)->computeAndStoreBucket($this->september, 'solana', false, false, $this->now);
        $this->assertSame(30_000, $count());

        // Next day, the live count DROPS — the stored monthly max must not.
        $holders['n'] = 18_000;
        CarbonImmutable::setTestNow($this->now->addDay());
        app(MonthlyChampionService::class)->computeAndStoreBucket($this->september, 'solana', false, false, CarbonImmutable::now());
        $this->assertSame(30_000, $count());

        // Then it RISES above the prior max — now it climbs.
        $holders['n'] = 55_000;
        CarbonImmutable::setTestNow($this->now->addDays(2));
        app(MonthlyChampionService::class)->computeAndStoreBucket($this->september, 'solana', false, false, CarbonImmutable::now());
        $this->assertSame(55_000, $count());
    }

    #[Test]
    public function the_per_token_cooldown_skips_a_recently_checked_token(): void
    {
        $this->token('CD', 'So1Cd'.Str::random(20));
        $this->fakeGecko(20_000);
        app(MonthlyChampionService::class)->computeAndStoreBucket($this->september, 'solana', false, false, $this->now);
        $calls = count(Http::recorded());
        $this->assertGreaterThan(0, $calls);

        // 2h later — inside the 20h cooldown -> no new GeckoTerminal call.
        CarbonImmutable::setTestNow($this->now->addHours(2));
        app(MonthlyChampionService::class)->computeAndStoreBucket($this->september, 'solana', false, false, CarbonImmutable::now());
        $this->assertCount($calls, Http::recorded());
    }

    #[Test]
    public function a_past_month_never_runs_the_holder_pass(): void
    {
        // July is past; a live GeckoTerminal fake exists but must not be hit.
        $this->fakeGecko(99_000);
        app(MonthlyChampionService::class)->finalizeMonth(2026, 7, force: true, now: $this->now);

        Http::assertNothingSent();
    }

    #[Test]
    public function the_holder_pass_can_be_disabled(): void
    {
        config()->set('ranking.holder_pass.enabled', false);
        $this->fakeGecko(42_000);
        $this->token('OFF', 'So1Off'.Str::random(20));

        app(MonthlyChampionService::class)->computeAndStoreBucket($this->september, 'solana', false, false, $this->now);

        $this->assertNull(MonthlyRanking::query()->where('month', 9)->where('rank', 1)->first()->holder_count);
        Http::assertNothingSent();
    }
}
