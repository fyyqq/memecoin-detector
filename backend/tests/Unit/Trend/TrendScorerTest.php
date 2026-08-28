<?php

declare(strict_types=1);

namespace Tests\Unit\Trend;

use App\Services\Trend\TrendInputs;
use App\Services\Trend\TrendScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pure scoring maths — no DB, no HTTP. Boots the framework only for config().
 */
class TrendScorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('trend.weights', [
            'price_momentum' => 0.30,
            'volume_liquidity' => 0.30,
            'peak_retention' => 0.20,
            'transaction_activity' => 0.20,
        ]);
        config()->set('trend.momentum_reference_pct', 100.0);
        config()->set('trend.volume_liquidity_reference_ratio', 1.0);
        config()->set('trend.txns_reference_count', 500.0);
        config()->set('trend.unavailable_component_score', 25.0);
    }

    private function scorer(): TrendScorer
    {
        return new TrendScorer;
    }

    private function inputs(
        ?float $priceChange = 0.0,
        ?float $volume = 100_000.0,
        ?float $liquidity = 100_000.0,
        ?float $currentMc = 5_000_000.0,
        ?float $peakMc = 10_000_000.0,
        ?int $txns = 500,
    ): TrendInputs {
        return new TrendInputs($priceChange, $volume, $liquidity, $currentMc, $peakMc, $txns);
    }

    #[Test]
    public function the_overall_score_and_every_component_stay_within_0_to_100(): void
    {
        $cases = [
            $this->inputs(priceChange: 999.0, volume: 1e12, liquidity: 1.0, currentMc: 9e9, peakMc: 1.0, txns: 10_000_000),
            $this->inputs(priceChange: -999.0, volume: 0.0, liquidity: 1e9, currentMc: 0.0, peakMc: 1e9, txns: 0),
            $this->inputs(null, null, null, null, null, null),
        ];

        foreach ($cases as $case) {
            $result = $this->scorer()->score($case);
            $this->assertGreaterThanOrEqual(0.0, $result->score);
            $this->assertLessThanOrEqual(100.0, $result->score);
            foreach ($result->components as $value) {
                $this->assertGreaterThanOrEqual(0.0, $value);
                $this->assertLessThanOrEqual(100.0, $value);
            }
        }
    }

    #[Test]
    public function price_momentum_is_the_midpoint_at_zero_and_symmetric(): void
    {
        $flat = $this->scorer()->score($this->inputs(priceChange: 0.0))->components['price_momentum'];
        $up = $this->scorer()->score($this->inputs(priceChange: 100.0))->components['price_momentum'];
        $down = $this->scorer()->score($this->inputs(priceChange: -100.0))->components['price_momentum'];

        $this->assertSame(50.0, $flat);
        $this->assertEqualsWithDelta(88.1, $up, 0.2);   // 50 * (1 + tanh(1))
        $this->assertEqualsWithDelta(11.9, $down, 0.2);
        $this->assertGreaterThan($flat, $up);
        $this->assertLessThan($flat, $down);
    }

    #[Test]
    public function negative_price_momentum_lowers_the_component_below_a_positive_move(): void
    {
        $neg = $this->scorer()->score($this->inputs(priceChange: -40.0))->components['price_momentum'];
        $pos = $this->scorer()->score($this->inputs(priceChange: 40.0))->components['price_momentum'];

        $this->assertLessThan(50.0, $neg);
        $this->assertGreaterThan(50.0, $pos);
    }

    #[Test]
    public function higher_volume_relative_to_liquidity_increases_the_component(): void
    {
        $low = $this->scorer()->score($this->inputs(volume: 100_000.0, liquidity: 1_000_000.0))->components['volume_liquidity'];
        $even = $this->scorer()->score($this->inputs(volume: 1_000_000.0, liquidity: 1_000_000.0))->components['volume_liquidity'];
        $high = $this->scorer()->score($this->inputs(volume: 9_000_000.0, liquidity: 1_000_000.0))->components['volume_liquidity'];

        $this->assertEqualsWithDelta(50.0, $even, 0.1);       // ratio 1 -> 50
        $this->assertEqualsWithDelta(90.0, $high, 0.1);       // ratio 9 -> 90
        $this->assertLessThan($even, $low);
        $this->assertGreaterThan($even, $high);
    }

    #[Test]
    public function null_or_zero_liquidity_does_not_divide_by_zero_and_yields_the_reduced_score(): void
    {
        $nullLiq = $this->scorer()->score($this->inputs(volume: 500_000.0, liquidity: null));
        $zeroLiq = $this->scorer()->score($this->inputs(volume: 500_000.0, liquidity: 0.0));

        $this->assertSame(25.0, $nullLiq->components['volume_liquidity']);
        $this->assertSame(25.0, $zeroLiq->components['volume_liquidity']);
    }

    #[Test]
    public function peak_retention_is_the_current_over_peak_ratio_times_100(): void
    {
        $half = $this->scorer()->score($this->inputs(currentMc: 6_000_000.0, peakMc: 12_000_000.0));
        $this->assertSame(50.0, $half->components['peak_retention']);

        $full = $this->scorer()->score($this->inputs(currentMc: 12_000_000.0, peakMc: 12_000_000.0));
        $this->assertSame(100.0, $full->components['peak_retention']);
    }

    #[Test]
    public function peak_retention_clamps_when_current_market_cap_exceeds_the_observed_peak(): void
    {
        $inconsistent = $this->scorer()->score($this->inputs(currentMc: 20_000_000.0, peakMc: 12_000_000.0));
        $this->assertSame(100.0, $inconsistent->components['peak_retention']);
    }

    #[Test]
    public function null_market_cap_is_handled_safely_with_the_reduced_score(): void
    {
        $result = $this->scorer()->score($this->inputs(currentMc: null));
        $this->assertSame(25.0, $result->components['peak_retention']);
    }

    #[Test]
    public function higher_transaction_activity_increases_the_component(): void
    {
        $none = $this->scorer()->score($this->inputs(txns: 0))->components['transaction_activity'];
        $ref = $this->scorer()->score($this->inputs(txns: 500))->components['transaction_activity'];
        $busy = $this->scorer()->score($this->inputs(txns: 4_500))->components['transaction_activity'];

        $this->assertSame(0.0, $none);
        $this->assertEqualsWithDelta(50.0, $ref, 0.1);
        $this->assertEqualsWithDelta(90.0, $busy, 0.1);
        $this->assertGreaterThan($ref, $busy);
    }

    #[Test]
    public function null_transactions_yield_the_reduced_score(): void
    {
        $result = $this->scorer()->score($this->inputs(txns: null));
        $this->assertSame(25.0, $result->components['transaction_activity']);
    }

    #[Test]
    public function every_metric_missing_produces_the_reduced_score_across_the_board(): void
    {
        $result = $this->scorer()->score(new TrendInputs(null, null, null, null, null, null));

        $this->assertSame([
            'price_momentum' => 25.0,
            'volume_liquidity' => 25.0,
            'peak_retention' => 25.0,
            'transaction_activity' => 25.0,
        ], $result->components);
        $this->assertSame(25.0, $result->score);
    }

    #[Test]
    public function weights_are_configurable(): void
    {
        $inputs = $this->inputs(priceChange: 100.0, volume: 100_000.0, liquidity: 100_000.0, currentMc: 5_000_000.0, peakMc: 10_000_000.0, txns: 500);

        config()->set('trend.weights', ['price_momentum' => 1.0, 'volume_liquidity' => 0.0, 'peak_retention' => 0.0, 'transaction_activity' => 0.0]);
        $momentumOnly = (new TrendScorer)->score($inputs);

        // With all weight on price momentum, the overall score == that component.
        $this->assertSame($momentumOnly->components['price_momentum'], $momentumOnly->score);
    }

    #[Test]
    public function the_overall_score_is_the_weighted_average_of_the_components(): void
    {
        $inputs = $this->inputs(priceChange: 0.0, volume: 1_000_000.0, liquidity: 1_000_000.0, currentMc: 5_000_000.0, peakMc: 10_000_000.0, txns: 500);
        $result = $this->scorer()->score($inputs);

        // components: 50, 50, 50, 50  -> weighted avg 50
        $this->assertSame(50.0, $result->score);
    }
}
