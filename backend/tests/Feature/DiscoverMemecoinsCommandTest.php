<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IngestionRun;
use App\Models\MarketSnapshot;
use App\Models\Token;
use App\Services\DexScreener\DexScreenerDiscoveryService;
use App\Services\DexScreener\DexScreenerNormalizer;
use App\Services\DexScreener\DiscoveryResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\FakesDexScreener;
use Tests\TestCase;

class DiscoverMemecoinsCommandTest extends TestCase
{
    use FakesDexScreener;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-28T12:00:00Z'));
        Http::preventStrayRequests();
        $this->bootDexScreenerFakeConfig();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /** @return array<string,int> */
    private function fakeDiagnostics(): array
    {
        return array_fill_keys([
            'raw_discovery_candidates', 'unique_candidates', 'candidates_after_chain_filter',
            'enrichment_attempted', 'enriched_ok', 'enrichment_failed', 'age_unknown',
            'older_than_max_age', 'age_eligible', 'market_cap_unknown', 'snapshots_written',
            'persist_failed', 'new_tokens', 'existing_tokens', 'peak_updated', 'qualified',
            'qualified_from_current_observation', 'not_qualified', 'observed_peak_below_threshold',
            'returned',
        ], 0);
    }

    #[Test]
    public function it_invokes_the_discovery_service_as_scheduled_and_exits_zero(): void
    {
        $this->mock(DexScreenerDiscoveryService::class)
            ->shouldReceive('discover')
            ->once()
            ->withArgs(fn ($chain, $limit, $trigger) => $chain === null && $limit === null && $trigger === 'scheduled')
            ->andReturn(new DiscoveryResult([], $this->fakeDiagnostics(), [], 42));

        $this->artisan('memecoins:discover')
            ->expectsOutputToContain('Memecoin discovery completed.')
            ->expectsOutputToContain('#42')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_exits_non_zero_when_the_discovery_service_throws(): void
    {
        $this->mock(DexScreenerDiscoveryService::class)
            ->shouldReceive('discover')
            ->once()
            ->andThrow(new RuntimeException('kaboom'));

        $this->artisan('memecoins:discover')
            ->expectsOutputToContain('Memecoin discovery failed: kaboom')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_trigger_option_overrides_the_recorded_trigger(): void
    {
        $spy = $this->mock(DexScreenerDiscoveryService::class);
        $spy->shouldReceive('discover')
            ->once()
            ->withArgs(fn ($chain, $limit, $trigger) => $trigger === 'manual')
            ->andReturn(new DiscoveryResult([], $this->fakeDiagnostics(), [], 1));

        $this->artisan('memecoins:discover --trigger=manual')->assertExitCode(0);
    }

    #[Test]
    public function it_runs_the_real_pipeline_and_records_a_completed_scheduled_run(): void
    {
        $addr = 'cmdaaaa00000000000000000000000000000000000000';
        $this->fakeDexScreener(["solana:{$addr}" => [$this->dexPair('solana', $addr, ['marketCap' => 6_000_000.0])]]);

        $this->artisan('memecoins:discover')
            ->expectsOutputToContain('Memecoin discovery completed.')
            ->assertExitCode(0);

        $run = IngestionRun::query()->latest('id')->firstOrFail();
        $this->assertSame('scheduled', $run->trigger);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(1, $run->snapshots_written);
        $this->assertSame(1, $run->new_tokens);
        $this->assertSame(1, $run->qualified);

        $this->assertDatabaseCount('tokens', 1);
        $this->assertDatabaseCount('market_snapshots', 1);
    }

    #[Test]
    public function an_unexpected_pipeline_error_marks_the_run_failed_and_exits_non_zero(): void
    {
        $addr = 'boomcmd00000000000000000000000000000000000000';
        $this->fakeDexScreener(["solana:{$addr}" => [$this->dexPair('solana', $addr)]]);

        // normalize() is not wrapped in the per-candidate try/catch, so a throw
        // here propagates as an unexpected pipeline failure.
        $this->mock(DexScreenerNormalizer::class)
            ->shouldReceive('normalize')
            ->andThrow(new RuntimeException('normalize exploded'));

        $this->artisan('memecoins:discover')->assertExitCode(1);

        $run = IngestionRun::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('scheduled', $run->trigger);
        $this->assertNotNull($run->completed_at);
        $this->assertStringContainsString('normalize exploded', (string) $run->error_message);
    }

    #[Test]
    public function repeated_execution_creates_separate_runs_without_duplicating_tokens(): void
    {
        $addr = 'repeataaa0000000000000000000000000000000000000';
        $this->fakeDexScreener(["solana:{$addr}" => [$this->dexPair('solana', $addr, ['marketCap' => 6_000_000.0])]]);
        $this->artisan('memecoins:discover')->assertExitCode(0);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));
        $this->fakeDexScreener(["solana:{$addr}" => [$this->dexPair('solana', $addr, ['marketCap' => 6_400_000.0])]]);
        $this->artisan('memecoins:discover')->assertExitCode(0);

        $this->assertDatabaseCount('ingestion_runs', 2);
        $this->assertSame(2, IngestionRun::query()->where('status', 'completed')->count());
        $this->assertDatabaseCount('tokens', 1);
        $this->assertDatabaseCount('market_snapshots', 2);
    }

    #[Test]
    public function running_the_command_twice_keeps_observed_peak_monotonic(): void
    {
        $addr = 'monotone00000000000000000000000000000000000000';

        $this->fakeDexScreener(["solana:{$addr}" => [$this->dexPair('solana', $addr, ['marketCap' => 9_000_000.0])]]);
        $this->artisan('memecoins:discover')->assertExitCode(0);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));
        $this->fakeDexScreener(["solana:{$addr}" => [$this->dexPair('solana', $addr, ['marketCap' => 3_000_000.0])]]);
        $this->artisan('memecoins:discover')->assertExitCode(0);

        $token = Token::query()->firstOrFail();
        $this->assertSame(9_000_000.0, $token->observed_peak_market_cap);
        $this->assertSame(
            3_000_000.0,
            MarketSnapshot::query()->latest('id')->firstOrFail()->market_cap,
        );
        $this->assertDatabaseCount('market_snapshots', 2);
    }
}
