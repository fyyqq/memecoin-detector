<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HistoricalPeakEvidence;
use App\Models\QualificationEvent;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Step 20 — GET /api/memecoins/recently-crossed.
 *
 * Read-only. PostgreSQL only — never DexScreener / CoinGecko / GeckoTerminal.
 */
class RecentlyCrossedTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-31T12:00:00Z');
        CarbonImmutable::setTestNow($this->now);
        Http::preventStrayRequests();

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        // Dashboard-simplification pass: the qualification ceiling moved from
        // $200M to $1B (inclusive). Mirror the production default here.
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 1_000_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('dexscreener.recent_crossing.hours', 48);
        config()->set('dexscreener.recent_crossing.max_hours', 168);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param  array<string,mixed>  $attrs
     * @param  array<string,mixed>  $snapshot
     */
    private function token(array $attrs = [], array $snapshot = []): Token
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
            'observed_peak_market_cap' => 8_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDays(1),
        ], $attrs));

        $token->marketSnapshots()->create(array_replace([
            'observed_at' => $this->now,
            'price_usd' => 0.01,
            'market_cap' => 6_500_000.0,
            'fdv' => 6_500_000.0,
            'liquidity_usd' => 250_000.0,
            'volume_h24' => 500_000.0,
            'price_change_h24' => 2.0,
            'txns_h24' => 20,
            'buys_h24' => 12,
            'sells_h24' => 8,
            'primary_pair_address' => 'pair-abc',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays(10),
        ], $snapshot));

        return $token->refresh();
    }

    private function crossing(Token $token, string $type, CarbonImmutable $crossedAt, float $value = 6_000_000.0): QualificationEvent
    {
        /** @var QualificationEvent $event */
        $event = QualificationEvent::query()->create([
            'token_id' => $token->id,
            'type' => $type,
            'crossed_at' => $crossedAt,
            'threshold_usd' => 5_000_000,
            'evidence_status' => $type,
            'source' => $type === QualificationEvent::TYPE_HISTORICAL_VERIFIED ? 'coingecko' : 'dexscreener',
            'market_cap_value' => $value,
        ]);

        return $event;
    }

    #[Test]
    public function it_returns_tokens_that_crossed_within_the_default_window(): void
    {
        $recent = $this->token(['symbol' => 'RECENT']);
        $this->crossing($recent, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(12));

        $old = $this->token(['symbol' => 'OLD']);
        $this->crossing($old, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(6));

        $res = $this->getJson('/api/memecoins/recently-crossed')->assertOk();

        $res->assertJsonPath('meta.hours', 48);
        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.symbol', 'RECENT');
        $res->assertJsonPath('data.0.crossing_type', 'CURRENT_OBSERVATION');
    }

    #[Test]
    public function the_endpoint_is_read_only_and_makes_no_provider_calls(): void
    {
        Http::fake();
        $token = $this->token();
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(3));

        DB::enableQueryLog();
        $this->getJson('/api/memecoins/recently-crossed')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        Http::assertNothingSent();
        $this->assertLessThanOrEqual(6, count($queries));
        $this->assertDatabaseCount('qualification_events', 1);
    }

    #[Test]
    public function the_hours_parameter_widens_the_window(): void
    {
        $token = $this->token(['symbol' => 'FIVEDAYS']);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subDays(5));

        $this->getJson('/api/memecoins/recently-crossed')->assertOk()->assertJsonPath('meta.count', 0);
        $this->getJson('/api/memecoins/recently-crossed?hours=168')
            ->assertOk()
            ->assertJsonPath('meta.hours', 168)
            ->assertJsonPath('meta.count', 1);
    }

    #[Test]
    public function the_hours_parameter_is_capped_at_the_safe_maximum(): void
    {
        $this->getJson('/api/memecoins/recently-crossed?hours=169')->assertStatus(422);
        $this->getJson('/api/memecoins/recently-crossed?hours=0')->assertStatus(422);
        $this->getJson('/api/memecoins/recently-crossed?hours=168')->assertOk();
    }

    #[Test]
    public function a_token_below_five_million_now_still_appears_if_it_recently_crossed(): void
    {
        $token = $this->token(
            ['symbol' => 'DUMPED', 'observed_peak_market_cap' => 9_000_000.0],
            ['market_cap' => 1_800_000.0],
        );
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(10), 9_000_000.0);

        $res = $this->getJson('/api/memecoins/recently-crossed')->assertOk();

        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.symbol', 'DUMPED');
        $res->assertJsonPath('data.0.status', 'COOLED');
        $res->assertJsonPath('data.0.current_market_cap', fn ($v) => (float) $v === 1_800_000.0);
    }

    #[Test]
    public function a_token_at_or_above_five_million_now_shows_as_active(): void
    {
        $token = $this->token(['symbol' => 'HOT'], ['market_cap' => 6_800_000.0]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(2), 6_800_000.0);

        $this->getJson('/api/memecoins/recently-crossed')->assertOk()
            ->assertJsonPath('data.0.symbol', 'HOT')
            ->assertJsonPath('data.0.status', 'ACTIVE');
    }

    #[Test]
    public function an_estimate_only_token_never_appears(): void
    {
        // A HISTORICAL_ESTIMATE token has no QualificationEvent at all.
        $token = $this->token([
            'symbol' => 'ESTONLY',
            'observed_peak_market_cap' => 2_000_000.0,
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'historical_estimate_fdv_usd' => 40_000_000.0,
        ]);
        HistoricalPeakEvidence::query()->create([
            'token_id' => $token->id,
            'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
            'peak_value_usd' => 40_000_000.0,
            'evidence_source' => 'geckoterminal',
            'evidence_basis' => 'fdv_total_supply',
            'checked_at' => $this->now,
        ]);

        $this->getJson('/api/memecoins/recently-crossed')->assertOk()->assertJsonPath('meta.count', 0);
    }

    #[Test]
    public function an_age_over_thirty_days_token_is_excluded_even_with_a_recent_crossing(): void
    {
        $token = $this->token([
            'symbol' => 'AGED',
            'earliest_pair_created_at' => $this->now->subDays(45),
        ]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));

        $this->getJson('/api/memecoins/recently-crossed')->assertOk()->assertJsonPath('meta.count', 0);

        // The historical record is kept.
        $this->assertDatabaseCount('qualification_events', 1);
    }

    #[Test]
    public function the_representative_crossing_drives_window_membership(): void
    {
        // CURRENT_OBSERVATION crossing 6h ago, but CoinGecko later verified the
        // true crossing was 10 days ago. Representative = verified -> excluded.
        $token = $this->token(['symbol' => 'VERIFIEDOLD', 'observed_peak_market_cap' => 3_000_000.0]);
        $token->update([
            'historical_peak_status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
            'historical_peak_value' => 15_000_000.0,
            'historical_peak_value_at' => $this->now->subDays(9),
        ]);
        $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6));
        $this->crossing($token, QualificationEvent::TYPE_HISTORICAL_VERIFIED, $this->now->subDays(6));

        // Representative crossing is the verified one (6 days ago) → outside the
        // 48h window, so the token does NOT appear even though its
        // CURRENT_OBSERVATION crossing is only 6h old.
        $this->getJson('/api/memecoins/recently-crossed')->assertOk()->assertJsonPath('meta.count', 0);

        // Widen past the verified crossing → it appears, tagged as verified.
        $res = $this->getJson('/api/memecoins/recently-crossed?hours=168')->assertOk();
        $res->assertJsonPath('meta.count', 1);
        $res->assertJsonPath('data.0.crossing_type', 'HISTORICAL_VERIFIED');
    }

    #[Test]
    public function results_are_sorted_newest_crossing_first(): void
    {
        $a = $this->token(['symbol' => 'A']);
        $this->crossing($a, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(30));
        $b = $this->token(['symbol' => 'B']);
        $this->crossing($b, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(2));
        $c = $this->token(['symbol' => 'C']);
        $this->crossing($c, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(12));

        $res = $this->getJson('/api/memecoins/recently-crossed')->assertOk();

        $this->assertSame(['B', 'C', 'A'], collect($res->json('data'))->pluck('symbol')->all());
    }

    /**
     * The qualification ceiling was raised from $200M to $1B (inclusive) in the
     * dashboard-simplification pass. The $5M floor is unchanged.
     */
    #[Test]
    public function the_market_cap_qualification_band_is_five_million_to_one_billion(): void
    {
        $cases = [
            ['SUB5M', 4_990_000.0, false],
            ['AT5M', 5_000_000.0, true],
            ['AT200M', 200_000_000.0, true],
            ['AT500M', 500_000_000.0, true],
            ['AT999M', 999_000_000.0, true],
            ['AT1B', 1_000_000_000.0, true],   // inclusive ceiling
            ['OVER1B', 1_250_000_000.0, false],
        ];

        foreach ($cases as [$symbol, $peak, $_]) {
            $token = $this->token([
                'symbol' => $symbol,
                'observed_peak_market_cap' => $peak,
                'observed_peak_market_cap_at' => $this->now->subDays(1),
            ], ['market_cap' => min($peak, 6_500_000.0)]);
            $this->crossing($token, QualificationEvent::TYPE_CURRENT_OBSERVATION, $this->now->subHours(6), $peak);
        }

        $returned = collect($this->getJson('/api/memecoins/recently-crossed')->assertOk()->json('data'))
            ->pluck('symbol')
            ->all();

        foreach ($cases as [$symbol, $_, $shouldQualify]) {
            $shouldQualify
                ? $this->assertContains($symbol, $returned, "$symbol should qualify")
                : $this->assertNotContains($symbol, $returned, "$symbol should NOT qualify");
        }
    }
}
