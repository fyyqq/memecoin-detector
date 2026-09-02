<?php

declare(strict_types=1);

namespace App\Services\Historical;

use App\Models\RiskSignal;
use App\Models\Token;
use App\Services\Risk\MainListDecision;
use Carbon\CarbonImmutable;

/**
 * The deterministic quality screen for the "🔥 Recently Crossed $5M" dashboard
 * section (`GET /api/memecoins/recently-crossed`).
 *
 * PostgreSQL-only — reads the token's already-loaded `latestSnapshot` +
 * `riskAssessment.signals` + config. It NEVER calls DexScreener / CoinGecko /
 * GeckoTerminal / GoPlus and NEVER writes.
 *
 * The controller query already enforces: representative crossing inside the
 * 30-day window, age ≤ 30d, and a verified/observed peak in `[$5M, $1B)`. This
 * class adds the market-QUALITY gates, evaluated in order:
 *
 *   1. discovery freshness — the discovery pipeline observed the token within
 *      `recent_crossing.discovery_freshness_hours`. We do not persist which feed
 *      (trending meta / boost / profile) surfaced a token, so a fresh
 *      `last_observed_at` is the honest token-level "still being discovered"
 *      signal. A token that fell off every discovery feed goes stale here.
 *   2. risk screen — reuses {@see MainListDecision} (LOWER/MEDIUM, screening
 *      completed, data completeness OK, no CRITICAL/HIGH hard override — i.e.
 *      no honeypot / cannot-buy / cannot-sell / mintable / …), WITHOUT the
 *      main-list ≥ 72h maturity gate.
 *   3. holder participation — a MEASURED `holder_count` risk signal is required
 *      when `require_holder_evidence`; holders per $1M of CURRENT market cap
 *      must be ≥ `min_holders_per_million_mcap`. Never a fabricated count.
 *   4. 24h volume vs CURRENT market cap — `volume_h24 / current_market_cap` ≥
 *      `min_volume_to_mcap_ratio`. Never FDV, never peak MC. High volume is
 *      never a reject.
 *   5. liquidity — `liquidity_usd` ≥ `risk.liquidity.min_total_usd` (the
 *      existing hard floor) AND ≥ current MC × `min_liquidity_to_mcap_ratio`.
 *
 * This never changes qualification, `observed_peak_market_cap`, pump events,
 * evidence, or the risk assessment — it only decides list membership.
 */
class RecentlyCrossedQualifier
{
    public function evaluate(Token $token, CarbonImmutable $now): RecentlyCrossedDecision
    {
        $snapshot = $token->relationLoaded('latestSnapshot') ? $token->latestSnapshot : $token->latestSnapshot()->first();
        $currentMc = $snapshot?->market_cap;

        // 1. discovery freshness -------------------------------------------------
        $freshnessHours = (int) config('dexscreener.recent_crossing.discovery_freshness_hours', 48);
        if ($token->last_observed_at === null
            || $token->last_observed_at->lessThan($now->subHours($freshnessHours))) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_DISCOVERY_STALE);
        }

        // 2. risk screen (no maturity gate) ------------------------------------
        $decision = MainListDecision::for($token, $now, requireMaturity: false);
        if (! $decision->eligible) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_RISK_SCREEN_FAILED);
        }

        // A positive CURRENT market cap is required for the ratio gates below.
        // (A token dumped below $5M still qualifies — the peak rule is applied
        // in the controller query — but it must still have a live market cap.)
        if ($currentMc === null || $currentMc <= 0.0) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_VOLUME_TOO_THIN);
        }

        // 3. holder participation ---------------------------------------------
        $holderResult = $this->holderGate($token, (float) $currentMc);
        if ($holderResult !== null) {
            return RecentlyCrossedDecision::reject($holderResult);
        }

        // 4. 24h volume vs CURRENT market cap --------------------------------
        $volume = $snapshot?->volume_h24;
        if ($volume === null) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_VOLUME_MISSING);
        }
        $minVolumeRatio = (float) config('dexscreener.recent_crossing.min_volume_to_mcap_ratio', 0.001);
        if ((float) $volume <= 0.0 || (float) $volume / (float) $currentMc < $minVolumeRatio) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_VOLUME_TOO_THIN);
        }

        // 5. liquidity -------------------------------------------------------
        $liquidity = $snapshot?->liquidity_usd;
        if ($liquidity === null) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_LIQUIDITY_MISSING);
        }
        $absoluteFloor = (float) config('risk.liquidity.min_total_usd', 10_000.0);
        $relativeFloor = (float) $currentMc * (float) config('dexscreener.recent_crossing.min_liquidity_to_mcap_ratio', 0.001);
        if ((float) $liquidity <= 0.0 || (float) $liquidity < $absoluteFloor || (float) $liquidity < $relativeFloor) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_LIQUIDITY_TOO_THIN);
        }

        return RecentlyCrossedDecision::pass();
    }

    /**
     * @return string|null a reject reason, or null when the holder gate passes
     */
    private function holderGate(Token $token, float $currentMc): ?string
    {
        $requireEvidence = (bool) config('dexscreener.recent_crossing.require_holder_evidence', true);

        /** @var RiskSignal|null $signal */
        $signal = ($token->relationLoaded('riskAssessment') && $token->riskAssessment !== null
                && $token->riskAssessment->relationLoaded('signals'))
            ? $token->riskAssessment->signals->firstWhere('signal_key', 'holder_count')
            : null;

        $holderCount = ($signal !== null && $signal->state === RiskSignal::STATE_MEASURED && $signal->numeric_value !== null)
            ? (int) $signal->numeric_value
            : null;

        if ($holderCount === null || $holderCount <= 0) {
            return $requireEvidence ? RecentlyCrossedDecision::REASON_HOLDER_EVIDENCE_MISSING : null;
        }

        $minPerMillion = (float) config('dexscreener.recent_crossing.min_holders_per_million_mcap', 5.0);
        $perMillion = $holderCount / ($currentMc / 1_000_000.0);

        return $perMillion < $minPerMillion ? RecentlyCrossedDecision::REASON_HOLDER_ANOMALY : null;
    }
}
