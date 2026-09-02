<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\QualificationEvent;
use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesRiskAssessments;
use Tests\TestCase;

/**
 * "📈 Post-30-Day Memecoins" — GET /api/memecoins/post-30-day.
 *
 * Read-only. PostgreSQL only. A memecoin appears only when it was PREVIOUSLY
 * approved by the Recently Crossed flow (`recently_crossed_qualified_at`
 * stamped) AND its pool age is now > 30 days. Historical approval survives the
 * token later dumping / going stale / being re-screened HIGH.
 */
class PostThirtyDayTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow(null);
        parent::tearDown();
    }

    /**
     * A token that was previously approved by Recently Crossed. By default aged
     * 45 days, approved 20 days ago, current MC $12M, LOWER risk, 8,000 holders.
     *
     * @param  array<string,mixed>  $attrs
     * @param  array<string,mixed>  $snapshot
     * @param  array{approved?:bool,risk?:?string,holders?:?int}  $opts
     */
    private function token(array $attrs = [], array $snapshot = [], array $opts = []): Token
    {
        $ageDays = $attrs['age_days'] ?? 45;
        unset($attrs['age_days']);

        /** @var Token $token */
        $token = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'TKN',
            'name' => 'Test Token',
            'earliest_pair_created_at' => $this->now->subDays($ageDays),
            'first_observed_at' => $this->now->subDays($ageDays),
            'last_observed_at' => $this->now,
            'recently_crossed_qualified_at' => ($opts['approved'] ?? true) ? $this->now->subDays(20) : null,
            'observed_peak_market_cap' => 25_000_000.0,
            'observed_peak_market_cap_at' => $this->now->subDays(25),
        ], $attrs));

        $token->marketSnapshots()->create(array_replace([
            'observed_at' => $this->now,
            'price_usd' => 0.012,
            'market_cap' => 12_000_000.0,
            'fdv' => 14_000_000.0,
            'liquidity_usd' => 500_000.0,
            'volume_h24' => 900_000.0,
            'price_change_h24' => -1.0,
            'txns_h24' => 2_000,
            'buys_h24' => 900,
            'sells_h24' => 1_100,
            'primary_pair_address' => 'pair-abc',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays($ageDays),
        ], $snapshot));

        $token->refresh();

        $risk = $opts['risk'] ?? RiskAssessment::LEVEL_LOWER;
        if ($risk !== null) {
            in_array($risk, [RiskAssessment::LEVEL_LOWER, RiskAssessment::LEVEL_MEDIUM], true)
                ? $this->passRisk($token, $risk)
                : $this->failRisk($token, $risk);
            $this->setHolders($token, array_key_exists('holders', $opts) ? $opts['holders'] : 8_000);
        }

        return $token;
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

    private function crossing(Token $token, CarbonImmutable $crossedAt): void
    {
        QualificationEvent::query()->create([
            'token_id' => $token->id,
            'type' => QualificationEvent::TYPE_CURRENT_OBSERVATION,
            'crossed_at' => $crossedAt,
            'threshold_usd' => 5_000_000,
            'evidence_status' => QualificationEvent::TYPE_CURRENT_OBSERVATION,
            'source' => 'dexscreener',
            'market_cap_value' => 6_000_000.0,
        ]);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function rows(string $query = ''): array
    {
        return $this->getJson('/api/memecoins/post-30-day'.$query)->assertOk()->json('data');
    }

    /**
     * @return array<int, string|null>
     */
    private function symbols(string $query = ''): array
    {
        return array_map(static fn (array $r) => $r['symbol'], $this->rows($query));
    }

    // --- membership -------------------------------------------------------

    #[Test]
    public function a_previously_approved_token_older_than_thirty_days_is_included(): void
    {
        $this->token(['symbol' => 'AGED', 'age_days' => 31]);
        $this->token(['symbol' => 'OLDER', 'age_days' => 45]);

        $this->assertEqualsCanonicalizing(['AGED', 'OLDER'], $this->symbols());
    }

    #[Test]
    public function a_previously_approved_token_still_within_thirty_days_is_not_in_this_section(): void
    {
        $this->token(['symbol' => 'YOUNG', 'age_days' => 20]);

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function a_never_approved_old_token_is_excluded(): void
    {
        $token = $this->token(['symbol' => 'NEVER', 'age_days' => 45], [], ['approved' => false]);
        // Even with a $5M crossing on record — approval is the gate, not the crossing.
        $this->crossing($token, $this->now->subDays(40));

        $this->assertSame([], $this->symbols());
    }

    #[Test]
    public function an_arbitrary_old_memecoin_with_current_mc_over_five_million_is_excluded(): void
    {
        $this->token(
            ['symbol' => 'RANDO', 'age_days' => 60, 'observed_peak_market_cap' => 40_000_000.0],
            ['market_cap' => 30_000_000.0],
            ['approved' => false],
        );

        $this->assertSame([], $this->symbols());
    }

    // --- boundary --------------------------------------------------------

    #[Test]
    public function exactly_thirty_days_is_not_post_thirty_day_and_thirty_one_days_is(): void
    {
        $this->token(['symbol' => 'EXACT', 'earliest_pair_created_at' => $this->now->subDays(30)]);
        $this->token(['symbol' => 'PLUS1', 'earliest_pair_created_at' => $this->now->subDays(30)->subMinutes(1)]);

        $this->assertSame(['PLUS1'], $this->symbols());
    }

    // --- historical approval preserved ---------------------------------

    #[Test]
    public function approval_survives_a_dump_below_five_million(): void
    {
        $this->token(
            ['symbol' => 'DUMPED', 'age_days' => 50, 'observed_peak_market_cap' => 30_000_000.0],
            ['market_cap' => 1_200_000.0, 'volume_h24' => 4_000.0, 'liquidity_usd' => 20_000.0],
        );

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('DUMPED', $rows[0]['symbol']);
        $this->assertSame('COOLED', $rows[0]['status']);
    }

    #[Test]
    public function approval_survives_losing_discovery_freshness(): void
    {
        $this->token(['symbol' => 'STALE', 'age_days' => 40, 'last_observed_at' => $this->now->subDays(20)]);

        $this->assertSame(['STALE'], $this->symbols());
    }

    #[Test]
    public function approval_survives_a_later_high_risk_rescreen_but_current_risk_is_shown(): void
    {
        $this->token(['symbol' => 'RISKY', 'age_days' => 40], [], ['risk' => RiskAssessment::LEVEL_HIGH]);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('RISKY', $rows[0]['symbol']);
        $this->assertSame('HIGH', $rows[0]['risk_level']);
    }

    #[Test]
    public function an_unscreened_previously_approved_token_still_appears_with_pending_risk(): void
    {
        $token = Token::query()->create([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'NOSCAN',
            'name' => 'Unscreened',
            'earliest_pair_created_at' => $this->now->subDays(40),
            'first_observed_at' => $this->now->subDays(40),
            'last_observed_at' => $this->now,
            'recently_crossed_qualified_at' => $this->now->subDays(15),
            'observed_peak_market_cap' => 20_000_000.0,
        ]);
        $token->marketSnapshots()->create([
            'observed_at' => $this->now,
            'price_usd' => 0.01,
            'market_cap' => 10_000_000.0,
            'fdv' => 12_000_000.0,
            'liquidity_usd' => 200_000.0,
            'volume_h24' => 100_000.0,
            'txns_h24' => 500,
            'primary_pair_address' => 'p',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $this->now->subDays(40),
        ]);

        $rows = $this->rows();
        $this->assertSame(['NOSCAN'], array_map(static fn ($r) => $r['symbol'], $rows));
        $this->assertNull($rows[0]['risk_level']);
        $this->assertSame('pending', $rows[0]['risk_status']);
    }

    #[Test]
    public function a_legacy_stamp_still_renders_in_post_thirty_day(): void
    {
        // A token stamped BEFORE the pippo-incident revert (when robinhood could
        // still qualify). The Post-30-Day endpoint is "dumb" — it renders any
        // live stamp. Going forward such a stamp is either revoked by the marker
        // (unscreenable chain) or never granted.
        $token = Token::query()->create([
            'chain_id' => 'robinhood',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'CASHCAT',
            'name' => 'Cash Cat',
            'earliest_pair_created_at' => $this->now->subDays(75),
            'first_observed_at' => $this->now->subDays(75),
            'last_observed_at' => $this->now,
            'recently_crossed_qualified_at' => $this->now->subDays(50),
            'observed_peak_market_cap' => 300_000_000.0,
        ]);
        $token->marketSnapshots()->create([
            'observed_at' => $this->now,
            'price_usd' => 0.26,
            'market_cap' => 258_000_000.0,
            'fdv' => 258_000_000.0,
            'liquidity_usd' => 4_490_000.0,
            'volume_h24' => 9_490_000.0,
            'txns_h24' => 5_800,
            'primary_pair_address' => 'p',
            'primary_dex_id' => 'uniswap',
            'earliest_pair_created_at' => $this->now->subDays(75),
        ]);

        $rows = $this->rows('?chain=robinhood');
        $this->assertSame(['CASHCAT'], array_map(static fn ($r) => $r['symbol'], $rows));
        $this->assertNull($rows[0]['risk_level']);
        $this->assertSame('pending', $rows[0]['risk_status']);
    }

    #[Test]
    public function a_revoked_stamp_removes_the_token_from_post_thirty_day(): void
    {
        $token = $this->token(['symbol' => 'REVOKED', 'age_days' => 45]);
        $this->assertSame(['REVOKED'], $this->symbols());

        // The marker later clears the stamp (a red flag surfaced while its
        // crossing was still in-window).
        $token->forceFill([
            'recently_crossed_qualified_at' => null,
            'recently_crossed_revoked_at' => $this->now,
            'recently_crossed_revoked_reason' => 'post_crossing_collapse',
        ])->save();

        $this->assertSame([], $this->symbols());
    }

    // --- chain filter ---------------------------------------------------

    #[Test]
    public function the_chain_filter_narrows_results_to_one_real_chain(): void
    {
        $this->token(['symbol' => 'SOL', 'chain_id' => 'solana']);
        $this->token(['symbol' => 'BNB', 'chain_id' => 'bsc']);
        $this->token(['symbol' => 'ETH', 'chain_id' => 'ethereum']);

        $this->assertEqualsCanonicalizing(['SOL', 'BNB', 'ETH'], $this->symbols());
        $this->assertSame(['SOL'], $this->symbols('?chain=solana'));
        $this->assertSame(['BNB'], $this->symbols('?chain=bsc'));
    }

    // --- sorting ------------------------------------------------------

    #[Test]
    public function it_sorts_by_market_cap_ascending_and_descending(): void
    {
        $this->token(['symbol' => 'SMALL'], ['market_cap' => 6_000_000.0]);
        $this->token(['symbol' => 'BIG'], ['market_cap' => 90_000_000.0]);
        $this->token(['symbol' => 'MID'], ['market_cap' => 30_000_000.0]);

        $this->assertSame(['BIG', 'MID', 'SMALL'], $this->symbols('?sort=market_cap&direction=desc'));
        $this->assertSame(['SMALL', 'MID', 'BIG'], $this->symbols('?sort=market_cap&direction=asc'));
    }

    #[Test]
    public function it_sorts_by_volume_ascending_and_descending(): void
    {
        $this->token(['symbol' => 'QUIET'], ['volume_h24' => 10_000.0]);
        $this->token(['symbol' => 'LOUD'], ['volume_h24' => 5_000_000.0]);

        $this->assertSame(['LOUD', 'QUIET'], $this->symbols('?sort=volume&direction=desc'));
        $this->assertSame(['QUIET', 'LOUD'], $this->symbols('?sort=volume&direction=asc'));
    }

    #[Test]
    public function ties_break_deterministically_on_peak_then_token_id(): void
    {
        // Identical current MC; different peak MC -> peak decides, then id.
        $a = $this->token(['symbol' => 'A', 'observed_peak_market_cap' => 10_000_000.0], ['market_cap' => 8_000_000.0]);
        $b = $this->token(['symbol' => 'B', 'observed_peak_market_cap' => 50_000_000.0], ['market_cap' => 8_000_000.0]);
        $c = $this->token(['symbol' => 'C', 'observed_peak_market_cap' => 50_000_000.0], ['market_cap' => 8_000_000.0]);

        $this->assertLessThan($c->id, $b->id);

        // desc: higher peak first; equal peak -> lower id first.
        $this->assertSame(['B', 'C', 'A'], $this->symbols('?sort=market_cap&direction=desc'));
        // asc is fully reversed and still deterministic.
        $this->assertSame(['A', 'B', 'C'], $this->symbols('?sort=market_cap&direction=asc'));
    }

    #[Test]
    public function the_default_sort_is_peak_market_cap_descending(): void
    {
        $this->token(['symbol' => 'LOWPEAK', 'observed_peak_market_cap' => 7_000_000.0]);
        $this->token(['symbol' => 'HIGHPEAK', 'observed_peak_market_cap' => 800_000_000.0]);

        $res = $this->getJson('/api/memecoins/post-30-day')->assertOk();
        $res->assertJsonPath('meta.sort', 'peak_market_cap');
        $res->assertJsonPath('meta.direction', 'desc');
        $this->assertSame(['HIGHPEAK', 'LOWPEAK'], $this->symbols());
    }

    #[Test]
    public function rows_with_no_snapshot_metric_sort_last_in_both_directions(): void
    {
        $withMc = $this->token(['symbol' => 'HASMC'], ['market_cap' => 9_000_000.0]);
        $noMc = $this->token(['symbol' => 'NOMC'], ['market_cap' => null]);

        $this->assertSame(['HASMC', 'NOMC'], $this->symbols('?sort=market_cap&direction=desc'));
        $this->assertSame(['HASMC', 'NOMC'], $this->symbols('?sort=market_cap&direction=asc'));
        $this->assertNotNull($withMc->id);
        $this->assertNotNull($noMc->id);
    }

    #[Test]
    public function an_invalid_sort_or_direction_is_rejected(): void
    {
        $this->getJson('/api/memecoins/post-30-day?sort=nonsense')->assertStatus(422);
        $this->getJson('/api/memecoins/post-30-day?direction=sideways')->assertStatus(422);
    }

    // --- no overlap with Recently Crossed -----------------------------

    #[Test]
    public function a_token_cannot_appear_in_both_recently_crossed_and_post_thirty_day(): void
    {
        // 29 days old, freshly approved, crossed 3 days ago -> Recently Crossed.
        $young = $this->token(['symbol' => 'YOUNG', 'age_days' => 29]);
        $this->crossing($young, $this->now->subDays(3));

        // 31 days old, approved -> Post-30-Day only.
        $old = $this->token(['symbol' => 'OLD', 'age_days' => 31]);
        $this->crossing($old, $this->now->subDays(20));

        $recently = array_map(
            static fn ($r) => $r['symbol'],
            $this->getJson('/api/memecoins/recently-crossed')->assertOk()->json('data'),
        );
        $post = $this->symbols();

        $this->assertContains('YOUNG', $recently);
        $this->assertNotContains('OLD', $recently);
        $this->assertSame(['OLD'], $post);
        $this->assertEmpty(array_intersect($recently, $post));
    }

    // --- endpoint contract -------------------------------------------

    #[Test]
    public function the_endpoint_is_read_only_and_makes_no_provider_calls(): void
    {
        Http::fake();
        $this->token(['symbol' => 'X']);

        DB::enableQueryLog();
        $this->getJson('/api/memecoins/post-30-day')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        Http::assertNothingSent();
        $this->assertLessThanOrEqual(8, count($queries));
        foreach ($queries as $q) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\s/i', $q['query']);
        }
    }

    #[Test]
    public function the_payload_carries_the_expected_shape(): void
    {
        $this->token(['symbol' => 'SHAPE', 'age_days' => 42]);

        $res = $this->getJson('/api/memecoins/post-30-day')->assertOk();
        $res->assertJsonPath('meta.age_threshold_days', 30);
        $res->assertJsonPath('meta.source', 'postgresql');
        $res->assertJsonStructure([
            'data' => [[
                'id', 'chain_id', 'token_address', 'name', 'symbol', 'age_days',
                'current_market_cap', 'observed_peak_market_cap', 'peak_market_cap',
                'volume_h24', 'liquidity_usd', 'holder_count', 'risk_level',
                'risk_score', 'risk_status', 'status', 'approved_at', 'crossed_at',
                'crossing_type', 'days_to_cross', 'last_observed_at',
            ]],
            'meta' => ['count', 'retrieved_at', 'source', 'sort', 'direction', 'age_threshold_days', 'sorts', 'note'],
        ]);
    }

    #[Test]
    public function the_empty_state_is_a_zero_length_data_array(): void
    {
        $this->getJson('/api/memecoins/post-30-day')->assertOk()
            ->assertJsonPath('meta.count', 0)
            ->assertJsonPath('data', []);
    }
}
