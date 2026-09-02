<?php

declare(strict_types=1);

namespace App\Services\Historical\Research;

/**
 * A source of HISTORICAL evidence for reconstructing a past "Monthly Top
 * Memecoins" bucket (Step 26).
 *
 * The capability model is MANDATORY: a provider declares exactly which
 * {@see HistoricalMetric}s it can answer via {@see supportsMetric()}. The
 * research service routes each metric only to providers that support it — a
 * provider is NEVER asked to fake an operation it cannot perform. A provider
 * that is asked for an unsupported metric anyway MUST return
 * {@see HistoricalMetricResult::unavailable()} (never throw, never invent).
 *
 * Phase 1 defines the contract only. Concrete providers (CoinGecko history,
 * GeckoTerminal history, the operator seed, internal snapshots) arrive in a
 * later phase. `web_research` stays OFF.
 *
 * Constraints every implementation must honour:
 *  - identity is `chain_id` + `token_address`, never a symbol alone;
 *  - a figure is `observed` / `reconstructed` / `estimate` — an estimate is
 *    ALWAYS labelled an estimate and is never a verified market cap;
 *  - a current figure is NEVER returned to represent a past month;
 *  - every failure degrades to an `unavailable` result, not an exception.
 */
interface HistoricalResearchProvider
{
    /** Stable id, e.g. `coingecko_history`, `geckoterminal_history`, `seed`, `internal_snapshots`. */
    public function name(): string;

    /** Whether this provider can answer the given metric AT ALL. */
    public function supportsMetric(HistoricalMetric $metric): bool;

    /**
     * Resolve / verify token identity for a chain + address (with optional
     * symbol / name hints). Returns a {@see HistoricalMetric::Identity} result
     * whose `metadata` carries the resolved `{chain_id, token_address, name,
     * symbol}`; `unavailable` when the identity cannot be confirmed.
     */
    public function searchToken(HistoricalResearchRequest $request): HistoricalMetricResult;

    /**
     * Fetch ONE historical metric for the request's token + month window.
     * MUST return {@see HistoricalMetricResult::unavailable()} when the metric
     * is unsupported, not found, or the provider fails — never an exception,
     * never a fabricated number.
     */
    public function getHistoricalMetric(HistoricalMetric $metric, HistoricalResearchRequest $request): HistoricalMetricResult;

    /**
     * Token metadata (name / symbol / decimals / total supply / image) — an
     * {@see HistoricalMetric::Identity} result with the detail in `metadata`.
     */
    public function getTokenMetadata(HistoricalResearchRequest $request): HistoricalMetricResult;

    /**
     * Historical OHLCV candles for the window — an {@see HistoricalMetric::Ohlcv}
     * result whose `metadata` carries the candle summary (`granularity`,
     * `candle_count`, `covered_days`, `peak_price`, …). `unavailable` when the
     * provider has no OHLCV for the window.
     */
    public function getHistoricalOhlcv(HistoricalResearchRequest $request): HistoricalMetricResult;
}
