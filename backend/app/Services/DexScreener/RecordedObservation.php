<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

use App\Models\MarketSnapshot;
use App\Models\Token;

/**
 * Result of persisting one normalized candidate: the (fresh) Token, the snapshot
 * that was appended, and what changed.
 */
final readonly class RecordedObservation
{
    public function __construct(
        public Token $token,
        public MarketSnapshot $snapshot,
        public bool $tokenWasCreated,
        public bool $peakUpdated,
        public ?float $previousObservedPeak,
    ) {}
}
