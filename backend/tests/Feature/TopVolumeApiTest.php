<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesRiskAssessments;
use Tests\TestCase;

/**
 * "Top Volume by Chain" (`GET /api/memecoins/top-volume`) — read-only,
 * PostgreSQL only. Per chain bucket, the top tokens by REPORTED 24h volume (one
 * figure per token — its latest snapshot's representative-pair `volume_h24`),
 * after the shared market-integrity gate. Never calls a provider.
 */
class TopVolumeApiTest extends TestCase
{
    use CreatesRiskAssessments;
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-02T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('trending.volume.top_per_chain', 5);
        config()->set('trending.volume.active_within_hours', 48);
        config()->set('trending.risk_stale_hours', 6);
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

    private function token(string $chain, string $addr, array $snapshotOverrides = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create([
            'chain_id' => $chain,
            'token_address' => $addr,
            'symbol' => mb_strtoupper(mb_substr($addr, 0, 6)),
            'name' => ucfirst($addr),
            'earliest_pair_created_at' => $this->now->subDays(10),
            'first_observed_at' => $this->now->subDays(10),
            'last_observed_at' => $this->now,
        ]);

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
    public function it_always_returns_all_five_buckets(): void
    {
        $data = $this->getJson('/api/memecoins/top-volume')->assertOk()->json('data');

        $this->assertSame(
            ['solana', 'robinhood', 'bsc', 'base', 'other'],
            array_column($data, 'chain_bucket'),
        );
    }

    #[Test]
    public function tokens_are_ranked_by_reported_volume_desc_and_capped_at_five_per_bucket(): void
    {
        foreach ([300_000, 900_000, 100_000, 700_000, 500_000, 50_000] as $i => $vol) {
            $this->token('solana', "sol{$i}", ['volume_h24' => (float) $vol, 'liquidity_usd' => 400_000.0]);
        }

        $solana = collect($this->getJson('/api/memecoins/top-volume')->assertOk()->json('data'))
            ->firstWhere('chain_bucket', 'solana');

        $this->assertCount(5, $solana['tokens']);
        $this->assertSame(
            [900_000, 700_000, 500_000, 300_000, 100_000],
            array_map('intval', array_column($solana['tokens'], 'reported_volume_usd')),
        );
    }

    #[Test]
    public function the_market_integrity_gate_excludes_anomalies(): void
    {
        $this->token('bsc', 'Clean', ['volume_h24' => 400_000.0, 'liquidity_usd' => 200_000.0, 'txns_h24' => 500]);
        $this->token('bsc', 'NoLiquidity', ['volume_h24' => 900_000.0, 'liquidity_usd' => 0.0]);
        $this->token('bsc', 'NoTxns', ['volume_h24' => 900_000.0, 'liquidity_usd' => 200_000.0, 'txns_h24' => 0]);
        $this->token('bsc', 'GarbageMc', ['volume_h24' => 900_000.0, 'liquidity_usd' => 200_000.0, 'market_cap' => 5.0e12]);
        $this->token('bsc', 'WashShape', ['volume_h24' => 90_000_000.0, 'liquidity_usd' => 1_000.0]);

        $bsc = collect($this->getJson('/api/memecoins/top-volume')->assertOk()->json('data'))
            ->firstWhere('chain_bucket', 'bsc');

        $this->assertSame(['CLEAN'], array_column($bsc['tokens'], 'symbol'));
    }

    #[Test]
    public function volume_is_one_figure_per_token_never_summed_across_pools(): void
    {
        // Two snapshots for one token — only the latest counts, once.
        $token = $this->token('solana', 'Multi', ['volume_h24' => 250_000.0]);
        $token->marketSnapshots()->create([
            'observed_at' => $this->now->subHours(2),
            'price_usd' => 0.01,
            'market_cap' => 20_000_000.0,
            'liquidity_usd' => 250_000.0,
            'volume_h24' => 999_000.0,
            'price_change_h24' => 5.0,
            'txns_h24' => 800,
        ]);

        $solana = collect($this->getJson('/api/memecoins/top-volume')->assertOk()->json('data'))
            ->firstWhere('chain_bucket', 'solana');

        $this->assertSame([250_000], array_map('intval', array_column($solana['tokens'], 'reported_volume_usd')));
    }

    #[Test]
    public function an_unknown_chain_maps_into_the_other_bucket(): void
    {
        $this->token('sui', 'SuiTok', ['volume_h24' => 300_000.0, 'liquidity_usd' => 150_000.0]);

        $other = collect($this->getJson('/api/memecoins/top-volume')->assertOk()->json('data'))
            ->firstWhere('chain_bucket', 'other');

        $this->assertSame(['SUITOK'], array_column($other['tokens'], 'symbol'));
    }

    #[Test]
    public function the_chain_filter_narrows_to_one_bucket(): void
    {
        $this->token('solana', 'SolTok', ['volume_h24' => 300_000.0]);
        $this->token('base', 'BaseTok', ['volume_h24' => 300_000.0]);

        $data = $this->getJson('/api/memecoins/top-volume?chain=base')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('base', $data[0]['chain_bucket']);
        $this->assertSame(['BASETO'], array_column($data[0]['tokens'], 'symbol'));
    }

    #[Test]
    public function a_stale_risk_scan_is_flagged(): void
    {
        $fresh = $this->token('solana', 'Fresh', ['volume_h24' => 400_000.0]);
        $stale = $this->token('solana', 'Stale', ['volume_h24' => 300_000.0]);
        $this->passRisk($fresh);
        $assessment = $this->passRisk($stale);
        $assessment->forceFill(['screened_at' => $this->now->subHours(12)])->save();

        $rows = collect($this->getJson('/api/memecoins/top-volume')->assertOk()->json('data'))
            ->firstWhere('chain_bucket', 'solana')['tokens'];

        $this->assertFalse(collect($rows)->firstWhere('symbol', 'FRESH')['risk_check_stale']);
        $this->assertTrue(collect($rows)->firstWhere('symbol', 'STALE')['risk_check_stale']);
    }

    #[Test]
    public function the_endpoint_makes_no_provider_calls_and_no_writes(): void
    {
        $this->token('solana', 'ReadOnly', ['volume_h24' => 100_000.0]);

        $before = DB::table('tokens')->count() + DB::table('market_snapshots')->count();
        $this->getJson('/api/memecoins/top-volume')->assertOk();
        $after = DB::table('tokens')->count() + DB::table('market_snapshots')->count();

        $this->assertSame($before, $after);
    }
}
