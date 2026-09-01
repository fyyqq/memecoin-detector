<?php

declare(strict_types=1);

namespace App\Services\Trending;

/**
 * The result of {@see TrackedTrendScorer}: a 0-100 INTERNAL score plus its five
 * 0-100 component scores and the raw inputs — everything needed to answer "why
 * is this coin ranked #N?" transparently, without AI.
 *
 * This is NOT DexScreener's proprietary `trendingScoreH6/H24`.
 */
final readonly class TrackedTrendScore
{
    /**
     * @param  array{momentum:float,volume_activity:float,transaction_activity:float,liquidity_quality:float,persistence:float}  $components
     */
    public function __construct(
        public float $score,
        public array $components,
        public TrackedTrendInputs $inputs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tracked_trend_score' => $this->score,
            'components' => $this->components,
        ];
    }
}
