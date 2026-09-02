<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

/**
 * Turns {@see HistoricalConfidenceSignals} into a {@see HistoricalConfidence}
 * band — deterministically, with no randomness and no hidden state.
 *
 * The rules (applied in order):
 *
 *   1. metric not available            ⇒ Unknown (stop)
 *   2. base band from source credibility:
 *        primary_market_data / historical_provider / archived_dexscreener ⇒ High
 *        reputable_reporting                                              ⇒ Medium
 *        secondary                                                        ⇒ Low
 *        low_quality                                                      ⇒ Unknown
 *   3. −1 band if there is no observed timestamp
 *   4. −1 band if token identity is not verified
 *   5. basis adjustment:
 *        reconstructed ⇒ −1 band
 *        estimate      ⇒ hard cap at Low (an estimate is never High/Medium)
 *        none          ⇒ Unknown (an unavailable metric already returned at 1)
 *   6. +1 band if ≥ 1 corroborating source AND basis is `observed` (never above High)
 *
 * The band is then clamped to [Unknown, High].
 */
class HistoricalConfidenceCalculator
{
    public function evaluate(HistoricalConfidenceSignals $signals): HistoricalConfidence
    {
        if (! $signals->metricAvailable || $signals->basis === MetricBasis::None) {
            return HistoricalConfidence::Unknown;
        }

        $level = match (true) {
            $signals->sourceCredibility->rank() <= 2 => 3, // High
            $signals->sourceCredibility === SourceCredibility::ReputableReporting => 2, // Medium
            $signals->sourceCredibility === SourceCredibility::Secondary => 1, // Low
            default => 0, // low_quality ⇒ Unknown
        };

        if (! $signals->hasObservedTimestamp) {
            $level--;
        }
        if (! $signals->identityVerified) {
            $level--;
        }
        if ($signals->basis === MetricBasis::Reconstructed) {
            $level--;
        }
        if ($signals->corroboratingSources >= 1 && $signals->basis === MetricBasis::Observed) {
            $level++;
        }

        if ($signals->basis === MetricBasis::Estimate) {
            $level = min($level, HistoricalConfidence::Low->level());
        }

        return HistoricalConfidence::fromLevel($level);
    }
}
