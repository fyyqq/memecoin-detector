<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemecoinDetailTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-28T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);

        // The detail endpoint must never touch the network — DexScreener,
        // CoinGecko or GeckoTerminal.
        Http::preventStrayRequests();

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $token
     * @param  list<array<string,mixed>>  $snapshots  one entry per snapshot; [] => a single default snapshot
     */
    private function makeToken(array $token = [], array $snapshots = []): Token
    {
        /** @var Token $model */
        $model = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Ci11wAJVj4tMeBo4EJUUKNnejAvHorcktcMSHmSLQdx4',
            'symbol' => 'DOGE',
            'name' => 'Dogecoin',
            'earliest_pair_created_at' => $this->now->subDays(8),
            'first_observed_at' => $this->now->subDays(5),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 74_600_000.0,
            'observed_peak_market_cap_at' => $this->now->subDays(2),
        ], $token));

        $rows = $snapshots === [] ? [[]] : $snapshots;
        $count = count($rows);

        foreach (array_values($rows) as $index => $snapshot) {
            $model->marketSnapshots()->create(array_replace([
                'observed_at' => $this->now->subMinutes(10 * ($count - $index)),
                'price_usd' => 0.12,
                'market_cap' => 2_100_000.0,
                'fdv' => 2_200_000.0,
                'liquidity_usd' => 1_200_000.0,
                'volume_h24' => 300_000.0,
                'price_change_h24' => 4.2,
                'txns_h24' => 512,
                'buys_h24' => 300,
                'sells_h24' => 212,
                'primary_pair_address' => 'pair-abc',
                'primary_dex_id' => 'raydium',
                'earliest_pair_created_at' => $this->now->subDays(8),
            ], $snapshot));
        }

        return $model->refresh();
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function attachEvidence(Token $token, array $attrs = []): HistoricalPeakEvidence
    {
        /** @var HistoricalPeakEvidence $evidence */
        $evidence = HistoricalPeakEvidence::query()->create(array_replace([
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'peak_value_usd' => 11_900_000.0,
            'peak_observed_at' => $this->now->subDays(4),
            'evidence_source' => HistoricalPeakEvidence::SOURCE_COINGECKO,
            'evidence_basis' => HistoricalPeakEvidence::BASIS_MARKET_CAP,
            'source_reference' => 'coingecko:dogecoin',
            'confidence' => 'high',
            'checked_at' => $this->now,
        ], $attrs));

        return $evidence;
    }

    private function detailUrl(Token $token): string
    {
        return "/api/memecoins/{$token->chain_id}/{$token->token_address}";
    }

    #[Test]
    public function the_detail_api_returns_the_token(): void
    {
        $token = $this->makeToken();

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonPath('data.id', $token->id);
        $res->assertJsonPath('data.chain_id', 'solana');
        $res->assertJsonPath('data.symbol', 'DOGE');
        $res->assertJsonPath('data.name', 'Dogecoin');
        $res->assertJsonPath('data.token_address', $token->token_address);
        $res->assertJsonPath('data.provenance.data_source', 'dexscreener');
        $res->assertJsonPath('meta.recent_snapshot_limit', 50);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_token_is_identified_by_chain_and_address_not_symbol(): void
    {
        $this->makeToken(['chain_id' => 'solana', 'token_address' => 'SharedAddr1', 'symbol' => 'SOLONE']);
        $this->makeToken(['chain_id' => 'ethereum', 'token_address' => 'SharedAddr1', 'symbol' => 'ETHONE']);

        $this->getJson('/api/memecoins/solana/SharedAddr1')->assertOk()->assertJsonPath('data.symbol', 'SOLONE');
        $this->getJson('/api/memecoins/ethereum/SharedAddr1')->assertOk()->assertJsonPath('data.symbol', 'ETHONE');
    }

    #[Test]
    public function a_symbol_cannot_be_used_as_the_route_identity(): void
    {
        $this->makeToken(['token_address' => 'RealContractAddr123', 'symbol' => 'DOGE']);

        $this->getJson('/api/memecoins/solana/DOGE')
            ->assertNotFound()
            ->assertExactJson(['error' => 'Memecoin not found.']);
    }

    #[Test]
    public function the_latest_snapshot_supplies_the_current_fields(): void
    {
        $token = $this->makeToken([], [
            ['observed_at' => $this->now->subHours(6), 'market_cap' => 1_000_000.0, 'liquidity_usd' => 111.0, 'price_change_h24' => -3.0, 'primary_dex_id' => 'old-dex'],
            ['observed_at' => $this->now->subHour(), 'market_cap' => 3_300_000.0, 'liquidity_usd' => 777_000.0, 'price_change_h24' => 9.9, 'primary_dex_id' => 'new-dex'],
        ]);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonPath('data.latest.market_cap', fn ($v) => (float) $v === 3_300_000.0);
        $res->assertJsonPath('data.latest.liquidity_usd', fn ($v) => (float) $v === 777_000.0);
        $res->assertJsonPath('data.latest.price_change_h24', fn ($v) => (float) $v === 9.9);
        $res->assertJsonPath('data.latest.primary_dex_id', 'new-dex');
        // history is newest first
        $res->assertJsonPath('data.snapshots.0.market_cap', fn ($v) => (float) $v === 3_300_000.0);
        $res->assertJsonPath('data.snapshots.1.market_cap', fn ($v) => (float) $v === 1_000_000.0);
    }

    #[Test]
    public function recent_snapshots_are_limited_and_newest_first(): void
    {
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = ['observed_at' => $this->now->subMinutes($i + 1), 'market_cap' => 1_000_000.0 + $i];
        }
        $token = $this->makeToken([], $rows);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonCount(50, 'data.snapshots');
        $res->assertJsonPath('data.snapshots.0.observed_at', $this->now->subMinutes(1)->toIso8601String());
        $res->assertJsonPath('data.snapshots.49.observed_at', $this->now->subMinutes(50)->toIso8601String());
        // all 60 rows are still stored — only the response is capped.
        $this->assertSame(60, MarketSnapshot::query()->where('token_id', $token->id)->count());
    }

    #[Test]
    public function historical_qualification_evidence_is_returned(): void
    {
        $token = $this->makeToken(['observed_peak_market_cap' => 2_980_000.0, 'observed_peak_market_cap_at' => $this->now->subDay()]);
        $this->attachEvidence($token, [
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'peak_value_usd' => 11_900_000.0,
            'peak_observed_at' => $this->now->subDays(4),
            'evidence_source' => 'coingecko',
            'evidence_basis' => 'market_cap',
            'confidence' => 'high',
        ]);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonPath('data.qualification.status', 'HISTORICAL_VERIFIED');
        $res->assertJsonPath('data.qualification.peak_value', fn ($v) => (float) $v === 11_900_000.0);
        $res->assertJsonPath('data.qualification.peak_at', $this->now->subDays(4)->toIso8601String());
        $res->assertJsonPath('data.qualification.source', 'coingecko');
        $res->assertJsonPath('data.qualification.basis', 'market_cap');
        $res->assertJsonPath('data.qualification.confidence', 'high');
        $res->assertJsonPath('data.provenance.historical_qualification_source', 'coingecko');
    }

    #[Test]
    public function an_fdv_estimate_is_exposed_separately_and_never_as_a_qualification_market_cap(): void
    {
        $token = $this->makeToken(['observed_peak_market_cap' => 1_000_000.0]);
        $this->attachEvidence($token, [
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'peak_value_usd' => 75_000_000.0,
            'peak_observed_at' => $this->now->subDays(6),
            'evidence_source' => 'geckoterminal',
            'evidence_basis' => 'fdv_total_supply',
            'confidence' => 'medium',
        ]);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        // Not qualified for the main list, and NO market-cap value leaks into qualification.
        $res->assertJsonPath('data.qualification.status', 'HISTORICAL_ESTIMATE');
        $res->assertJsonPath('data.qualification.qualified', false);
        $res->assertJsonPath('data.qualification.peak_value', null);
        $res->assertJsonPath('data.qualification.basis', null);
        $res->assertJsonPath('data.qualification.source', null);

        // The estimate is exposed under an explicitly-named, separate block.
        $res->assertJsonPath('data.historical_estimate.estimated_fdv_usd', fn ($v) => (float) $v === 75_000_000.0);
        $res->assertJsonPath('data.historical_estimate.estimate_source', 'geckoterminal');
        $res->assertJsonPath('data.historical_estimate.estimate_basis', 'fdv_total_supply');
        $res->assertJsonPath('data.historical_estimate.estimate_confidence', 'medium');
        $res->assertJsonPath('data.historical_estimate.estimate_at', $this->now->subDays(6)->toIso8601String());
        $res->assertJsonPath('data.historical_estimate.note', fn ($v) => is_string($v) && str_contains($v, 'does NOT verify'));

        // There is no key named historical_market_cap anywhere in the payload.
        $this->assertStringNotContainsString('historical_market_cap', $res->getContent() ?: '');

        $res->assertJsonPath('data.provenance.historical_estimate_note', fn ($v) => is_string($v) && str_contains($v, 'FDV basis'));
    }

    #[Test]
    public function a_token_with_only_an_fdv_estimate_reports_qualified_false(): void
    {
        $token = $this->makeToken(['observed_peak_market_cap' => 2_980_000.0]);
        $this->attachEvidence($token, [
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'peak_value_usd' => 11_900_000.0,
            'evidence_source' => 'geckoterminal',
            'evidence_basis' => 'fdv_total_supply',
        ]);

        $this->getJson($this->detailUrl($token))
            ->assertOk()
            ->assertJsonPath('data.qualification.qualified', false)
            ->assertJsonPath('data.qualification.peak_value', null)
            ->assertJsonPath('data.historical_estimate.estimated_fdv_usd', fn ($v) => (float) $v === 11_900_000.0)
            ->assertJsonPath('data.observed.peak_market_cap', fn ($v) => (float) $v === 2_980_000.0);
    }

    #[Test]
    public function current_observation_is_derived_when_there_is_no_evidence_row(): void
    {
        $token = $this->makeToken(['observed_peak_market_cap' => 8_200_000.0, 'observed_peak_market_cap_at' => $this->now->subDays(3)]);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonPath('data.qualification.status', 'CURRENT_OBSERVATION');
        $res->assertJsonPath('data.qualification.peak_value', fn ($v) => (float) $v === 8_200_000.0);
        $res->assertJsonPath('data.qualification.source', 'dexscreener');
        $res->assertJsonPath('data.qualification.basis', 'current_market_cap');
        $res->assertJsonPath('data.qualification.confidence', 'high');
        $res->assertJsonPath('data.provenance.historical_estimate_note', null);
    }

    #[Test]
    public function an_unqualified_token_reports_unknown_not_a_denial(): void
    {
        $token = $this->makeToken(['observed_peak_market_cap' => 40_000.0]);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonPath('data.qualification.status', 'UNKNOWN');
        $res->assertJsonPath('data.qualification.peak_value', null);
        $res->assertJsonPath('data.qualification.source', null);
    }

    #[Test]
    public function qualification_peak_stays_distinct_from_observed_peak(): void
    {
        // Cold-start recovered token: our snapshots only ever saw $2.98M, but
        // CoinGecko verified an $11.9M historical peak.
        $token = $this->makeToken(['observed_peak_market_cap' => 2_980_000.0, 'observed_peak_market_cap_at' => $this->now->subDay()]);
        $this->attachEvidence($token, ['peak_value_usd' => 11_900_000.0]);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonPath('data.observed.peak_market_cap', fn ($v) => (float) $v === 2_980_000.0);
        $res->assertJsonPath('data.qualification.peak_value', fn ($v) => (float) $v === 11_900_000.0);

        // The Token's observed_peak_market_cap column is untouched by the read.
        $fresh = $token->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(2_980_000.0, $fresh->observed_peak_market_cap);
    }

    #[Test]
    public function a_missing_token_returns_a_clean_404(): void
    {
        $this->getJson('/api/memecoins/solana/NoSuchTokenAddress')
            ->assertNotFound()
            ->assertExactJson(['error' => 'Memecoin not found.']);
    }

    #[Test]
    public function the_endpoint_is_read_only(): void
    {
        $token = $this->makeToken([], [[], [], []]);
        $this->attachEvidence($token);
        $tokensBefore = Token::query()->count();
        $snapshotsBefore = MarketSnapshot::query()->count();
        $evidenceBefore = HistoricalPeakEvidence::query()->count();
        $peakBefore = $token->observed_peak_market_cap;
        $lastObservedBefore = $token->last_observed_at;

        $this->getJson($this->detailUrl($token))->assertOk();

        $this->assertSame($tokensBefore, Token::query()->count());
        $this->assertSame($snapshotsBefore, MarketSnapshot::query()->count());
        $this->assertSame($evidenceBefore, HistoricalPeakEvidence::query()->count());

        $fresh = $token->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($peakBefore, $fresh->observed_peak_market_cap);
        $this->assertEquals($lastObservedBefore, $fresh->last_observed_at);
    }

    #[Test]
    public function the_endpoint_never_calls_any_external_provider(): void
    {
        Http::fake();
        $token = $this->makeToken();
        $this->attachEvidence($token);

        $this->getJson($this->detailUrl($token))->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_token_below_five_million_and_older_than_thirty_days_still_resolves(): void
    {
        // Would fail dashboard qualification on both counts — the detail page
        // must still show it.
        $token = $this->makeToken(
            [
                'earliest_pair_created_at' => $this->now->subDays(120),
                'observed_peak_market_cap' => 1_200_000.0,
                'observed_peak_market_cap_at' => $this->now->subDays(90),
            ],
            [['market_cap' => 250_000.0]],
        );

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonPath('data.id', $token->id);
        $res->assertJsonPath('data.latest.market_cap', fn ($v) => (float) $v === 250_000.0);
        $res->assertJsonPath('data.observed.peak_market_cap', fn ($v) => (float) $v === 1_200_000.0);
        $res->assertJsonPath('data.age_days', fn ($v) => is_numeric($v) && $v > 30);
        $res->assertJsonPath('data.qualification.status', 'UNKNOWN');
    }

    #[Test]
    public function the_primary_pair_address_is_returned_for_the_live_chart_embed(): void
    {
        // Step 17: the React detail page builds the DexScreener chart iframe from
        // data.chain_id + data.latest.primary_pair_address (never token_address).
        $token = $this->makeToken(['chain_id' => 'base'], [[
            'primary_pair_address' => '0xe58d922ebb81a43259577144bf16dce5e76e82999901f893184e917eb0a30e31',
            'primary_dex_id' => 'uniswap',
        ]]);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        $res->assertJsonPath('data.chain_id', 'base');
        $res->assertJsonPath(
            'data.latest.primary_pair_address',
            '0xe58d922ebb81a43259577144bf16dce5e76e82999901f893184e917eb0a30e31',
        );
        $res->assertJsonPath('data.latest.primary_dex_id', 'uniswap');
        // The pair address must never equal the token contract address.
        $this->assertNotSame($token->token_address, $res->json('data.latest.primary_pair_address'));
    }

    #[Test]
    public function a_null_primary_pair_address_is_returned_as_null_for_the_chart_fallback(): void
    {
        $token = $this->makeToken([], [['primary_pair_address' => null, 'primary_dex_id' => null]]);

        $this->getJson($this->detailUrl($token))
            ->assertOk()
            ->assertJsonPath('data.latest.primary_pair_address', null)
            ->assertJsonPath('data.latest.primary_dex_id', null);
    }

    #[Test]
    public function null_fields_remain_null_and_are_never_coerced_to_zero(): void
    {
        $token = $this->makeToken([], [[
            'market_cap' => null,
            'price_usd' => null,
            'fdv' => null,
            'liquidity_usd' => null,
            'volume_h24' => null,
            'price_change_h24' => null,
            'txns_h24' => null,
            'buys_h24' => null,
            'sells_h24' => null,
        ]]);

        $res = $this->getJson($this->detailUrl($token))->assertOk();

        foreach (['market_cap', 'price_usd', 'fdv', 'liquidity_usd', 'volume_h24', 'price_change_h24', 'txns_h24', 'buys_h24', 'sells_h24'] as $field) {
            $res->assertJsonPath("data.latest.{$field}", null);
        }
        $res->assertJsonPath('data.pair.pair_count', null);
        $res->assertJsonPath('data.snapshots.0.market_cap', null);
        $res->assertJsonPath('data.snapshots.0.txns_h24', null);
    }

    #[Test]
    public function the_detail_query_does_not_run_per_snapshot_queries(): void
    {
        $token = $this->makeToken([], array_fill(0, 12, []));
        $this->attachEvidence($token);

        DB::enableQueryLog();
        $this->getJson($this->detailUrl($token))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // token + historical-evidence + qualification-events + narrative-report
        // (+ its sources) + risk-assessment (+ its signals) + recent-snapshot
        // window + pump events + explanation + evidence eager loads; small
        // headroom for the framework, but never one-per-row.
        $this->assertLessThanOrEqual(13, count($queries));
    }
}
