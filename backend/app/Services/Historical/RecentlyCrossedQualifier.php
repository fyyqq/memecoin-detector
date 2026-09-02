<?php

declare(strict_types=1);

namespace App\Services\Historical;

use App\Models\MarketSnapshot;
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
 * class adds the market-QUALITY + RED-FLAG gates, evaluated in order:
 *
 *   1. discovery freshness — the discovery pipeline OBSERVED the token within
 *      `recent_crossing.discovery_freshness_hours`. Honestly "recently observed
 *      by discovery", never a claim the token is "trending".
 *   1b. optional pool-age floor — `recent_crossing.min_age_hours` (0 = off).
 *   2. unscreenable chain — a chain absent from `config('risk.goplus_chain_map')`
 *      cannot be contract-screened (no honeypot / mint / blacklist check), so it
 *      is not listed. HARD red flag.
 *   3. risk screen — reuses {@see MainListDecision} WITHOUT the ≥ 72h maturity
 *      gate. Any failure (honeypot / cannot-buy / cannot-sell / mintable /
 *      CRITICAL / HIGH / hard override / RISK UNKNOWN / incomplete) rejects.
 *      SOFT for a covered chain (the stamp survives — Post-30-Day shows current
 *      risk alongside).
 *   4. holder participation — when a MEASURED `holder_count` risk signal exists,
 *      holders per $1M of CURRENT market cap ≥ `min_holders_per_million_mcap`
 *      (25). A MISSING count rejects when `require_holder_evidence` (true).
 *   5. 24h volume vs CURRENT market cap ≥ `min_volume_to_mcap_ratio` (0.01).
 *   6. liquidity ≥ `risk.liquidity.min_total_usd` AND ≥ current MC ×
 *      `min_liquidity_to_mcap_ratio` (0.005).
 *   7. RED FLAG — momentum: |`price_change_h24`| ≤ `max_price_change_h24_pct`
 *      (250). A token still on a vertical (or that just dumped) is not listed
 *      until it settles. HARD red flag.
 *   8. RED FLAG — post-crossing collapse: NOT (our observed peak within
 *      `collapse_lookback_hours` AND current MC < peak × `collapse_floor_ratio`).
 *      pippo: $1,299 vs a $12.4M peak ~20 min earlier. A gentle decline weeks
 *      after the peak is still `COOLED`. HARD red flag.
 *
 * The ratio floors (4–6) were calibrated against a 9-token empirical reference
 * set; the red-flag gates (2, 7, 8) were added after the 2026-09 "pippo"
 * incident — see docs/recently-crossed-calibration.md.
 *
 * This never changes qualification, `observed_peak_market_cap`, pump events,
 * evidence, or the risk assessment — it only decides list membership.
 */
