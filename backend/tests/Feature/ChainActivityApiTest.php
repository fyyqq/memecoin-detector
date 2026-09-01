<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyChainActivity;
use App\Models\Token;
use App\Services\DexScreener\DexScreenerDiscoveryService;
use App\Services\DexScreener\DiscoveryResult;
use App\Services\Trending\ChainActivityRollup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Chain Market Activity" (`GET /api/memecoins/chain-activity`) — read-only,
 * PostgreSQL only. Reads the materialised `daily_chain_activity` table, which is
 * (re)computed by `ChainActivityRollup` inside the `memecoins:discover` run.
 */
class ChainActivityApiTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-02T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('trending.volume.active_within_hours', 48);
        config()->set('trending.integrity.min_liquidity_usd', 1.0);
        config()->set('trending.integrity.min_transaction_count', 1);
        config()->set('trending.integrity.max_market_cap_usd', 1_000_000_000_000.0);
        config()->set('trending.integrity.max_volume_liquidity_ratio', 75.0);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    private function token(string $chain, string $addr, array $tokenOverrides = [], array $snapshotOverrides = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => $chain,
            'token_address' => $addr,
            'symbol' => mb_strtoupper(mb_substr($addr, 0, 5)),
            'name' => ucfirst($addr),
            'earliest_pair_created_at' => $this->now->subDays(10),
            'first_observed_at' => $this->now->subDays(10),
            'last_observed_at' => $this->now,
        ], $tokenOverrides));

        $token->marketSnapshots()->create(array_replace([
            'observed_at' => $this->now,
            'price_usd' => 0.01,
            'market_cap' => 20_000_000.0,
            'liquidity_usd' => 250_000.0,
            'volume_h24' => 1_000_000.0,
            'price_change_h24' => 5.0,
            'txns_h24' => 800,
        ], $snapshotOverrides));

        return $token;
    }

    #[Test]
    public function the_endpoint_always_returns_all_five_chain_buckets(): void
    {
        $data = $this->getJson('/api/memecoins/chain-activity')->assertOk()->json('data');

        $this->assertSame(
            ['solana', 'robinhood', 'bsc', 'base', 'other'],
            array_column($data, 'chain_bucket'),
        );
    }

    #[Test]
    public function it_reports_the_stored_totals_and_a_day_over_day_delta(): void
    {
        DailyChainActivity::query()->create([
            'date' => $this->now->subDay()->toDateString(),
            'chain_bucket' => 'solana',
            'total_volume_usd' => 1_000_000.0,
            'total_liquidity_usd' => 500_000.0,
            'active_token_count' => 3,
            'computed_at' => $this->now->subDay(),
        ]);
        DailyChainActivity::query()->create([
            'date' => $this->now->toDateString(),
            'chain_bucket' => 'solana',
            'total_volume_usd' => 1_500_000.0,
            'total_liquidity_usd' => 600_000.0,
            'active_token_count' => 4,
            'top_token_address' => 'Winner',
            'top_token_symbol' => 'WIN',
            'top_token_volume' => 900_000.0,
            'computed_at' => $this->now,
        ]);

        $solana = collect($this->getJson('/api/memecoins/chain-activity')->assertOk()->json('data'))
            ->firstWhere('chain_bucket', 'solana');

        $this->assertEqualsWithDelta(1_500_000.0, $solana['total_volume_usd'], 0.01);
        $this->assertSame(4, $solana['active_token_count']);
        $this->assertSame('WIN', $solana['top_token']['symbol']);
        $this->assertEqualsWithDelta(50.0, $solana['volume_change_pct'], 0.01);
    }

    #[Test]
    public function the_delta_is_null_when_there_is_no_prior_day_row(): void
    {
        DailyChainActivity::query()->create([
            'date' => $this->now->toDateString(),
            'chain_bucket' => 'base',
            'total_volume_usd' => 10_000.0,
            'total_liquidity_usd' => 5_000.0,
            'active_token_count' => 1,
            'computed_at' => $this->now,
        ]);

        $base = collect($this->getJson('/api/memecoins/chain-activity')->assertOk()->json('data'))
            ->firstWhere('chain_bucket', 'base');

        $this->assertNull($base['volume_change_pct']);
    }

    #[Test]
    public function the_read_endpoint_makes_no_provider_calls_and_no_writes(): void
    {
        $this->token('solana', 'ReadOnly');
        DailyChainActivity::query()->create([
            'date' => $this->now->toDateString(),
            'chain_bucket' => 'solana',
            'total_volume_usd' => 1.0,
            'total_liquidity_usd' => 1.0,
            'active_token_count' => 1,
            'computed_at' => $this->now,
        ]);

        $before = DB::table('daily_chain_activity')->count() + DB::table('tokens')->count();
        $this->getJson('/api/memecoins/chain-activity')->assertOk();
        $after = DB::table('daily_chain_activity')->count() + DB::table('tokens')->count();

        $this->assertSame($before, $after);
    }

    #[Test]
    public function the_rollup_materialises_one_row_per_bucket_deduped_and_integrity_gated(): void
    {
        // Two Solana tokens (summed), one BSC token, one wash-trade shape that
        // the integrity gate must exclude.
        $this->token('solana', 'SolA', [], ['volume_h24' => 800_000.0, 'liquidity_usd' => 400_000.0]);
        $this->token('solana', 'SolB', [], ['volume_h24' => 200_000.0, 'liquidity_usd' => 100_000.0]);
        $this->token('bsc', 'BscA', [], ['volume_h24' => 500_000.0, 'liquidity_usd' => 250_000.0]);
        $this->token('solana', 'Wash', [], ['volume_h24' => 90_000_000.0, 'liquidity_usd' => 1_000.0]);

        $written = app(ChainActivityRollup::class)->recompute($this->now);

        $this->assertSame(5, $written);
        $this->assertSame(5, DailyChainActivity::where('date', $this->now->toDateString())->count());

        $solana = DailyChainActivity::where('date', $this->now->toDateString())->where('chain_bucket', 'solana')->firstOrFail();
        $this->assertEqualsWithDelta(1_000_000.0, $solana->total_volume_usd, 1.0);
        $this->assertSame(2, $solana->active_token_count);
        $this->assertSame('SOLA', $solana->top_token_symbol);
    }

    #[Test]
    public function the_discovery_command_refreshes_chain_activity(): void
    {
        $this->mock(DexScreenerDiscoveryService::class)
            ->shouldReceive('discover')
            ->once()
            ->andReturn(new DiscoveryResult([], array_fill_keys([
                'raw_discovery_candidates', 'unique_candidates', 'enriched_ok', 'age_eligible',
                'snapshots_written', 'new_tokens', 'peak_updated', 'qualified',
            ], 0), [], 7));

        $this->token('base', 'BaseTok', [], ['volume_h24' => 300_000.0, 'liquidity_usd' => 150_000.0]);

        $this->artisan('memecoins:discover')
            ->expectsOutputToContain('Chain activity rows: 5')
            ->assertExitCode(0);

        $this->assertSame(5, DailyChainActivity::where('date', $this->now->toDateString())->count());
        $base = DailyChainActivity::where('date', $this->now->toDateString())->where('chain_bucket', 'base')->firstOrFail();
        $this->assertEqualsWithDelta(300_000.0, $base->total_volume_usd, 1.0);
    }

    #[Test]
    public function a_token_with_only_a_stale_snapshot_is_not_counted_active(): void
    {
        $this->token('solana', 'Stale', [], ['observed_at' => $this->now->subDays(5), 'volume_h24' => 400_000.0]);

        app(ChainActivityRollup::class)->recompute($this->now);

        $solana = DailyChainActivity::where('date', $this->now->toDateString())->where('chain_bucket', 'solana')->firstOrFail();
        $this->assertSame(0, $solana->active_token_count);
        $this->assertEqualsWithDelta(0.0, $solana->total_volume_usd, 0.01);
    }
}
