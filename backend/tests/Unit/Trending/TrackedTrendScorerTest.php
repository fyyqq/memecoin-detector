<?php

declare(strict_types=1);

namespace Tests\Unit\Trending;

use App\Services\Trending\TrackedTrendInputs;
use App\Services\Trending\TrackedTrendScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `tracked_trend_score` is transparent + deterministic. It is NOT
 * DexScreener's proprietary `trendingScore`. Market cap is NOT an input.
 */
class TrackedTrendScorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('trending.score.weights', [
            'momentum' => 0.30,
            'volume_activity' => 0.28,
            'transaction_activity' => 0.18,
            'liquidity_quality' => 0.12,
            'persistence' => 0.12,
        ]);
        config()->set('trending.score.references', [
            'momentum_pct' => 60.0,
            'volume_usd' => 2_000_000.0,
            'txns' => 800.0,
            'liquidity_usd' => 150_000.0,
        ]);
        config()->set('trending.score.unavailable_component', 0.25);
    }

    private function scorer(): TrackedTrendScorer
    {
        return new TrackedTrendScorer;
    }

    #[Test]
    public function it_is_deterministic_same_inputs_same_score(): void
    {
        $inputs = new TrackedTrendInputs('6h', 40.0, 1_500_000.0, 1_200, 250_000.0, 6, 12);

        $a = $this->scorer()->score($inputs);
        $b = $this->scorer()->score($inputs);

        $this->assertSame($a->score, $b->score);
        $this->assertSame($a->components, $b->components);
    }

    #[Test]
    public function every_component_and_the_overall_score_are_within_zero_and_one_hundred(): void
    {
        $result = $this->scorer()->score(new TrackedTrendInputs('24h', 5_000.0, 999_999_999.0, 999_999, 999_999_999.0, 999, 12));

        $this->assertGreaterThanOrEqual(0.0, $result->score);
        $this->assertLessThanOrEqual(100.0, $result->score);
        foreach ($result->components as $value) {
            $this->assertGreaterThanOrEqual(0.0, $value);
            $this->assertLessThanOrEqual(100.0, $value);
        }
    }

    #[Test]
    public function a_missing_metric_becomes_a_reduced_component_not_zero_and_not_fifty(): void
    {
        $result = $this->scorer()->score(new TrackedTrendInputs('6h', null, null, null, null, 0, 12));

        // unavailable_component (0.25) -> 25.0 for the four market components.
        $this->assertSame(25.0, $result->components['momentum']);
        $this->assertSame(25.0, $result->components['volume_activity']);
        $this->assertSame(25.0, $result->components['transaction_activity']);
        $this->assertSame(25.0, $result->components['liquidity_quality']);
        // persistence with 0 appearances is a real 0, not "unavailable".
        $this->assertSame(0.0, $result->components['persistence']);
        $this->assertGreaterThan(0.0, $result->score);
        $this->assertLessThan(50.0, $result->score);
    }

    #[Test]
    public function higher_momentum_volume_txns_liquidity_and_persistence_all_raise_the_score(): void
    {
        $low = $this->scorer()->score(new TrackedTrendInputs('6h', -20.0, 10_000.0, 20, 10_000.0, 1, 12));
        $high = $this->scorer()->score(new TrackedTrendInputs('6h', 120.0, 5_000_000.0, 5_000, 1_000_000.0, 12, 12));

        $this->assertGreaterThan($low->score, $high->score);
        $this->assertGreaterThan($low->components['momentum'], $high->components['momentum']);
        $this->assertGreaterThan($low->components['volume_activity'], $high->components['volume_activity']);
        $this->assertGreaterThan($low->components['transaction_activity'], $high->components['transaction_activity']);
        $this->assertGreaterThan($low->components['liquidity_quality'], $high->components['liquidity_quality']);
        $this->assertGreaterThan($low->components['persistence'], $high->components['persistence']);
    }

    #[Test]
    public function market_cap_is_not_an_input_two_tokens_with_identical_activity_score_identically(): void
    {
        // There is no market-cap parameter on TrackedTrendInputs at all — this
        // test documents that: identical activity => identical score regardless
        // of how big the token is.
        $a = $this->scorer()->score(new TrackedTrendInputs('24h', 30.0, 800_000.0, 900, 300_000.0, 4, 12));
        $b = $this->scorer()->score(new TrackedTrendInputs('24h', 30.0, 800_000.0, 900, 300_000.0, 4, 12));

        $this->assertSame($a->score, $b->score);
    }

    #[Test]
    public function zero_percent_momentum_scores_the_neutral_midpoint(): void
    {
        $result = $this->scorer()->score(new TrackedTrendInputs('6h', 0.0, 1_000_000.0, 500, 100_000.0, 6, 12));

        $this->assertSame(50.0, $result->components['momentum']);
    }
}
