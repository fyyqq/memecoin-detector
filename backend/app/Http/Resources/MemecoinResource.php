<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\QualificationEvent;
use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the "30-Day Leaders" dashboard.
 *
 * Identity + peak state come from the {@see Token}; the current market fields
 * come from its **latest** {@see MarketSnapshot} (eager-loaded as
 * `latestSnapshot`); the `qualification_*` fields come from the token's
 * {@see HistoricalPeakEvidence} (eager-loaded as `historicalPeakEvidence`), or
 * are derived as CURRENT_OBSERVATION from `observed_peak_market_cap` when no
 * evidence row exists yet.
 *
 * Every row in this list qualifies on a VERIFIED / OBSERVED market cap:
 * `qualification_status` is always CURRENT_OBSERVATION or HISTORICAL_VERIFIED,
 * `qualification_peak_value` is always a real market cap (never an FDV estimate).
 * `observed_peak_market_cap` (OUR OWN snapshot peak) and `qualification_peak_value`
 * are deliberately reported as separate fields.
 *
 * @mixin Token
 */
class MemecoinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->latestSnapshot;
        $qualification = $this->qualification();
        $crossing = $this->crossing();

        return [
            'id' => $this->id,
            'chain_id' => $this->chain_id,
            'token_address' => $this->token_address,
            'name' => $this->name,
            'symbol' => $this->symbol,

            // Current market state — from the latest observation, not the Token.
            'current_market_cap' => $snapshot?->market_cap,
            'observed_peak_market_cap' => $this->observed_peak_market_cap,
            'observed_peak_market_cap_at' => $this->observed_peak_market_cap_at?->toIso8601String(),

            // How this token qualifies for the 30-day universe.
            'qualification_status' => $qualification['status'],
            'qualification_peak_value' => $qualification['peak_value'],
            'qualification_peak_at' => $qualification['peak_at'],
            'qualification_source' => $qualification['source'],
            'qualification_basis' => $qualification['basis'],

            // Step 20 — the "$5M crossing" (representative event: verified over
            // observed). null when no crossing has been recorded for this token.
            'qualification_crossed_at' => $crossing['crossed_at'],
            'qualification_crossing_type' => $crossing['type'],
            'recently_crossed' => $crossing['recently_crossed'],

            // Step 24 — the risk screen this row passed. Every MAIN LIST row is
            // LOWER or MEDIUM risk (HIGH / CRITICAL / UNKNOWN are on RISK WATCH).
            // "risk_summary" is a list of concise, pre-written phrases — never
            // dynamically generated prose, and never the word "safe".
            ...$this->riskFields(),

            'age_days' => $this->ageDays(),

            'liquidity_usd' => $snapshot?->liquidity_usd,
            'volume_h24' => $snapshot?->volume_h24,

            'primary_dex_id' => $snapshot?->primary_dex_id,
            'primary_pair_address' => $snapshot?->primary_pair_address,

            'data_source' => 'dexscreener',
            'last_observed_at' => $this->last_observed_at?->toIso8601String(),
        ];
    }

    /**
     * Step 24 risk fields. A MAIN LIST row always has a completed assessment
     * (the list query only returns risk-screen-passing tokens), but stay
     * defensive.
     *
     * @return array{risk_level:?string,risk_score:?int,data_completeness:?float,risk_summary:list<string>}
     */
    private function riskFields(): array
    {
        /** @var RiskAssessment|null $assessment */
        $assessment = $this->riskAssessment;

        if ($assessment === null) {
            return [
                'risk_level' => null,
                'risk_score' => null,
                'data_completeness' => null,
                'risk_summary' => [],
            ];
        }

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'none' => 4];

        $summary = ($assessment->relationLoaded('signals') ? $assessment->signals : collect())
            ->filter(fn (RiskSignal $s): bool => in_array($s->state, [RiskSignal::STATE_BAD, RiskSignal::STATE_MEASURED], true)
                && in_array($s->severity, [RiskSignal::SEVERITY_MEDIUM, RiskSignal::SEVERITY_HIGH, RiskSignal::SEVERITY_CRITICAL], true))
            ->sortBy(fn (RiskSignal $s): int => $rank[$s->severity] ?? 9)
            ->take(3)
            ->map(fn (RiskSignal $s): string => (string) $s->explanation)
            ->values()
            ->all();

        return [
            'risk_level' => $assessment->risk_level,
            'risk_score' => $assessment->risk_score,
            'data_completeness' => round((float) $assessment->data_completeness, 3),
            'risk_summary' => $summary,
        ];
    }

    /**
     * The qualifying evidence for this row — always a VERIFIED / OBSERVED market
     * cap (the list query only returns CURRENT_OBSERVATION and HISTORICAL_VERIFIED
     * tokens). Prefers the stored {@see HistoricalPeakEvidence}; falls back to
     * the mirrored Token columns.
     *
     * @return array{status:?string,peak_value:?float,peak_at:?string,source:?string,basis:?string}
     */
    private function qualification(): array
    {
        /** @var HistoricalPeakEvidence|null $evidence */
        $evidence = $this->historicalPeakEvidence;

        $min = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $max = (float) config('dexscreener.filters.observed_peak_market_cap_max_usd');

        if ($evidence !== null && $evidence->qualifies($min, $max)) {
            return [
                'status' => $evidence->status,
                'peak_value' => $evidence->peak_value_usd,
                'peak_at' => $evidence->peak_observed_at?->toIso8601String(),
                'source' => $evidence->evidence_source,
                'basis' => $evidence->evidence_basis,
            ];
        }

        if ($this->observed_peak_market_cap !== null
            && $this->observed_peak_market_cap >= $min
            && $this->observed_peak_market_cap < $max) {
            return [
                'status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
                'peak_value' => $this->observed_peak_market_cap,
                'peak_at' => $this->observed_peak_market_cap_at?->toIso8601String(),
                'source' => HistoricalPeakEvidence::SOURCE_DEXSCREENER,
                'basis' => HistoricalPeakEvidence::BASIS_CURRENT_MARKET_CAP,
            ];
        }

        // Defensive: derive HISTORICAL_VERIFIED from the mirrored columns when the
        // evidence relation is not loaded. `historical_peak_value` only ever
        // holds a verified/observed market cap.
        if ($this->historical_peak_status === HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED
            && $this->historical_peak_value !== null
            && $this->historical_peak_value >= $min
            && $this->historical_peak_value < $max) {
            return [
                'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
                'peak_value' => $this->historical_peak_value,
                'peak_at' => $this->historical_peak_value_at?->toIso8601String(),
                'source' => HistoricalPeakEvidence::SOURCE_COINGECKO,
                'basis' => HistoricalPeakEvidence::BASIS_MARKET_CAP,
            ];
        }

        return ['status' => null, 'peak_value' => null, 'peak_at' => null, 'source' => null, 'basis' => null];
    }

    /**
     * The token's representative "$5M crossing" (Step 20) — the strongest
     * recorded {@see QualificationEvent} (HISTORICAL_VERIFIED over
     * CURRENT_OBSERVATION). Reads the eager-loaded `qualificationEvents`
     * relation; all-null when no crossing has been recorded (the read API never
     * creates one).
     *
     * @return array{crossed_at:?string,type:?string,recently_crossed:bool}
     */
    private function crossing(): array
    {
        $event = $this->representativeQualificationEvent();

        if ($event === null) {
            return ['crossed_at' => null, 'type' => null, 'recently_crossed' => false];
        }

        $hours = (int) config('dexscreener.recent_crossing.hours');
        $cutoff = CarbonImmutable::now()->subHours($hours);

        return [
            'crossed_at' => $event->crossed_at?->toIso8601String(),
            'type' => $event->type,
            'recently_crossed' => $event->crossed_at !== null && $event->crossed_at->greaterThanOrEqualTo($cutoff),
        ];
    }

    /**
     * Age from earliest DEX pool creation to now (days, 2dp). NOT token deploy
     * time. Null when we never captured a pool-creation timestamp.
     */
    private function ageDays(): ?float
    {
        if ($this->earliest_pair_created_at === null) {
            return null;
        }

        $seconds = CarbonImmutable::now()->getTimestamp() - $this->earliest_pair_created_at->getTimestamp();

        return round($seconds / 86_400, 2);
    }
}
