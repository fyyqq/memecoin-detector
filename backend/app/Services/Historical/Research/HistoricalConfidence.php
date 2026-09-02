<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

/**
 * The evidence-quality band for one historical metric (Step 26). It represents
 * HOW GOOD THE EVIDENCE IS — it is NOT a probability of anything.
 *
 * The band is DERIVED deterministically from evidence characteristics by
 * {@see HistoricalConfidenceCalculator} — it is never a hand-typed number.
 * Full cross-source reconciliation is a later phase; the calculator already
 * accepts a corroboration count so that phase can feed it without a signature
 * change.
 */
enum HistoricalConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Unknown = 'unknown';

    /** 0 = Unknown … 3 = High — for deterministic clamping / comparison. */
    public function level(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    public static function fromLevel(int $level): self
    {
        return match (max(0, min(3, $level))) {
            0 => self::Unknown,
            1 => self::Low,
            2 => self::Medium,
            3 => self::High,
        };
    }

    /** The weaker of two bands. */
    public function min(self $other): self
    {
        return $this->level() <= $other->level() ? $this : $other;
    }
}
