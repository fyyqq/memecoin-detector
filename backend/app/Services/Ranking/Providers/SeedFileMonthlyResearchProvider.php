<?php

declare(strict_types=1);

namespace App\Services\Ranking\Providers;

use App\Models\MonthlyRanking;
use App\Services\Ranking\ChainBucket;
use App\Services\Ranking\MonthlyChampionResearchProvider;
use App\Services\Ranking\MonthlyResearchCandidate;
use App\Services\Ranking\MonthlyResearchContext;
use App\Services\Ranking\MonthlyResearchSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads verified historical-champion candidates from a curated JSON file
 * (`config('ranking.research.seed_path')`).
 *
 * This is the bridge between MANUAL internet research (an operator investigates
 * reputable historical market sources, resolves token identity, records
 * source URLs / claims / dates) and the deterministic pipeline. The file is
 * NEVER auto-generated from search-result snippets. Absent file / bad JSON =>
 * `[]` and the bucket stays `no_verified_result` honestly. Provide MULTIPLE
 * candidates per `(year, month, chain_bucket)` — they are ranked into a Top 3.
 *
 * File shape:
 * {
 *   "candidates": [
 *     {
 *       "year": 2026, "month": 1, "chain_bucket": "solana",
 *       "name": "...", "symbol": "...", "chain_id": "solana",
 *       "token_address": "...", "image_url": null,
 *       "baseline_market_cap": 6000000, "peak_market_cap": 45000000,
 *       "volume_usd": 12000000,
 *       "holder_count": 18000,            // real positive integer ONLY; omit / null = UNKNOWN
 *       "launch_date": "2025-12-20", "age_uncertain": false,
 *       "source_type": "best_supported_historical_performer",
 *       "confidence": "medium",
 *       "sources": [
 *         { "name": "CoinGecko", "url": "https://...", "claim": "...",
 *           "published_at": "2026-02-01", "credibility": "historical_provider" }
 *       ],
 *       "explanation": "why this is a top-3 performer"
 *     }
 *   ]
 * }
 */
class SeedFileMonthlyResearchProvider implements MonthlyChampionResearchProvider
{
    private bool $lastCallFailed = false;

    public function name(): string
    {
        return 'seed_file';
    }

    public function isAvailable(): bool
    {
        $path = $this->path();

        return $path !== null && is_file($path);
    }

    public function lastCallFailed(): bool
    {
        return $this->lastCallFailed;
    }

    /**
     * @return list<MonthlyResearchCandidate>
     */
    public function research(MonthlyResearchContext $context): array
    {
        $this->lastCallFailed = false;
        $path = $this->path();
        if ($path === null || ! is_file($path)) {
            return [];
        }

        try {
            $raw = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->lastCallFailed = true;
            Log::warning('Monthly champion seed file is not valid JSON', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }

        $rows = is_array($raw['candidates'] ?? null) ? $raw['candidates'] : [];
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((int) ($row['year'] ?? 0) !== $context->year() || (int) ($row['month'] ?? 0) !== $context->month()) {
                continue;
            }

            $chainId = mb_strtolower(trim((string) ($row['chain_id'] ?? '')));
            $bucket = mb_strtolower(trim((string) ($row['chain_bucket'] ?? '')));
            // The row's declared bucket AND the token's real chain must both
            // land in the bucket we're researching.
            if ($bucket !== $context->bucket || ChainBucket::forChain($chainId) !== $context->bucket) {
                continue;
            }

            $candidate = $this->toCandidate($row, $chainId);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function toCandidate(array $row, string $chainId): ?MonthlyResearchCandidate
    {
        $name = trim((string) ($row['name'] ?? ''));
        $symbol = trim((string) ($row['symbol'] ?? ''));
        if ($name === '' || $symbol === '' || $chainId === '') {
            return null; // entity identity not resolvable — never accept
        }

        $sources = [];
        foreach ((array) ($row['sources'] ?? []) as $s) {
            if (is_array($s)) {
                $sources[] = MonthlyResearchSource::fromArray($s);
            }
        }
        if ($sources === []) {
            return null; // a research candidate MUST carry at least one source
        }

        $sourceType = in_array($row['source_type'] ?? null, [
            MonthlyRanking::SOURCE_EXACT_DEXSCREENER_RANK,
            MonthlyRanking::SOURCE_BEST_SUPPORTED_HISTORICAL,
        ], true) ? (string) $row['source_type'] : MonthlyRanking::SOURCE_BEST_SUPPORTED_HISTORICAL;

        $confidence = in_array($row['confidence'] ?? null, [
            MonthlyRanking::CONFIDENCE_HIGH,
            MonthlyRanking::CONFIDENCE_MEDIUM,
            MonthlyRanking::CONFIDENCE_LOW,
        ], true) ? (string) $row['confidence'] : MonthlyRanking::CONFIDENCE_LOW;

        return new MonthlyResearchCandidate(
            name: mb_substr($name, 0, 120),
            symbol: mb_substr($symbol, 0, 64),
            chainId: $chainId,
            tokenAddress: isset($row['token_address']) && is_string($row['token_address']) && $row['token_address'] !== ''
                ? mb_substr($row['token_address'], 0, 128) : null,
            imageUrl: isset($row['image_url']) && is_string($row['image_url']) && $row['image_url'] !== ''
                ? mb_substr($row['image_url'], 0, 500) : null,
            baselineMarketCap: $this->num($row['baseline_market_cap'] ?? null),
            peakMarketCap: $this->num($row['peak_market_cap'] ?? null),
            volumeUsd: $this->num($row['volume_usd'] ?? null),
            holderCount: $this->holderCount($row['holder_count'] ?? null),
            launchDate: $this->date($row['launch_date'] ?? null),
            ageUncertain: filter_var($row['age_uncertain'] ?? false, FILTER_VALIDATE_BOOL),
            sourceType: $sourceType,
            suggestedConfidence: $confidence,
            sources: $sources,
            explanation: mb_substr(trim((string) ($row['explanation'] ?? '')), 0, 600),
        );
    }

    private function num(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v > 0.0 ? (float) $v : null;
    }

    /**
     * A historical holder count is used ONLY when it is a real positive integer.
     * Anything else (absent, `"UNKNOWN"`, 0, a string) is honestly UNKNOWN — it
     * is never coerced to a number.
     */
    private function holderCount(mixed $v): ?int
    {
        return is_int($v) && $v > 0 ? $v : (is_numeric($v) && ! is_string($v) && (int) $v > 0 ? (int) $v : null);
    }

    private function date(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($v);
        } catch (Throwable) {
            return null;
        }
    }

    private function path(): ?string
    {
        $p = config('ranking.research.seed_path');

        return is_string($p) && $p !== '' ? $p : null;
    }
}
