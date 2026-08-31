<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\IngestionRun;
use App\Models\QualificationEvent;
use App\Models\Token;
use App\Services\DexScreener\DexScreenerDiscoveryService;
use App\Services\Historical\QualificationEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 20 — "$5M crossing" (QualificationEvent) detection.
 *
 * The recorder runs inside the ingestion / qualification pipeline. A read API
 * never creates an event and never scans snapshots.
 */
class QualificationEventTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private float $floor = 5_000_000.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-31T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 200_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('dexscreener.recent_crossing.hours', 48);
        config()->set('dexscreener.recent_crossing.max_hours', 168);
        config()->set('historical.coingecko.enabled', false);
        config()->set('historical.geckoterminal.enabled', false);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    private function recorder(): QualificationEventRecorder
    {
        return app(QualificationEventRecorder::class);
    }

    /**
     * @param  array<string,mixed>  $attrs
     * @param  list<array{observed_at:CarbonImmutable,market_cap:?float}>  $snapshots
     */
    private function token(array $attrs = [], array $snapshots = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'TKN',
            'name' => 'Test Token',
            'earliest_pair_created_at' => $this->now->subDays(10),
            'first_observed_at' => $this->now->subDays(5),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => null,
            'observed_peak_market_cap_at' => null,
        ], $attrs));

        foreach ($snapshots as $row) {
            $token->marketSnapshots()->create([
                'observed_at' => $row['observed_at'],
                'price_usd' => 0.01,
                'market_cap' => $row['market_cap'],
                'fdv' => $row['market_cap'],
                'liquidity_usd' => 200_000.0,
                'volume_h24' => 100_000.0,
                'price_change_h24' => 1.0,
                'txns_h24' => 10,
                'buys_h24' => 6,
                'sells_h24' => 4,
                'primary_pair_address' => 'pair-abc',
                'primary_dex_id' => 'raydium',
                'earliest_pair_created_at' => $this->now->subDays(10),
            ]);
        }

        return $token->refresh();
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function evidence(Token $token, string $status, array $attrs = []): HistoricalPeakEvidence
    {
        /** @var HistoricalPeakEvidence $evidence */
        $evidence = HistoricalPeakEvidence::query()->create(array_replace([
            'token_id' => $token->id,
            'status' => $status,
            'peak_value_usd' => 8_000_000.0,
            'peak_observed_at' => $this->now->subDays(3),
            'evidence_source' => $status === HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED ? 'coingecko' : 'dexscreener',
            'evidence_basis' => $status === HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED ? 'market_cap' : 'current_market_cap',
            'checked_at' => $this->now,
        ], $attrs));

        return $evidence;
    }

    private function record(Token $token, ?HistoricalPeakEvidence $evidence): array
    {
        return $this->recorder()->recordBatch(
            [['token' => $token->refresh(), 'evidence' => $evidence?->refresh()]],
            $this->now,
            $this->floor,
            200_000_000.0,
        );
    }

    // ---- crossing logic ----------------------------------------------------

    #[Test]
    public function a_first_current_observation_at_or_above_five_million_creates_a_crossing_event(): void
    {
        $token = $this->token(
            ['observed_peak_market_cap' => 6_200_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(3)],
            [['observed_at' => $this->now->subHours(3), 'market_cap' => 6_200_000.0]],
        );
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, [
            'peak_value_usd' => 6_200_000.0,
        ]);

        $stats = $this->record($token, $evidence);

        $this->assertSame(1, $stats['qualification_events_created']);
        $event = QualificationEvent::query()->where('token_id', $token->id)->sole();
        $this->assertSame(QualificationEvent::TYPE_CURRENT_OBSERVATION, $event->type);
        $this->assertSame('dexscreener', $event->source);
        $this->assertTrue($event->crossed_at->equalTo($this->now->subHours(3)));
        $this->assertSame(6_200_000.0, $event->market_cap_value);
        $this->assertSame(5_000_000, $event->threshold_usd);
    }

    #[Test]
    public function the_crossing_uses_the_earliest_snapshot_at_or_above_the_threshold(): void
    {
        $token = $this->token(
            ['observed_peak_market_cap' => 9_000_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(2)],
            [
                ['observed_at' => $this->now->subHours(6), 'market_cap' => 3_000_000.0], // below
                ['observed_at' => $this->now->subHours(4), 'market_cap' => 5_400_000.0], // first crossing
                ['observed_at' => $this->now->subHours(2), 'market_cap' => 9_000_000.0], // later peak
            ],
        );
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, [
            'peak_value_usd' => 9_000_000.0,
        ]);

        $this->record($token, $evidence);

        $event = QualificationEvent::query()->where('token_id', $token->id)->sole();
        $this->assertTrue($event->crossed_at->equalTo($this->now->subHours(4)));
        $this->assertSame(5_400_000.0, $event->market_cap_value);
    }

    #[Test]
    public function a_repeated_at_or_above_threshold_run_does_not_duplicate_the_event(): void
    {
        $token = $this->token(
            ['observed_peak_market_cap' => 7_000_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(5)],
            [['observed_at' => $this->now->subHours(5), 'market_cap' => 7_000_000.0]],
        );
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, ['peak_value_usd' => 7_000_000.0]);

        $first = $this->record($token, $evidence);
        $second = $this->record($token, $evidence);
        $third = $this->record($token, $evidence);

        $this->assertSame(1, $first['qualification_events_created']);
        $this->assertSame(0, $second['qualification_events_created']);
        $this->assertSame(1, $second['qualification_events_existing']);
        $this->assertSame(0, $third['qualification_events_created']);
        $this->assertDatabaseCount('qualification_events', 1);
    }

    #[Test]
    public function the_crossing_remains_after_the_current_market_cap_falls_below_five_million(): void
    {
        $token = $this->token(
            ['observed_peak_market_cap' => 11_000_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(20)],
            [
                ['observed_at' => $this->now->subHours(20), 'market_cap' => 11_000_000.0],
                ['observed_at' => $this->now->subHours(1), 'market_cap' => 1_800_000.0], // dumped
            ],
        );
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, ['peak_value_usd' => 11_000_000.0]);

        $this->record($token, $evidence);
        // A later run with the token now well below $5M.
        $this->record($token, $evidence);

        $event = QualificationEvent::query()->where('token_id', $token->id)->sole();
        $this->assertTrue($event->crossed_at->equalTo($this->now->subHours(20)));
        $this->assertDatabaseCount('qualification_events', 1);
    }

    #[Test]
    public function a_historical_verified_evidence_creates_a_verified_crossing_event(): void
    {
        $token = $this->token(
            ['observed_peak_market_cap' => 900_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(1)],
            [['observed_at' => $this->now->subHours(1), 'market_cap' => 900_000.0]],
        );
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED, [
            'peak_value_usd' => 12_000_000.0,
            'peak_observed_at' => $this->now->subDays(6),
            'first_verified_crossing_at' => $this->now->subDays(8),
        ]);
        $token->update([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 12_000_000.0,
            'historical_peak_value_at' => $this->now->subDays(6),
        ]);

        $stats = $this->record($token, $evidence);

        $this->assertSame(1, $stats['qualification_events_created']);
        $event = QualificationEvent::query()->where('token_id', $token->id)->sole();
        $this->assertSame(QualificationEvent::TYPE_HISTORICAL_VERIFIED, $event->type);
        $this->assertSame('coingecko', $event->source);
        // Earliest verified >= $5M point, NOT the peak timestamp.
        $this->assertTrue($event->crossed_at->equalTo($this->now->subDays(8)));
    }

    #[Test]
    public function a_verified_crossing_falls_back_to_the_peak_timestamp_when_no_first_crossing_is_stored(): void
    {
        $token = $this->token([], []);
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED, [
            'peak_value_usd' => 9_000_000.0,
            'peak_observed_at' => $this->now->subDays(4),
            'first_verified_crossing_at' => null,
        ]);

        $this->record($token, $evidence);

        $event = QualificationEvent::query()->where('token_id', $token->id)->sole();
        $this->assertTrue($event->crossed_at->equalTo($this->now->subDays(4)));
    }

    #[Test]
    public function a_historical_estimate_does_not_create_a_verified_crossing(): void
    {
        $token = $this->token(['observed_peak_market_cap' => 2_000_000.0], [
            ['observed_at' => $this->now->subHours(2), 'market_cap' => 2_000_000.0],
        ]);
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE, [
            'peak_value_usd' => 40_000_000.0,
            'evidence_source' => 'geckoterminal',
            'evidence_basis' => 'fdv_total_supply',
        ]);

        $stats = $this->record($token, $evidence);

        $this->assertSame(0, $stats['qualification_events_created']);
        $this->assertDatabaseCount('qualification_events', 0);
    }

    #[Test]
    public function an_unknown_evidence_does_not_create_a_crossing(): void
    {
        $token = $this->token(['observed_peak_market_cap' => 1_000_000.0], [
            ['observed_at' => $this->now->subHours(2), 'market_cap' => 1_000_000.0],
        ]);
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_UNKNOWN, ['peak_value_usd' => null]);

        $stats = $this->record($token, $evidence);

        $this->assertSame(0, $stats['qualification_events_created']);
        $this->assertDatabaseCount('qualification_events', 0);
    }

    #[Test]
    public function a_verified_observed_peak_above_the_two_hundred_million_ceiling_creates_no_crossing(): void
    {
        $token = $this->token(
            ['observed_peak_market_cap' => 640_000_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(2)],
            [['observed_at' => $this->now->subHours(2), 'market_cap' => 640_000_000.0]],
        );
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, [
            'peak_value_usd' => 640_000_000.0,
        ]);

        $stats = $this->record($token, $evidence);

        $this->assertSame(0, $stats['qualification_events_created']);
        $this->assertDatabaseCount('qualification_events', 0);
    }

    #[Test]
    public function no_evidence_row_creates_no_crossing(): void
    {
        $token = $this->token(['observed_peak_market_cap' => 8_000_000.0], [
            ['observed_at' => $this->now->subHours(2), 'market_cap' => 8_000_000.0],
        ]);

        $stats = $this->record($token, null);

        $this->assertSame(0, $stats['qualification_events_created']);
        $this->assertDatabaseCount('qualification_events', 0);
    }

    #[Test]
    public function historical_verified_and_current_observation_events_can_coexist_and_verified_is_representative(): void
    {
        $token = $this->token(
            ['observed_peak_market_cap' => 6_000_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(4)],
            [['observed_at' => $this->now->subHours(4), 'market_cap' => 6_000_000.0]],
        );

        // Run 1 — CURRENT_OBSERVATION.
        $co = $this->evidence($token, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, ['peak_value_usd' => 6_000_000.0]);
        $this->record($token, $co);

        // Run 2 — CoinGecko later verifies a much earlier crossing. The evidence
        // row flips to HISTORICAL_VERIFIED.
        $co->update([
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'peak_value_usd' => 15_000_000.0,
            'peak_observed_at' => $this->now->subDays(9),
            'first_verified_crossing_at' => $this->now->subDays(12),
            'evidence_source' => 'coingecko',
            'evidence_basis' => 'market_cap',
        ]);
        $this->record($token, $co);

        $this->assertDatabaseCount('qualification_events', 2);
        $representative = $token->refresh()->load('qualificationEvents')->representativeQualificationEvent();
        $this->assertSame(QualificationEvent::TYPE_HISTORICAL_VERIFIED, $representative->type);
        $this->assertTrue($representative->crossed_at->equalTo($this->now->subDays(12)));
        // The original CURRENT_OBSERVATION record is preserved untouched.
        $original = QualificationEvent::query()
            ->where('token_id', $token->id)
            ->where('type', QualificationEvent::TYPE_CURRENT_OBSERVATION)
            ->sole();
        $this->assertTrue($original->crossed_at->equalTo($this->now->subHours(4)));
    }

    #[Test]
    public function token_identity_is_chain_plus_address(): void
    {
        $a = $this->token(
            ['chain_id' => 'solana', 'token_address' => 'SameSymbolAddr1', 'observed_peak_market_cap' => 6_000_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(2)],
            [['observed_at' => $this->now->subHours(2), 'market_cap' => 6_000_000.0]],
        );
        $b = $this->token(
            ['chain_id' => 'base', 'token_address' => 'SameSymbolAddr1', 'observed_peak_market_cap' => 7_000_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(2)],
            [['observed_at' => $this->now->subHours(2), 'market_cap' => 7_000_000.0]],
        );

        $this->record($a, $this->evidence($a, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, ['peak_value_usd' => 6_000_000.0]));
        $this->record($b, $this->evidence($b, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, ['peak_value_usd' => 7_000_000.0]));

        $this->assertDatabaseCount('qualification_events', 2);
        $this->assertSame(1, QualificationEvent::query()->where('token_id', $a->id)->count());
        $this->assertSame(1, QualificationEvent::query()->where('token_id', $b->id)->count());
    }

    // ---- pipeline integration -------------------------------------------

    #[Test]
    public function the_discovery_pipeline_records_a_current_observation_crossing(): void
    {
        config()->set('dexscreener.base_url', 'https://api.dexscreener.com');
        config()->set('dexscreener.discovery_sources.trending_meta_enabled', false);
        config()->set('dexscreener.discovery_sources.profiles_enabled', true);
        config()->set('dexscreener.discovery_sources.boosts_enabled', false);
        config()->set('dexscreener.discovery_sources.keyword_enabled', false);

        $addr = 'PipelineCross1111111111111111111111111111111';
        $pair = [
            'chainId' => 'solana',
            'dexId' => 'raydium',
            'pairAddress' => 'PAIR-pipeline-cross',
            'baseToken' => ['address' => $addr, 'name' => 'Crosser', 'symbol' => 'CROSS'],
            'quoteToken' => ['address' => 'Q', 'symbol' => 'SOL'],
            'priceUsd' => '0.02',
            'liquidity' => ['usd' => 300_000.0],
            'volume' => ['h24' => 250_000.0],
            'priceChange' => ['h24' => 12.0],
            'txns' => ['h24' => ['buys' => 40, 'sells' => 12]],
            'marketCap' => 7_500_000.0,
            'fdv' => 7_500_000.0,
            'pairCreatedAt' => $this->now->subDays(6)->getTimestampMs(),
        ];

        Http::fake([
            'api.dexscreener.com/token-profiles/latest/v1' => fn () => Http::response([
                ['chainId' => 'solana', 'tokenAddress' => $addr],
            ]),
            'api.dexscreener.com/token-boosts/latest/v1' => fn () => Http::response([]),
            'api.dexscreener.com/token-boosts/top/v1' => fn () => Http::response([]),
            'api.dexscreener.com/metas/trending/v1' => fn () => Http::response([]),
            'api.dexscreener.com/token-pairs/v1/*' => fn () => Http::response([$pair]),
        ]);

        $result = app(DexScreenerDiscoveryService::class)
            ->discover(null, 20, IngestionRun::TRIGGER_MANUAL);

        $this->assertSame(1, $result->diagnostics['qualified']);
        $this->assertSame(1, $result->diagnostics['qualification_events_created']);

        $token = Token::query()->where('token_address', $addr)->sole();
        $event = QualificationEvent::query()->where('token_id', $token->id)->sole();
        $this->assertSame(QualificationEvent::TYPE_CURRENT_OBSERVATION, $event->type);
        $this->assertSame(7_500_000.0, $event->market_cap_value);

        // A second identical run creates nothing new.
        $second = app(DexScreenerDiscoveryService::class)
            ->discover(null, 20, IngestionRun::TRIGGER_SCHEDULED);
        $this->assertSame(0, $second->diagnostics['qualification_events_created']);
        $this->assertDatabaseCount('qualification_events', 1);
    }

    // ---- detail page ---------------------------------------------------

    #[Test]
    public function the_detail_endpoint_exposes_the_qualification_timeline(): void
    {
        $token = $this->token(
            ['observed_peak_market_cap' => 9_000_000.0, 'observed_peak_market_cap_at' => $this->now->subHours(30)],
            [
                ['observed_at' => $this->now->subHours(30), 'market_cap' => 9_000_000.0],
                ['observed_at' => $this->now->subHours(1), 'market_cap' => 2_000_000.0],
            ],
        );
        $evidence = $this->evidence($token, HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION, ['peak_value_usd' => 9_000_000.0]);
        $this->record($token, $evidence);

        $res = $this->getJson("/api/memecoins/{$token->chain_id}/{$token->token_address}")->assertOk();

        $res->assertJsonPath('data.qualification_timeline.crossing_type', 'CURRENT_OBSERVATION');
        $res->assertJsonPath('data.qualification_timeline.crossed_at', $this->now->subHours(30)->toIso8601String());
        $res->assertJsonPath('data.qualification_timeline.recently_crossed', true);
        $res->assertJsonPath('data.qualification_timeline.currently_below_threshold', true);
        $res->assertJsonPath('data.qualification_timeline.threshold_usd', 5000000);
        $res->assertJsonCount(1, 'data.qualification_timeline.events');
        Http::assertNothingSent();
    }
}
