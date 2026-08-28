<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemecoinListTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-28T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);

        // The read endpoint must never touch the network.
        Http::preventStrayRequests();

        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.limits.default_result_limit', 20);
        config()->set('dexscreener.limits.max_result_limit', 50);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $token
     * @param  array<string,mixed>  $snapshot
     */
    private function makeToken(array $token = [], array $snapshot = []): Token
    {
        /** @var Token $model */
        $model = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'TKN',
            'name' => 'Test Token',
            'earliest_pair_created_at' => $this->now->subDays(10),
            'first_observed_at' => $this->now->subDays(3),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 8_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDay(),
        ], $token));

        $model->marketSnapshots()->create(array_replace([
            'observed_at' => $this->now,
            'price_usd' => 0.01,
            'market_cap' => 2_000_000.0,
            'fdv' => 2_100_000.0,
            'liquidity_usd' => 500_000.0,
            'volume_h24' => 1_000_000.0,
            'price_change_h24' => 1.2,
            'txns_h24' => 100,
            'buys_h24' => 60,
            'sells_h24' => 40,
            'primary_pair_address' => 'pair-abc',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays(10),
        ], $snapshot));

        return $model->refresh();
    }

    #[Test]
    public function it_returns_only_qualified_tokens(): void
    {
        $qualified = $this->makeToken(['symbol' => 'GOOD', 'observed_peak_market_cap' => 9_000_000.0]);
        $this->makeToken(['symbol' => 'LOWPEAK', 'observed_peak_market_cap' => 2_000_000.0]);
        $this->makeToken([
            'symbol' => 'OLD',
            'observed_peak_market_cap' => 50_000_000.0,
            'earliest_pair_created_at' => $this->now->subDays(45),
        ]);

        $res = $this->getJson('/api/memecoins')->assertOk();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.id', $qualified->id);
        $res->assertJsonPath('data.0.symbol', 'GOOD');
        Http::assertNothingSent();
    }

    #[Test]
    public function a_token_with_current_mc_below_five_million_still_qualifies_on_observed_peak(): void
    {
        $this->makeToken(
            ['symbol' => 'FADED', 'observed_peak_market_cap' => 11_800_000.0],
            ['market_cap' => 2_100_000.0],
        );

        $res = $this->getJson('/api/memecoins')->assertOk();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.current_market_cap', fn ($v) => (float) $v === 2_100_000.0);
        $res->assertJsonPath('data.0.observed_peak_market_cap', fn ($v) => (float) $v === 11_800_000.0);
    }

    #[Test]
    public function a_token_with_observed_peak_below_five_million_is_excluded(): void
    {
        $this->makeToken(['observed_peak_market_cap' => 4_999_999.0]);

        $this->getJson('/api/memecoins')->assertOk()->assertJsonPath('meta.count', 0);
    }

    #[Test]
    public function a_token_older_than_thirty_days_is_excluded(): void
    {
        $this->makeToken([
            'observed_peak_market_cap' => 20_000_000.0,
            'earliest_pair_created_at' => $this->now->subDays(31),
        ]);

        $this->getJson('/api/memecoins')->assertOk()->assertJsonPath('meta.count', 0);
    }

    #[Test]
    public function the_latest_snapshot_supplies_the_current_market_fields(): void
    {
        $token = $this->makeToken([], [
            'observed_at' => $this->now->subHours(6),
            'market_cap' => 1_000_000.0,
            'liquidity_usd' => 100_000.0,
            'volume_h24' => 50_000.0,
            'primary_dex_id' => 'old-dex',
        ]);

        $token->marketSnapshots()->create([
            'observed_at' => $this->now->subHour(),
            'price_usd' => 0.02,
            'market_cap' => 3_300_000.0,
            'fdv' => 3_300_000.0,
            'liquidity_usd' => 777_000.0,
            'volume_h24' => 4_300_000.0,
            'price_change_h24' => -2.0,
            'txns_h24' => 200,
            'buys_h24' => 120,
            'sells_h24' => 80,
            'primary_pair_address' => 'pair-new',
            'primary_dex_id' => 'new-dex',
            'earliest_pair_created_at' => $this->now->subDays(10),
        ]);

        $res = $this->getJson('/api/memecoins')->assertOk();

        $res->assertJsonPath('data.0.current_market_cap', fn ($v) => (float) $v === 3_300_000.0);
        $res->assertJsonPath('data.0.liquidity_usd', fn ($v) => (float) $v === 777_000.0);
        $res->assertJsonPath('data.0.volume_h24', fn ($v) => (float) $v === 4_300_000.0);
        $res->assertJsonPath('data.0.primary_dex_id', 'new-dex');
        $res->assertJsonPath('data.0.primary_pair_address', 'pair-new');
    }

    #[Test]
    public function the_chain_filter_restricts_results(): void
    {
        $this->makeToken(['chain_id' => 'solana', 'symbol' => 'SOL1']);
        $this->makeToken(['chain_id' => 'ethereum', 'symbol' => 'ETH1']);

        $res = $this->getJson('/api/memecoins?chain=solana')->assertOk();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.chain_id', 'solana');
    }

    #[Test]
    public function it_sorts_by_observed_peak_market_cap_descending(): void
    {
        $this->makeToken(['symbol' => 'SMALL', 'observed_peak_market_cap' => 6_000_000.0]);
        $this->makeToken(['symbol' => 'BIG', 'observed_peak_market_cap' => 90_000_000.0]);
        $this->makeToken(['symbol' => 'MID', 'observed_peak_market_cap' => 20_000_000.0]);

        $res = $this->getJson('/api/memecoins')->assertOk();

        $this->assertSame(['BIG', 'MID', 'SMALL'], collect($res->json('data'))->pluck('symbol')->all());
    }

    #[Test]
    public function limit_works_and_is_clamped_to_the_server_maximum(): void
    {
        config()->set('dexscreener.limits.max_result_limit', 2);

        foreach (range(1, 4) as $i) {
            $this->makeToken(['symbol' => "T{$i}", 'observed_peak_market_cap' => 5_000_000.0 + $i * 1_000_000]);
        }

        $this->getJson('/api/memecoins?limit=1')->assertOk()->assertJsonPath('meta.count', 1);
        // limit=10 requested, server max is 2
        $this->getJson('/api/memecoins?limit=10')->assertOk()->assertJsonPath('meta.count', 2);
        // invalid
        $this->getJson('/api/memecoins?limit=0')->assertStatus(422);
        $this->getJson('/api/memecoins?chain=not valid!')->assertStatus(422);
    }

    #[Test]
    public function the_read_endpoint_never_calls_dexscreener(): void
    {
        Http::fake();
        $this->makeToken();

        $this->getJson('/api/memecoins')->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function an_empty_database_returns_an_empty_data_array(): void
    {
        $res = $this->getJson('/api/memecoins')->assertOk();

        $res->assertExactJson([
            'data' => [],
            'meta' => [
                'count' => 0,
                'retrieved_at' => $this->now->toIso8601String(),
                'filters' => [
                    'max_age_days' => 30,
                    'observed_peak_market_cap_min_usd' => 5000000,
                ],
            ],
        ]);
    }

    #[Test]
    public function timestamps_are_returned_as_iso_8601(): void
    {
        $token = $this->makeToken([
            'observed_peak_market_cap_at' => $this->now->subDays(2),
            'last_observed_at' => $this->now->subMinutes(5),
        ]);

        $res = $this->getJson('/api/memecoins')->assertOk();

        $res->assertJsonPath('data.0.observed_peak_market_cap_at', $this->now->subDays(2)->toIso8601String());
        $res->assertJsonPath('data.0.last_observed_at', $this->now->subMinutes(5)->toIso8601String());
        $res->assertJsonPath('data.0.age_days', fn ($v) => is_numeric($v) && abs($v - 10.0) < 0.01);
        $res->assertJsonPath('meta.retrieved_at', $this->now->toIso8601String());
        $this->assertSame($token->id, $res->json('data.0.id'));
    }
}
