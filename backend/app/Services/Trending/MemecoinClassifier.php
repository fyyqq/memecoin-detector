<?php

declare(strict_types=1);

namespace App\Services\Trending;

/**
 * Decides whether a trending candidate is an actual **memecoin** candidate — so
 * "Trending Now" shows newly-launched memecoins, not every token that happens to
 * sit inside a DexScreener trending narrative.
 *
 *   TRUE     — a strong meme signal fired (meme-narrative trending meta and/or a
 *              meme keyword in the name/symbol) and no non-meme signal
 *   FALSE    — a clear non-memecoin: stablecoin, wrapped asset, liquid-staking
 *              token, obvious infra / utility / governance token, or a major
 *              blue-chip
 *   UNKNOWN  — ambiguous: no strong meme signal, no non-meme signal
 *
 * Trending Now includes only TRUE. FALSE is excluded. UNKNOWN is excluded (the
 * spec: "if ambiguous, allow only if other memecoin signals are strong" — a
 * strong signal would have made it TRUE).
 *
 * Deterministic, config-driven (`config/trending.php` → `memecoin.*`). No AI.
 */
class MemecoinClassifier
{
    public const TRUE = 'TRUE';

    public const FALSE = 'FALSE';

    public const UNKNOWN = 'UNKNOWN';

    /** @var list<string> */
    private array $denySymbols;

    /** @var list<string> */
    private array $denyNamePatterns;

    /** @var list<string> */
    private array $memeMetaSlugs;

    /** @var list<string> */
    private array $utilityMetaSlugs;

    /** @var list<string> */
    private array $memeKeywords;

    public function __construct()
    {
        $this->denySymbols = $this->lowered(config('trending.memecoin.deny_symbols', []));
        $this->denyNamePatterns = $this->lowered(config('trending.memecoin.deny_name_patterns', []));
        $this->memeMetaSlugs = $this->lowered(config('trending.memecoin.meme_meta_slugs', []));
        $this->utilityMetaSlugs = $this->lowered(config('trending.memecoin.utility_meta_slugs', []));
        $this->memeKeywords = $this->lowered(config('trending.memecoin.meme_keywords', []));
    }

    public function classify(TrendingCandidate $candidate): string
    {
        if ($this->isDenied($candidate)) {
            return self::FALSE;
        }

        if ($this->hasStrongMemeSignal($candidate)) {
            return self::TRUE;
        }

        return self::UNKNOWN;
    }

    public function isEligibleForTrending(string $verdict): bool
    {
        return $verdict === self::TRUE;
    }

    private function isDenied(TrendingCandidate $candidate): bool
    {
        $symbol = mb_strtolower(trim((string) $candidate->symbol));
        $name = mb_strtolower(trim((string) $candidate->name));

        if ($symbol !== '' && in_array($symbol, $this->denySymbols, true)) {
            return true;
        }

        // Wrapped asset — "w" + a known base (weth, wbtc, wsol, wbnb, …). Kept
        // separate from the deny list so it also catches new chains.
        if (preg_match('/^w(eth|btc|sol|bnb|avax|matic|pol|ftm|hbar|near|xrp|trx|ada|dot|atom|ldo)$/', $symbol) === 1) {
            return true;
        }

        foreach ($this->denyNamePatterns as $pattern) {
            if ($pattern !== '' && str_contains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function hasStrongMemeSignal(TrendingCandidate $candidate): bool
    {
        // 1. Member of a clearly-meme trending narrative.
        foreach ($candidate->metaSlugs as $slug) {
            $slug = mb_strtolower(trim($slug));
            if ($slug !== '' && in_array($slug, $this->memeMetaSlugs, true)) {
                return true;
            }
        }

        // 2. Meme keyword in the name or symbol.
        $haystack = mb_strtolower(trim((string) $candidate->name.' '.(string) $candidate->symbol));
        foreach ($this->memeKeywords as $keyword) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function lowered($values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($v): string => mb_strtolower(trim((string) $v)),
            $values,
        ), static fn (string $v): bool => $v !== ''));
    }
}
