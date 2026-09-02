<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\HistoricalPeakEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The centralised market-cap qualification band:
 *
 *   $5,000,000 <= verified/observed peak < $1,000,000,000
 *
 * The floor is INCLUSIVE (exactly $5M qualifies). The upper bound is EXCLUSIVE
 * (exactly $1B does NOT qualify). `qualifies()` and `peakAboveCeiling()` are
 * complementary for a peak that clears the floor.
 */
class HistoricalPeakEvidenceBoundaryTest extends TestCase
{
    private const FLOOR = 5_000_000.0;

    private const CEILING = 1_000_000_000.0;

    private function evidence(float $peak): HistoricalPeakEvidence
    {
        $e = new HistoricalPeakEvidence;
        $e->status = HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION;
        $e->peak_value_usd = $peak;

        return $e;
    }

    /**
     * @return array<string, array{0: float, 1: bool}>
     */
    public static function boundaryCases(): array
    {
        return [
            '4,999,999 is below the floor' => [4_999_999.0, false],
            '5,000,000 is the inclusive floor' => [5_000_000.0, true],
            '200,000,000 is inside the band' => [200_000_000.0, true],
            '999,999,999 is just under $1B' => [999_999_999.0, true],
            '1,000,000,000 hits the exclusive ceiling' => [1_000_000_000.0, false],
            '1,000,000,001 is above $1B' => [1_000_000_001.0, false],
        ];
    }

    #[Test]
    #[DataProvider('boundaryCases')]
    public function qualifies_uses_an_inclusive_floor_and_an_exclusive_one_billion_ceiling(float $peak, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->evidence($peak)->qualifies(self::FLOOR, self::CEILING),
        );
    }

    #[Test]
    public function peak_above_ceiling_is_true_at_exactly_one_billion_and_complements_qualifies(): void
    {
        // Exactly $1B: NOT qualified, but IS "above ceiling" (the reason surfaces).
        $atCeiling = $this->evidence(self::CEILING);
        $this->assertFalse($atCeiling->qualifies(self::FLOOR, self::CEILING));
        $this->assertTrue($atCeiling->peakAboveCeiling(self::FLOOR, self::CEILING));

        // $999,999,999: qualified, not above ceiling.
        $underCeiling = $this->evidence(999_999_999.0);
        $this->assertTrue($underCeiling->qualifies(self::FLOOR, self::CEILING));
        $this->assertFalse($underCeiling->peakAboveCeiling(self::FLOOR, self::CEILING));

        // Below the floor is neither qualified nor "above ceiling".
        $belowFloor = $this->evidence(4_999_999.0);
        $this->assertFalse($belowFloor->qualifies(self::FLOOR, self::CEILING));
        $this->assertFalse($belowFloor->peakAboveCeiling(self::FLOOR, self::CEILING));
    }
}
