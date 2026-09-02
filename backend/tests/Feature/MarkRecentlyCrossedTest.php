<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\QualificationEvent;
use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesRiskAssessments;
use Tests\TestCase;

/**
 * `memecoins:mark-recently-crossed` — stamps `recently_crossed_qualified_at`
 * once for tokens that currently pass the ENTIRE "🔥 Recently Crossed $5M"
 * predicate, so they continue into "📈 Post-30-Day Memecoins" once they age out.
 *
 * PostgreSQL-only. Writes ONLY that one column. Never clears / rewrites it.
 */
class MarkRecentlyCrossedTest extends TestCase
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

        config()->set('dexscreener.filters.observed_peak_market_cap_min_usd', 5_000_000);
        config()->set('dexscreener.filters.observed_peak_market_cap_max_usd', 1_000_000_000);
        config()->set('dexscreener.filters.max_age_days', 30);
        config()->set('dexscreener.recent_crossing.window_days', 30);
        config()->set('dexscreener.recent_crossing.discovery_freshness_hours', 48);
        config()->set('dexscreener.recent_crossing.min_holders_per_million_mcap', 5.0);
        config()->set('dexscreener.recent_crossing.require_holder_evidence', true);
        config()->set('dexscreener.recent_crossing.min_volume_to_mcap_ratio', 0.001);
        config()->set('dexscreener.recent_crossing.min_liquidity_to_mcap_ratio', 0.001);
        config()->set('risk.liquidity.min_total_usd', 10_000.0);
        config()->set('risk.main_list.require_screening', true);
        config()->set('risk.min_data_completeness', 0.5);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /**
     * A token that currently passes every Recently Crossed gate: 10-day pool,
     * fresh, peak in band, $20M MC, $2M volume, $800K liquidity, LOWER risk,
     * 8,000 holders, crossed 6h ago.
     *
     * @param  array<string,mixed>  $attrs
     * @param  array<string,mixed>  $snapshot
     */
    private function passingToken(array $attrs = [], array $snapshot = []): Token
    {
        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'TKN',
            'name' => 'Test Token',
            'earliest_pair_created_at' => $this->now->subDays(10),
            'first_observed_at' => $this->now->subDays(8),
            'last_observed_at' => $this->now,
            'observed_peak_market_cap' => 25_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDays(1),
        ], $attrs));

        $token->marketSnapshots()->create(array_replace([
            'observed_at' => $this->now,
            'price_usd' => 0.02,
            'market_cap' => 20_000_000.0,
            'fdv' => 24_000_000.0,
            'liquidity_usd' => 800_000.0,
            'volume_h24' => 2_000_000.0,
            'txns_h24' => 3_000,
            'primary_pair_address' => 'pair-abc',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays(10),
        ], $snapshot));

        $token->refresh();
        $this->passRisk($token, RiskAssessment::LEVEL_LOWER);
        $this->setHolders($token, 8_000);

        QualificationEvent::query()->create([
            'token_id' => $token->id,
            'type' => QualificationEvent::TYPE_CURRENT_OBSERVATION,
            'crossed_at' => $this->now->subHours(6),
            'threshold_usd' => 5_000_000,
            'evidence_status' => QualificationEvent::TYPE_CURRENT_OBSERVATION,
            'source' => 'dexscreener',
            'market_cap_value' => 6_000_000.0,
        ]);

        return $token->fresh();
    }

    private function setHolders(Token $token, ?int $count): void
    {
        $assessment = $token->riskAssessment()->firstOrFail();
        $assessment->signals()->where('signal_key', 'holder_count')->delete();
        $assessment->signals()->create([
            'token_id' => $token->id,
            'signal_key' => 'holder_count',
            'signal_group' => RiskSignal::GROUP_HOLDER_DISTRIBUTION,
            'state' => $count === null ? RiskSignal::STATE_UNKNOWN : RiskSignal::STATE_MEASURED,
            'value' => $count === null ? null : (string) $count,
            'numeric_value' => $count === null ? null : (float) $count,
            'unit' => $count === null ? null : 'holders',
            'severity' => RiskSignal::SEVERITY_NONE,
            'source' => 'goplus',
            'source_checked_at' => $this->now,
            'explanation' => 'Holder count.',
        ]);
        $token->load('riskAssessment.signals');
    }

    private function runMarker(): void
    {
        $this->artisan('memecoins:mark-recently-crossed')->assertExitCode(0);
    }

    #[Test]
    public function it_stamps_a_token_that_passes_every_gate(): void
    {
        $token = $this->passingToken(['symbol' => 'GOOD']);
        $this->assertNull($token->recently_crossed_qualified_at);

        $this->runMarker();

        $this->assertNotNull($token->fresh()->recently_crossed_qualified_at);
        $this->assertTrue($token->fresh()->recently_crossed_qualified_at->equalTo($this->now));
    }

    #[Test]
    public function it_does_not_stamp_a_token_that_fails_the_risk_gate(): void
    {
        $token = $this->passingToken(['symbol' => 'RISKY']);
        $token->riskAssessment()->update([
            'risk_level' => RiskAssessment::LEVEL_HIGH,
            'hard_override_signal' => 'is_mintable',
            'main_list_eligible' => false,
        ]);

        $this->runMarker();

        $this->assertNull($token->fresh()->recently_crossed_qualified_at);
    }

    #[Test]
    public function it_does_not_stamp_a_token_whose_crossing_is_outside_the_window(): void
    {
        $token = $this->passingToken(['symbol' => 'STALECROSS']);
        $token->qualificationEvents()->update(['crossed_at' => $this->now->subDays(40)]);

        $this->runMarker();

        $this->assertNull($token->fresh()->recently_crossed_qualified_at);
    }

    #[Test]
    public function it_does_not_stamp_a_token_already_older_than_thirty_days(): void
    {
        // The DB candidate scope excludes it (age gate) — belt and braces.
        $token = $this->passingToken([
            'symbol' => 'AGED',
            'earliest_pair_created_at' => $this->now->subDays(31),
        ]);

        $this->runMarker();

        $this->assertNull($token->fresh()->recently_crossed_qualified_at);
    }

    #[Test]
    public function it_does_not_stamp_a_token_with_no_crossing_event(): void
    {
        $token = $this->passingToken(['symbol' => 'NOCROSS']);
        $token->qualificationEvents()->delete();

        $this->runMarker();

        $this->assertNull($token->fresh()->recently_crossed_qualified_at);
    }

    #[Test]
    public function the_marker_is_never_rewritten_on_a_later_run(): void
    {
        $token = $this->passingToken(['symbol' => 'ONCE']);
        $this->runMarker();
        $stampedAt = $token->fresh()->recently_crossed_qualified_at;
        $this->assertNotNull($stampedAt);

        // Time moves on; run again.
        CarbonImmutable::setTestNow($this->now->addDays(3));
        $this->runMarker();

        $this->assertTrue($token->fresh()->recently_crossed_qualified_at->equalTo($stampedAt));
    }

    #[Test]
    public function the_marker_is_not_cleared_when_the_token_later_fails_a_gate(): void
    {
        $token = $this->passingToken(['symbol' => 'DUMP']);
        $this->runMarker();
        $this->assertNotNull($token->fresh()->recently_crossed_qualified_at);

        // The token dumps hard and volume dries up.
        $token->latestSnapshot()->first()->update([
            'market_cap' => 800_000.0,
            'volume_h24' => 10.0,
            'liquidity_usd' => 1_000.0,
        ]);

        $this->runMarker();

        $this->assertNotNull($token->fresh()->recently_crossed_qualified_at);
    }

    #[Test]
    public function it_makes_no_external_calls(): void
    {
        Http::fake();
        $this->passingToken();

        $this->runMarker();

        Http::assertNothingSent();
    }
}
