<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

use App\Services\Ranking\MonthWindow;

/**
 * What a {@see HistoricalResearchProvider} needs to research ONE token's ONE
 * metric for ONE calendar month.
 *
 * Identity is `chainId` + `tokenAddress` (the codebase-wide token identity).
 * `tokenAddress` may be null ONLY for {@see HistoricalResearchProvider::searchToken()},
 * where `symbol` / `name` are hints to resolve it — a metric is never fetched
 * without a resolved address.
 */
final readonly class HistoricalResearchRequest
{
    public function __construct(
        public string $chainId,
        public ?string $tokenAddress,
        public MonthWindow $window,
        public ?string $symbol = null,
        public ?string $name = null,
    ) {}

    public function hasAddress(): bool
    {
        return $this->tokenAddress !== null && trim($this->tokenAddress) !== '';
    }

    /** `chain_id:token_address` — the stable identity key, lower-cased. */
    public function tokenKey(): string
    {
        return mb_strtolower(trim($this->chainId)).':'.mb_strtolower(trim((string) $this->tokenAddress));
    }
}
