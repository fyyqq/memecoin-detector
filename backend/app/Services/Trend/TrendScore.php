<?php

declare(strict_types=1);

namespace App\Services\Trend;

/**
 * The result of {@see TrendScorer}: a 0-100 overall score, its four 0-100
 * component scores, and the raw inputs — everything needed to answer
 * "why is this coin ranked #1?" without AI.
 */
final readonly class TrendScore
{
    /**
     * @param  array{price_momentum:float,volume_liquidity:float,peak_retention:float,transaction_activity:float}  $components
     */
    public function __construct(
        public float $score,
        public array $components,
        public TrendInputs $inputs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trend_score' => $this->score,
            'trend_components' => $this->components,
        ];
    }
}
