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
 *   1. discovery freshness — the discovery pipeline OBSERVED the token within
 *      `recent_crossing.discovery_freshness_hours`. We persist only
 *      `last_observed_at`, not which feed surfaced it, so this is honestly
 *      "recently observed by discovery" — never a claim the token is "trending".
 *   2. risk screen — reuses {@see MainListDecision} WITHOUT the main-list ≥ 72h
 *      maturity gate. A POSITIVE hard-failure (honeypot / cannot-buy /
 *      cannot-sell / mintable / CRITICAL / HIGH / recorded hard override)
 *      rejects on every chain. A RISK-UNKNOWN / unscreened / low-completeness
 *      result rejects only on a chain our security provider
 *      (`risk.goplus_chain_map`) covers; on an unsupported chain (e.g.
 *      `robinhood`) that outcome is expected and does not reject by itself
 *      (`recent_crossing.allow_unsupported_chain_risk_unknown`).
 *   3. holder participation — when a MEASURED `holder_count` risk signal
 *      exists, holders per $1M of CURRENT market cap must be ≥
 *      `min_holders_per_million_mcap` (calibrated to 25 — reference survivors
 *      sit at 552–3,484). A MISSING count rejects only when
 *      `require_holder_evidence` is true (default false — unsupported chains
 *      cannot produce one). Never a fabricated count.
 *   4. 24h volume vs CURRENT market cap — `volume_h24 / current_market_cap` ≥
 *      `min_volume_to_mcap_ratio` (calibrated to 0.01 — reference survivors
 *      0.035–0.75). Never FDV, never peak MC. High volume is never a reject.
 *   5. liquidity — `liquidity_usd` ≥ `risk.liquidity.min_total_usd` (the
 *      existing hard floor) AND ≥ current MC × `min_liquidity_to_mcap_ratio`
 *      (calibrated to 0.005 — reference survivors 0.016–0.32).
 *
 * The ratio floors were calibrated against a 9-token empirical reference set —
 * see docs/recently-crossed-calibration.md.
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
        if (! $decision->eligible && ! $this->riskFailureIsOnlyAnUnsupportedChainDataGap($token, $decision)) {
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
     * True when the risk screen only failed because the token's chain has no
     * security-provider coverage (RISK UNKNOWN / not screened / incomplete /
     * insufficient data) AND there is no POSITIVE hard-failure signal. On a
     * covered chain (`risk.goplus_chain_map`) every risk failure still rejects.
     */
    private function riskFailureIsOnlyAnUnsupportedChainDataGap(Token $token, MainListDecision $decision): bool
    {
        if (! (bool) config('dexscreener.recent_crossing.allow_unsupported_chain_risk_unknown', true)) {
            return false;
        }

        $dataGapReasons = ['not_screened', 'risk_unknown', 'screening_incomplete', 'insufficient_security_data'];

        // Any non-data-gap reason (risk_high / risk_critical / hard_filter:* /
        // too_young) means a real failure — never bypass.
        if ($decision->reasons === [] || array_diff($decision->reasons, $dataGapReasons) !== []) {
            return false;
        }

        $coveredChains = array_map('strval', array_keys((array) config('risk.goplus_chain_map', [])));

        return ! in_array((string) $token->chain_id, $coveredChains, true);
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
