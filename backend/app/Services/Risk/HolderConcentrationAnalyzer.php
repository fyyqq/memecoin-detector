<?php

declare(strict_types=1);

namespace App\Services\Risk;

/**
 * Turns GoPlus (+ GeckoTerminal) holder data into effective concentration
 * numbers (Step 24).
 *
 * Mandatory exclusions before any concentration figure: burn / dead /
 * incinerator addresses, LP-pair / AMM-pool contracts (EVM `pairAddress`,
 * GoPlus `dex[].id`), locked holders, and any holder GoPlus tags as
 * infrastructure. On Solana the top "holder" is almost always the AMM pool —
 * GoPlus keys Solana holders by `account` (case-sensitive base58) and gives no
 * `is_contract`, so the pool must be matched against `dex[].id`. GeckoTerminal
 * is a magnitude cross-check only (no addresses, cannot exclude LP).
 */
class HolderConcentrationAnalyzer
{
    /** @var list<string> lowercased */
    private array $burnAddresses;

    /** @var list<string> lowercased */
    private array $infraTags;

    private float $contractExcludePct;

    public function __construct()
    {
        $this->burnAddresses = array_map('mb_strtolower', (array) config('risk.holders.burn_addresses', []));
        $this->infraTags = array_map('mb_strtolower', (array) config('risk.holders.infrastructure_tags', []));
        $this->contractExcludePct = (float) config('risk.holders.contract_exclude_pct', 0.30);
    }

    /**
     * @param  list<string>  $poolAddresses  LP-pair / pool addresses (any casing) to exclude
     */
    public function analyze(
        GoPlusSecurityLookup $goplus,
        GeckoTerminalInfoLookup $gt,
        array $poolAddresses,
        ?float $currentMarketCap,
    ): HolderConcentration {
        // Exclusion set — GoPlus `dex[].id` pools + the caller's pool list.
        // Kept in BOTH raw and lowercased form (Solana base58 is case-sensitive;
        // EVM is not).
        $exclude = [];
        foreach ([...$poolAddresses, ...$goplus->dexPoolAddresses()] as $addr) {
            if ($addr === '') {
                continue;
            }
            $exclude[$addr] = true;
            $exclude[mb_strtolower($addr)] = true;
        }

        $creatorAddresses = $this->creatorAddresses($goplus);

        $holderCount = $goplus->holderCount() ?? $gt->holderCount();
        $perMillion = null;
        if ($holderCount !== null && $currentMarketCap !== null && $currentMarketCap > 0.0) {
            $perMillion = round($holderCount / ($currentMarketCap / 1_000_000), 2);
        }

        $creatorPct = $this->fraction($goplus->creatorPercent());
        $ownerPct = $this->fraction($goplus->ownerPercent());

        $effective = [];
        $excluded = 0;
        foreach ($goplus->holders() as $holder) {
            $raw = (string) ($holder['account'] ?? $holder['address'] ?? '');
            $lower = mb_strtolower($raw);
            $tag = mb_strtolower((string) ($holder['tag'] ?? ''));
            $isContract = ($holder['is_contract'] ?? null) === 1 || ($holder['is_contract'] ?? null) === '1';
            $isLocked = ($holder['is_locked'] ?? null) === 1 || ($holder['is_locked'] ?? null) === '1';
            $percent = is_numeric($holder['percent'] ?? null) ? (float) $holder['percent'] : null;
            if ($percent === null) {
                continue;
            }

            // Solana: infer creator % from the holders list when GoPlus gives no
            // creator_percent field.
            if ($creatorPct === null && $raw !== '' && isset($creatorAddresses[$raw])) {
                $creatorPct = $this->fraction($percent);
            }

            if ($this->isExcluded($raw, $lower, $tag, $isContract, $isLocked, $percent, $exclude)) {
                $excluded++;

                continue;
            }

            $effective[] = $percent;
        }

        rsort($effective);

        $top1 = $this->fraction($effective[0] ?? null);
        $top5 = $this->sumFraction(array_slice($effective, 0, 5));
        $top10 = $this->sumFraction(array_slice($effective, 0, 10));

        if ($top10 === null) {
            $top10 = $gt->top10Fraction();
        }

        $available = $holderCount !== null || $top1 !== null || $top10 !== null
            || $creatorPct !== null || $ownerPct !== null;

        return new HolderConcentration(
            available: $available,
            holderCount: $holderCount,
            holdersPerMillionMc: $perMillion,
            top1EffectivePct: $top1,
            top5EffectivePct: $top5,
            top10EffectivePct: $top10,
            creatorPct: $creatorPct,
            ownerPct: $ownerPct,
            excludedHolders: $excluded,
            source: $goplus->holders() !== [] ? 'goplus' : ($gt->top10Fraction() !== null ? 'geckoterminal' : null),
        );
    }

    /**
     * @param  array<string,bool>  $exclude
     */
    private function isExcluded(string $raw, string $lower, string $tag, bool $isContract, bool $isLocked, float $percent, array $exclude): bool
    {
        if ($lower !== '' && in_array($lower, $this->burnAddresses, true)) {
            return true;
        }
        if (($raw !== '' && isset($exclude[$raw])) || ($lower !== '' && isset($exclude[$lower]))) {
            return true;
        }
        if ($isLocked) {
            return true;
        }
        if ($tag !== '') {
            foreach ($this->infraTags as $needle) {
                if ($needle !== '' && str_contains($tag, $needle)) {
                    return true;
                }
            }
        }

        // A CONTRACT holding a very large share is virtually always
        // infrastructure — an AMM pool / vault / bridge / staking contract, not
        // an individual whale. GoPlus / GeckoTerminal often leave the pool
        // untagged. The DEPLOYER's own holding is scored separately as
        // `creator_pct`.
        if ($isContract && $percent >= $this->contractExcludePct) {
            return true;
        }

        return false;
    }

    /**
     * Deployer / creator addresses from the GoPlus response (EVM
     * `creator_address`, Solana `creators[]`).
     *
     * @return array<string,bool>
     */
    private function creatorAddresses(GoPlusSecurityLookup $goplus): array
    {
        $out = [];
        $creator = $goplus->string('creator_address');
        if ($creator !== null) {
            $out[$creator] = true;
        }
        $creators = $goplus->raw['creators'] ?? [];
        if (is_array($creators)) {
            foreach ($creators as $c) {
                if (is_array($c) && is_string($c['address'] ?? null) && $c['address'] !== '') {
                    $out[$c['address']] = true;
                }
            }
        }

        return $out;
    }

    /** GoPlus percents are already fractions (0.42 == 42%). Clamp defensively. */
    private function fraction(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return round(max(0.0, min(1.0, $value)), 4);
    }

    /**
     * @param  list<float>  $values
     */
    private function sumFraction(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(max(0.0, min(1.0, array_sum($values))), 4);
    }
}