class RecentlyCrossedQualifier
{
    public function evaluate(Token $token, CarbonImmutable $now): RecentlyCrossedDecision
    {
        $snapshot = $this->latestSnapshot($token);
        $currentMc = $snapshot?->market_cap;

        // 1. discovery freshness -------------------------------------------------
        $freshnessHours = (int) config('dexscreener.recent_crossing.discovery_freshness_hours', 48);
        if ($token->last_observed_at === null
            || $token->last_observed_at->lessThan($now->subHours($freshnessHours))) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_DISCOVERY_STALE);
        }

        // 1b. optional hard pool-age floor (0 = disabled) ----------------------
        $minAgeHours = (int) config('dexscreener.recent_crossing.min_age_hours', 0);
        if ($minAgeHours > 0
            && ($token->earliest_pair_created_at === null
                || $token->earliest_pair_created_at->greaterThan($now->subHours($minAgeHours)))) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_TOO_YOUNG);
        }

        // 2. unscreenable chain (HARD) ----------------------------------------
        // No security-provider coverage => we cannot rule out a honeypot / mint
        // / blacklist, so we do not list it.
        if ($this->chainIsUnscreenable($token)) {
            return RecentlyCrossedDecision::reject(
                RecentlyCrossedDecision::REASON_RISK_SCREEN_FAILED,
                hardRedFlag: true,
            );
        }

        // 3. risk screen (no maturity gate) ---------------------------------
        if (! MainListDecision::for($token, $now, requireMaturity: false)->eligible) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_RISK_SCREEN_FAILED);
        }

        // A positive CURRENT market cap is required for the ratio gates below.
        // (A token dumped below $5M still qualifies — the peak rule is applied
        // in the controller query — but it must still have a live market cap.)
        if ($currentMc === null || $currentMc <= 0.0) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_VOLUME_TOO_THIN);
        }

        // 4. holder participation -------------------------------------------
        $holderResult = $this->holderGate($token, (float) $currentMc);
        if ($holderResult !== null) {
            return RecentlyCrossedDecision::reject($holderResult);
        }

        // 5. 24h volume vs CURRENT market cap ------------------------------
        $volume = $snapshot?->volume_h24;
        if ($volume === null) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_VOLUME_MISSING);
        }
        $minVolumeRatio = (float) config('dexscreener.recent_crossing.min_volume_to_mcap_ratio', 0.001);
        if ((float) $volume <= 0.0 || (float) $volume / (float) $currentMc < $minVolumeRatio) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_VOLUME_TOO_THIN);
        }

        // 6. liquidity ---------------------------------------------------
        $liquidity = $snapshot?->liquidity_usd;
        if ($liquidity === null) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_LIQUIDITY_MISSING);
        }
        $absoluteFloor = (float) config('risk.liquidity.min_total_usd', 10_000.0);
        $relativeFloor = (float) $currentMc * (float) config('dexscreener.recent_crossing.min_liquidity_to_mcap_ratio', 0.001);
        if ((float) $liquidity <= 0.0 || (float) $liquidity < $absoluteFloor || (float) $liquidity < $relativeFloor) {
            return RecentlyCrossedDecision::reject(RecentlyCrossedDecision::REASON_LIQUIDITY_TOO_THIN);
        }

        // 7. RED FLAG — momentum still vertical (HARD) --------------------
        if ($this->momentumAnomaly($snapshot)) {
            return RecentlyCrossedDecision::reject(
                RecentlyCrossedDecision::REASON_MOMENTUM_ANOMALY,
                hardRedFlag: true,
            );
        }

        // 8. RED FLAG — spiked-then-collapsed (HARD) ---------------------
        if ($this->postCrossingCollapse($token, (float) $currentMc, $now)) {
            return RecentlyCrossedDecision::reject(
                RecentlyCrossedDecision::REASON_POST_CROSSING_COLLAPSE,
                hardRedFlag: true,
            );
        }

        return RecentlyCrossedDecision::pass();
    }

    /**
     * A pure HARD-red-flag check, independent of the soft gates. Used ONLY by
     * {@see RecentlyCrossedApprovalMarker}'s revocation pass to decide whether
     * an existing "previously approved" stamp should be cleared. Returns the
     * reason code, or null when the token trips no hard red flag.
     */
    public function redFlag(Token $token, CarbonImmutable $now): ?string
    {
        if ($this->chainIsUnscreenable($token)) {
            return RecentlyCrossedDecision::REASON_RISK_SCREEN_FAILED;
        }

        $snapshot = $this->latestSnapshot($token);

        if ($this->momentumAnomaly($snapshot)) {
            return RecentlyCrossedDecision::REASON_MOMENTUM_ANOMALY;
        }

        if ($snapshot?->market_cap !== null
            && $this->postCrossingCollapse($token, (float) $snapshot->market_cap, $now)) {
            return RecentlyCrossedDecision::REASON_POST_CROSSING_COLLAPSE;
        }

        return null;
    }

    private function latestSnapshot(Token $token): ?MarketSnapshot
    {
        return $token->relationLoaded('latestSnapshot')
            ? $token->latestSnapshot
            : $token->latestSnapshot()->first();
    }

    /**
     * True when the token's chain has no security-provider coverage
     * (`config('risk.goplus_chain_map')` is the authoritative covered-chains
     * list — e.g. `robinhood` is absent).
     */
    private function chainIsUnscreenable(Token $token): bool
    {
        $covered = array_map('strval', array_keys((array) config('risk.goplus_chain_map', [])));

        return ! in_array((string) $token->chain_id, $covered, true);
    }

    /**
     * RED FLAG — the token is still on a vertical (up or crashing). A `null`
     * 24h change is a data gap, not a red flag.
     */
    private function momentumAnomaly(?MarketSnapshot $snapshot): bool
    {
        $change = $snapshot?->price_change_h24;
        if ($change === null) {
            return false;
        }

        $max = (float) config('dexscreener.recent_crossing.max_price_change_h24_pct', 250.0);

        return abs((float) $change) > $max;
    }

    /**
     * RED FLAG — our OWN observed peak was reached very recently AND the current
     * market cap has since collapsed to a small fraction of it. A decline that
     * started long after the peak is a trend, not a rug, and stays `COOLED`.
     */
    private function postCrossingCollapse(Token $token, float $currentMc, CarbonImmutable $now): bool
    {
        $peak = $token->observed_peak_market_cap;
        $peakAt = $token->observed_peak_market_cap_at;
        if ($peak === null || $peak <= 0.0 || $peakAt === null) {
            return false;
        }

        $lookbackHours = (int) config('dexscreener.recent_crossing.collapse_lookback_hours', 72);
        if ($peakAt->lessThan($now->subHours($lookbackHours))) {
            return false;
        }

        $floorRatio = (float) config('dexscreener.recent_crossing.collapse_floor_ratio', 0.35);

        return $currentMc < (float) $peak * $floorRatio;
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
