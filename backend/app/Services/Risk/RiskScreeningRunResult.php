<?php

declare(strict_types=1);

namespace App\Services\Risk;

/**
 * Summary of one `memecoins:screen-risk` run.
 */
final class RiskScreeningRunResult
{
    public function __construct(
        public int $tokensAnalyzed = 0,
        public int $mainListEligible = 0,
        public int $riskWatch = 0,
        public int $lower = 0,
        public int $medium = 0,
        public int $high = 0,
        public int $critical = 0,
        public int $unknown = 0,
        public int $skippedCooldown = 0,
        public int $providerFailures = 0,
        public float $durationSeconds = 0.0,
    ) {}

    /** @return array<string,int|float> */
    public function toArray(): array
    {
        return [
            'tokens_analyzed' => $this->tokensAnalyzed,
            'main_list_eligible' => $this->mainListEligible,
            'risk_watch' => $this->riskWatch,
            'lower' => $this->lower,
            'medium' => $this->medium,
            'high' => $this->high,
            'critical' => $this->critical,
            'unknown' => $this->unknown,
            'skipped_cooldown' => $this->skippedCooldown,
            'provider_failures' => $this->providerFailures,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
