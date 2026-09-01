<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Models\TrendingSnapshot;
use Carbon\CarbonImmutable;

/**
 * One trending token, deduplicated to a single representative pair, carrying the
 * market data for BOTH timeframes (6h + 24h) so the collector scores it once per
 * timeframe.
 *
 * Built by {@see TrendingMetaCollector} from `GET /metas/meta/v1/{slug}` pair
 * objects. Pure value object — no persistence, no scoring.
 */
final readonly class TrendingCandidate
{
    /**
     * @param  list<string>  $metaSlugs  every trending meta that surfaced this token
     */
    public function __construct(
        public string $chainId,
        public string $tokenAddress,
        public ?string $pairAddress,
        public ?string $dexId,
        public ?string $symbol,
        public ?string $name,
        public ?float $marketCap,
        public ?float $liquidityUsd,
        public ?CarbonImmutable $pairCreatedAt,
        public ?float $volume6h,
        public ?float $volume24h,
        public ?float $priceChange6h,
        public ?float $priceChange24h,
        public ?int $txns6h,
        public ?int $txns24h,
        public string $trendingMetaSlug,
        public string $trendingMetaName,
        public array $metaSlugs,
        public CarbonImmutable $capturedAt,
    ) {}

    public function key(): string
    {
        return mb_strtolower($this->chainId).':'.mb_strtolower($this->tokenAddress);
    }

    public function metaCount(): int
    {
        return count(array_unique($this->metaSlugs));
    }

    public function volumeFor(string $timeframe): ?float
    {
        return $timeframe === TrendingSnapshot::TIMEFRAME_6H ? $this->volume6h : $this->volume24h;
    }

    public function priceChangeFor(string $timeframe): ?float
    {
        return $timeframe === TrendingSnapshot::TIMEFRAME_6H ? $this->priceChange6h : $this->priceChange24h;
    }

    public function txnsFor(string $timeframe): ?int
    {
        return $timeframe === TrendingSnapshot::TIMEFRAME_6H ? $this->txns6h : $this->txns24h;
    }

    public function pairAgeHours(CarbonImmutable $now): ?float
    {
        if ($this->pairCreatedAt === null) {
            return null;
        }

        return max(0.0, ($now->getTimestamp() - $this->pairCreatedAt->getTimestamp()) / 3600.0);
    }
}
