<?php

declare(strict_types=1);

namespace App\Services\Ranking;

use Carbon\CarbonImmutable;

/**
 * A calendar month as a half-open UTC interval `[start, endExclusive)`.
 */
final readonly class MonthWindow
{
    public function __construct(
        public int $year,
        public int $month,
        public CarbonImmutable $start,
        public CarbonImmutable $endExclusive,
    ) {}

    public static function of(int $year, int $month): self
    {
        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC');

        return new self($year, $month, $start, $start->addMonth());
    }

    public static function containing(CarbonImmutable $moment): self
    {
        return self::of((int) $moment->year, (int) $moment->month);
    }

    /** The previous calendar month. */
    public function previous(): self
    {
        $prev = $this->start->subMonth();

        return self::of((int) $prev->year, (int) $prev->month);
    }

    public function isPast(CarbonImmutable $now): bool
    {
        return $this->endExclusive->lessThanOrEqualTo($now);
    }

    public function isFuture(CarbonImmutable $now): bool
    {
        return $this->start->greaterThan($now);
    }

    public function isCurrent(CarbonImmutable $now): bool
    {
        return ! $this->isPast($now) && ! $this->isFuture($now);
    }

    public function monthName(): string
    {
        return $this->start->format('F');
    }
}
