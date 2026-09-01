<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\RiskAssessment;
use App\Models\RiskSignal;

/**
 * Deterministic risk-signal evaluation (Step 24). Pure function of a
 * {@see TokenRiskContext} — no queries, no HTTP, no AI.
 *
 * Produces one {@see RiskSignalDraft} per signal, each tri-state
 * (MEASURED / BAD / UNKNOWN / NOT_AVAILABLE). Hard-flag signals carry
 * `hardOverride` + `hardOverrideLevel` so {@see RiskScoreCalculator} can raise
 * the level regardless of the numeric score — and the UI can always show which
 * flag triggered.
 */
class RiskSignalEvaluator
{
    /** @return list<RiskSignalDraft> */
    public function evaluate(TokenRiskContext $ctx): array
    {
        return [
            ...$this->ageSignals($ctx),
            ...$this->marketStructureSignals($ctx),
            ...$this->pumpDumpSignals($ctx),
            ...$this->liquiditySignals($ctx),
            ...$this->holderSignals($ctx),
            ...$this->exitSafetySignals($ctx),
            ...$this->contractSecuritySignals($ctx),
        ];
    }

    // --- AGE ---------------------------------------------------------------

    /** @return list<RiskSignalDraft> */
    private function ageSignals(TokenRiskContext $ctx): array
    {
        $g = RiskSignal::GROUP_AGE;
        $out = [];

        $ageHours = $ctx->ageHours();
        $minAge = (int) config('risk.main_list.min_age_hours', 72);

        if ($ageHours === null) {
            $out[] = RiskSignalDraft::unknown('token_age_hours', $g, 'Pool creation time not recorded.', 'internal');
        } else {
            $tooYoung = $ageHours < $minAge;
            // Score contribution: full at age 0, zero at 2x the maturity gate.
            $contribution = max(0.0, min(1.0, 1.0 - ($ageHours / ($minAge * 2))));
            $out[] = new RiskSignalDraft(
                key: 'token_age_hours',
                group: $g,
                state: RiskSignal::STATE_MEASURED,
                value: round($ageHours, 1).'h',
                numericValue: round($ageHours, 1),
                unit: 'hours',
                severity: $tooYoung ? RiskSignal::SEVERITY_MEDIUM : RiskSignal::SEVERITY_NONE,
                source: 'internal',
                sourceCheckedAt: $ctx->now,
                explanation: $tooYoung
                    ? "Token is younger than the {$minAge}h main-list maturity minimum."
                    : 'Token has cleared the main-list maturity minimum.',
                scoreContribution: $contribution > 0 ? $contribution : null,
            );
        }

        // Age / market-cap heuristic warning bands (soft — NOT proof of manipulation).
        $mc = $ctx->currentMarketCap();
        /** @var list<array{0:int,1:int}> $bands */
        $bands = config('risk.age_market_cap_bands', []);
        if ($ageHours !== null && $mc !== null) {
            $tripped = null;
            foreach ($bands as [$maxAgeHours, $minMc]) {
                if ($ageHours <= $maxAgeHours && $mc >= $minMc) {
                    $tripped = [$maxAgeHours, $minMc];
                    break;
                }
            }
            if ($tripped !== null) {
                $out[] = RiskSignalDraft::measured(
                    'market_cap_for_age', $g,
                    '$'.number_format($mc / 1_000_000, 1).'M at '.round($ageHours).'h',
                    round($mc, 0), 'usd',
                    RiskSignal::SEVERITY_MEDIUM, 'internal', $ctx->now,
                    'Market cap is unusually large for the token age — a heuristic warning, not proof of manipulation.',
                    0.7,
                );
            } else {
                $out[] = RiskSignalDraft::measured(
                    'market_cap_for_age', $g, 'within normal band', null, null,
                    RiskSignal::SEVERITY_NONE, 'internal', $ctx->now,
                    'Market cap relative to age is within the normal band.',
                );
            }
        } else {
            $out[] = RiskSignalDraft::unknown('market_cap_for_age', $g, 'Age or current market cap unavailable.', 'internal');
        }

        return $out;
    }

    // --- MARKET STRUCTURE -----------------------------------------------------

    /** @return list<RiskSignalDraft> */
    private function marketStructureSignals(TokenRiskContext $ctx): array
    {
        $g = RiskSignal::GROUP_MARKET_STRUCTURE;
        $out = [];
        $snap = $ctx->latestSnapshot;

        // Volume / liquidity turnover — heuristic bands, NEVER "10x = scam".
        $vol = $snap?->volume_h24;
        $liq = $snap?->liquidity_usd;
        if ($vol !== null && $liq !== null && $liq > 0.0) {
            $ratio = $vol / $liq;
            [$b2, $b5, $b10] = config('risk.liquidity.turnover_bands', [2.0, 5.0, 10.0]);
            [$sev, $contrib, $text] = match (true) {
                $ratio > $b10 => [RiskSignal::SEVERITY_HIGH, 0.9, 'Very high turnover relative to available liquidity.'],
                $ratio > $b5 => [RiskSignal::SEVERITY_MEDIUM, 0.55, 'Hot turnover relative to available liquidity — worth watching.'],
                $ratio > $b2 => [RiskSignal::SEVERITY_LOW, 0.25, 'Active turnover relative to available liquidity.'],
                default => [RiskSignal::SEVERITY_NONE, 0.0, 'Turnover relative to liquidity looks organic.'],
            };
            $out[] = RiskSignalDraft::measured(
                'volume_liquidity_ratio', $g,
                round($ratio, 2).'x', round($ratio, 3), 'ratio',
                $sev, 'internal', $ctx->now, $text, $contrib > 0 ? $contrib : null,
            );
        } else {
            $out[] = RiskSignalDraft::unknown('volume_liquidity_ratio', $g, '24h volume or liquidity unavailable.', 'internal');
        }

        // Buy / sell balance (soft). sells > buys is NOT scam proof.
        $buys = $snap?->buys_h24;
        $sells = $snap?->sells_h24;
        if ($buys !== null && $sells !== null && ($buys + $sells) > 0) {
            $buyShare = $buys / ($buys + $sells);
            $sev = $buyShare < 0.3 ? RiskSignal::SEVERITY_LOW : RiskSignal::SEVERITY_NONE;
            $out[] = RiskSignalDraft::measured(
                'buy_share', $g,
                round($buyShare * 100, 1).'%', round($buyShare, 3), 'fraction',
                $sev, 'internal', $ctx->now,
                $buyShare < 0.3
                    ? 'Sell-dominant 24h flow — a soft signal, not proof of anything.'
                    : 'Buy/sell flow is broadly balanced.',
                $buyShare < 0.3 ? 0.3 : null,
            );
        } else {
            $out[] = RiskSignalDraft::unknown('buy_share', $g, '24h buy/sell counts unavailable.', 'internal');
        }

        // Top-trader per-wallet data — permanently unavailable from a free
        // official API (Step 23). Never inferred, contributes 0.
        $out[] = RiskSignalDraft::notAvailable(
            'top_trader_analysis', $g,
            'Per-wallet bought/sold data is not available from any free official API and is never inferred.',
        );

        // Community takeover — contextual only, not a fraud classification. Not
        // fetched in this pass; kept as a non-applicable placeholder.
        $out[] = RiskSignalDraft::unknown(
            'community_takeover', $g,
            'Community-takeover context not checked in this screening pass.',
            'internal', applicable: false,
        );

        return $out;
    }

    // --- PUMP / DUMP --------------------------------------------------------

    /** @return list<RiskSignalDraft> */
    private function pumpDumpSignals(TokenRiskContext $ctx): array
    {
        $g = RiskSignal::GROUP_PUMP_DUMP;
        $shape = $ctx->chartShape;

        if (! $shape->sufficientHistory) {
            return [RiskSignalDraft::unknown(
                'pump_dump_shape', $g,
                'Insufficient observation history to assess pump-dump shape.',
                'internal',
            )];
        }

        $out = [];
        $crashAt = (float) config('risk.pump_dump.crash_drawdown_at', 0.70);
        $drawdown = ($shape->peakToCurrentDrawdownPct ?? 0.0) / 100.0;

        // Completed round-trip crash — a HARD pump-dump failure for the MAIN
        // LIST when it is a round trip AND a severe drawdown AND volume collapse.
        $hardCrash = $shape->roundTrip && $drawdown >= $crashAt && $shape->volumeCollapse;
        if ($hardCrash) {
            $out[] = RiskSignalDraft::bad(
                'round_trip_crash', $g,
                round($drawdown * 100).'% from peak', round($drawdown, 3),
                RiskSignal::SEVERITY_HIGH, 'internal', $ctx->now,
                'Completed pump-and-dump shape: large run-up, >'.round($crashAt * 100).'% retrace from peak, and collapsed volume.',
                1.0, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH,
            );
        } elseif ($shape->roundTrip || $shape->severeShortPumpThenCollapse) {
            $out[] = RiskSignalDraft::measured(
                'round_trip_crash', $g,
                round($drawdown * 100).'% from peak', round($drawdown, 3), 'fraction',
                RiskSignal::SEVERITY_MEDIUM, 'internal', $ctx->now,
                'Pump-then-retrace shape present — contributes to pump-dump risk, not a scam label.',
                0.6,
            );
        } else {
            $out[] = RiskSignalDraft::measured(
                'round_trip_crash', $g, 'no completed round trip', null, null,
                RiskSignal::SEVERITY_NONE, 'internal', $ctx->now,
                'No completed pump-and-dump round trip in the observed history.',
            );
        }

        // Peak-to-current drawdown (soft, informational).
        $out[] = RiskSignalDraft::measured(
            'peak_to_current_drawdown', $g,
            round($drawdown * 100, 1).'%', round($drawdown, 3), 'fraction',
            $drawdown >= 0.5 ? RiskSignal::SEVERITY_MEDIUM : ($drawdown >= 0.3 ? RiskSignal::SEVERITY_LOW : RiskSignal::SEVERITY_NONE),
            'internal', $ctx->now,
            'Current market cap is '.round($drawdown * 100).'% below the observed peak.',
            $drawdown >= 0.3 ? min(1.0, $drawdown) : null,
        );

        return $out;
    }

    // --- LIQUIDITY --------------------------------------------------------

    /** @return list<RiskSignalDraft> */
    private function liquiditySignals(TokenRiskContext $ctx): array
    {
        $g = RiskSignal::GROUP_LIQUIDITY;
        $out = [];
        $ls = $ctx->liquidity;
        $minTotal = (float) config('risk.liquidity.min_total_usd', 10_000.0);

        // Prefer the multi-pool probe; fall back to the latest snapshot's single
        // liquidity figure.
        $totalLiquidity = $ls->available && $ls->totalLiquidityUsd > 0.0
            ? $ls->totalLiquidityUsd
            : $ctx->latestSnapshot?->liquidity_usd;

        if ($totalLiquidity === null) {
            $out[] = RiskSignalDraft::unknown('total_liquidity', $g, 'No liquidity figure available.', 'internal');
        } elseif ($totalLiquidity < $minTotal) {
            $out[] = RiskSignalDraft::bad(
                'total_liquidity', $g,
                '$'.number_format($totalLiquidity, 0), $totalLiquidity,
                RiskSignal::SEVERITY_CRITICAL, $ls->available ? 'dexscreener' : 'internal', $ctx->now,
                'No usable liquidity — the token effectively has no market.',
                1.0, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH,
            );
        } else {
            $out[] = RiskSignalDraft::measured(
                'total_liquidity', $g,
                '$'.number_format($totalLiquidity, 0), $totalLiquidity, 'usd',
                RiskSignal::SEVERITY_NONE, $ls->available ? 'dexscreener' : 'internal', $ctx->now,
                'Total pooled liquidity across observed pools.',
            );
        }

        // Pool / DEX spread. Multiple pools REDUCE concentration risk — they
        // never mean "safe". A single DEEP pool is only a soft concern; a
        // single THIN pool with no LP-lock/burn evidence is a hard
        // single-point-of-failure.
        if ($ls->available) {
            $thinUsd = (float) config('risk.liquidity.thin_total_usd', 50_000.0);
            $lpLocked = $this->lpLockedFraction($ctx->goplus);
            $lpKnown = $ctx->goplus->lpHolders() !== [];
            $lpSafe = $lpLocked >= (float) config('risk.liquidity.lp_locked_safe_at', 0.50);
            $singlePoint = $ls->singlePool || $ls->poolCount <= 1;
            $effectiveLiquidity = $ls->totalLiquidityUsd > 0.0 ? $ls->totalLiquidityUsd : ($ctx->latestSnapshot?->liquidity_usd ?? 0.0);

            $hardLpFail = $singlePoint && $effectiveLiquidity < $thinUsd && ! $lpSafe;

            if ($hardLpFail) {
                $out[] = RiskSignalDraft::bad('pool_structure', $g, $ls->poolCount.' thin pool(s)', (float) $ls->poolCount,
                    RiskSignal::SEVERITY_HIGH, 'dexscreener', $ctx->now,
                    'Effectively a single thin pool with no LP-lock/burn evidence — one liquidity pull could end the market.',
                    1.0, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH);
            } elseif ($singlePoint) {
                $out[] = RiskSignalDraft::measured('pool_structure', $g,
                    $ls->poolCount.' pool(s) / '.$ls->dexCount.' DEX(es)', (float) $ls->poolCount, 'pools',
                    RiskSignal::SEVERITY_MEDIUM, 'dexscreener', $ctx->now,
                    $lpSafe
                        ? 'Liquidity concentrated in one pool (a single point of failure), but LP tokens are locked/burned.'
                        : ($lpKnown
                            ? 'Liquidity concentrated in one pool with withdrawable LP — a single point of failure.'
                            : 'Liquidity concentrated in one pool; LP-lock status unavailable on this chain.'),
                    0.55);
            } else {
                $out[] = RiskSignalDraft::measured('pool_structure', $g,
                    $ls->poolCount.' pool(s) / '.$ls->dexCount.' DEX(es)', (float) $ls->poolCount, 'pools',
                    RiskSignal::SEVERITY_LOW, 'dexscreener', $ctx->now,
                    'Liquidity spread across multiple pools/DEXes — a risk-reduction signal, not a guarantee.',
                    0.15);
            }
        } else {
            $out[] = RiskSignalDraft::unknown('pool_structure', $g, 'DexScreener pair list unavailable — pool/DEX spread unknown.', 'dexscreener');
        }

        // LP lock / burn.
        $out[] = $this->lpLockSignal($ctx);

        return $out;
    }

    private function lpLockedFraction(GoPlusSecurityLookup $goplus): float
    {
        $locked = 0.0;
        foreach ($goplus->lpHolders() as $lp) {
            $isLocked = ($lp['is_locked'] ?? null) === 1 || ($lp['is_locked'] ?? null) === '1';
            $percent = is_numeric($lp['percent'] ?? null) ? (float) $lp['percent'] : 0.0;
            $tag = mb_strtolower((string) ($lp['tag'] ?? ''));
            $address = mb_strtolower((string) ($lp['address'] ?? ''));
            $isBurn = in_array($address, array_map('mb_strtolower', (array) config('risk.holders.burn_addresses', [])), true);
            if ($isLocked || $isBurn || str_contains($tag, 'lock') || str_contains($tag, 'burn')) {
                $locked += $percent;
            }
        }

        return min(1.0, $locked);
    }

    private function lpLockSignal(TokenRiskContext $ctx): RiskSignalDraft
    {
        $g = RiskSignal::GROUP_LIQUIDITY;
        $lps = $ctx->goplus->lpHolders();

        if ($lps === []) {
            return RiskSignalDraft::unknown(
                'lp_lock', $g,
                'LP-holder / lock data unavailable — "LP locked" is never inferred from liquidity merely existing.',
                'goplus',
            );
        }

        $locked = $this->lpLockedFraction($ctx->goplus);
        $safeAt = (float) config('risk.liquidity.lp_locked_safe_at', 0.50);

        return RiskSignalDraft::measured(
            'lp_lock', $g,
            round($locked * 100).'% locked/burned', round($locked, 3), 'fraction',
            $locked >= $safeAt ? RiskSignal::SEVERITY_NONE : RiskSignal::SEVERITY_MEDIUM,
            'goplus', $ctx->now,
            $locked >= $safeAt
                ? 'A majority of LP tokens are locked or burned (recognised lockers only).'
                : 'Less than half of LP tokens are in a recognised locker/burn — withdrawable liquidity risk.',
            $locked >= $safeAt ? null : 0.6,
        );
    }

    // --- HOLDER DISTRIBUTION -----------------------------------------------

    /** @return list<RiskSignalDraft> */
    private function holderSignals(TokenRiskContext $ctx): array
    {
        $g = RiskSignal::GROUP_HOLDER_DISTRIBUTION;
        $out = [];
        $hc = $ctx->holders;
        $cfg = config('risk.holders');

        if (! $hc->available) {
            $out[] = RiskSignalDraft::unknown('holder_distribution', $g, 'Holder data unavailable from GoPlus / GeckoTerminal — concentration not assessed.', 'goplus');

            return $out;
        }

        // Holder count / holders per $1M MC (context, not a hard rule).
        if ($hc->holderCount !== null) {
            $perM = $hc->holdersPerMillionMc;
            $ref = (float) ($cfg['per_million_reference'] ?? 50.0);
            $thin = $perM !== null && $perM < $ref;
            $out[] = RiskSignalDraft::measured(
                'holder_count', $g,
                number_format($hc->holderCount).($perM !== null ? " ({$perM}/\$1M)" : ''),
                (float) $hc->holderCount, 'holders',
                $thin ? RiskSignal::SEVERITY_LOW : RiskSignal::SEVERITY_NONE,
                $hc->source, $ctx->now,
                $thin
                    ? 'Few holders relative to market cap — a thin-distribution warning, not a hard rule.'
                    : 'Holder count relative to market cap is in a normal range.',
                $thin ? 0.4 : null,
            );
        } else {
            $out[] = RiskSignalDraft::unknown('holder_count', $g, 'Holder count unavailable.', 'goplus');
        }

        // Effective top-1 concentration (LP/burn/CEX/locker excluded).
        $top1 = $hc->top1EffectivePct;
        if ($top1 !== null) {
            $crit = (float) ($cfg['top1_critical_at'] ?? 0.50);
            $high = (float) ($cfg['top1_high_at'] ?? 0.35);
            $warn = (float) ($cfg['top1_warning_at'] ?? 0.20);
            if ($top1 >= $crit) {
                $out[] = RiskSignalDraft::bad('top1_effective_pct', $g, round($top1 * 100, 1).'%', $top1, RiskSignal::SEVERITY_CRITICAL, $hc->source, $ctx->now,
                    'A single non-infrastructure wallet holds an extreme share of effective supply.', 1.0, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH);
            } elseif ($top1 >= $high) {
                $out[] = RiskSignalDraft::bad('top1_effective_pct', $g, round($top1 * 100, 1).'%', $top1, RiskSignal::SEVERITY_HIGH, $hc->source, $ctx->now,
                    'One wallet holds a large share of effective supply (LP/burn/CEX excluded).', 0.9, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH);
            } else {
                $out[] = RiskSignalDraft::measured('top1_effective_pct', $g, round($top1 * 100, 1).'%', $top1, 'fraction',
                    $top1 >= $warn ? RiskSignal::SEVERITY_MEDIUM : RiskSignal::SEVERITY_NONE, $hc->source, $ctx->now,
                    'Largest non-infrastructure holder share of effective supply.',
                    $top1 >= $warn ? 0.5 : null);
            }
        } else {
            $out[] = RiskSignalDraft::unknown('top1_effective_pct', $g, 'Top-holder list unavailable — concentration not computed.', 'goplus');
        }

        // Top-5 / top-10 (soft).
        foreach (['top5_effective_pct' => $hc->top5EffectivePct, 'top10_effective_pct' => $hc->top10EffectivePct] as $key => $val) {
            if ($val !== null) {
                $out[] = RiskSignalDraft::measured($key, $g, round($val * 100, 1).'%', $val, 'fraction',
                    $val >= 0.7 ? RiskSignal::SEVERITY_MEDIUM : ($val >= 0.5 ? RiskSignal::SEVERITY_LOW : RiskSignal::SEVERITY_NONE),
                    $hc->source, $ctx->now, 'Cumulative effective concentration of the largest holders.',
                    $val >= 0.5 ? min(0.8, $val) : null);
            } else {
                $out[] = RiskSignalDraft::unknown($key, $g, 'Holder list too short to compute.', 'goplus');
            }
        }

        // Creator concentration.
        $creator = $hc->creatorPct;
        if ($creator !== null) {
            $high = (float) ($cfg['creator_high_at'] ?? 0.20);
            $warn = (float) ($cfg['creator_warning_at'] ?? 0.10);
            if ($creator >= $high) {
                $out[] = RiskSignalDraft::bad('creator_pct', $g, round($creator * 100, 1).'%', $creator, RiskSignal::SEVERITY_HIGH, 'goplus', $ctx->now,
                    'The deployer holds a large share of supply.', 0.9, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH);
            } else {
                $out[] = RiskSignalDraft::measured('creator_pct', $g, round($creator * 100, 1).'%', $creator, 'fraction',
                    $creator >= $warn ? RiskSignal::SEVERITY_MEDIUM : RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now,
                    'Deployer wallet share of supply.', $creator >= $warn ? 0.4 : null);
            }
        } else {
            $out[] = RiskSignalDraft::unknown('creator_pct', $g, 'Creator balance unavailable.', 'goplus');
        }

        return $out;
    }

    // --- EXIT SAFETY -----------------------------------------------------

    /** @return list<RiskSignalDraft> */
    private function exitSafetySignals(TokenRiskContext $ctx): array
    {
        $g = RiskSignal::GROUP_EXIT_SAFETY;
        $gp = $ctx->goplus;
        $gt = $ctx->geckoterminal;
        $out = [];
        $cfg = config('risk.contract');

        // Honeypot — GoPlus simulation, GeckoTerminal cross-check.
        $honeypot = $gp->flag('is_honeypot') ?? $gt->isHoneypot();
        if ($honeypot === true) {
            $out[] = RiskSignalDraft::bad('is_honeypot', $g, 'true', 1.0, RiskSignal::SEVERITY_CRITICAL, 'goplus', $ctx->now,
                'Simulated as a honeypot — the position cannot be sold.', 1.0, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_CRITICAL);
        } elseif ($honeypot === false) {
            $out[] = RiskSignalDraft::measured('is_honeypot', $g, 'false', 0.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now, 'Not simulated as a honeypot.');
        } else {
            $out[] = RiskSignalDraft::unknown('is_honeypot', $g, 'Honeypot simulation not available for this token/chain.', 'goplus');
        }

        // cannot_sell_all / cannot_buy.
        $out[] = $this->evmFlag($ctx, $g, 'cannot_sell_all', 'Cannot sell the entire balance — an exit trap.',
            RiskSignal::SEVERITY_CRITICAL, RiskAssessment::LEVEL_CRITICAL);
        $out[] = $this->evmFlag($ctx, $g, 'cannot_buy', 'Buys are blocked by the contract.',
            RiskSignal::SEVERITY_HIGH, RiskAssessment::LEVEL_HIGH);

        // Sell tax.
        $sellTax = $gp->decimal('sell_tax');
        $criticalAt = (float) ($cfg['sell_tax_critical_at'] ?? 1.0);
        $highAt = (float) ($cfg['tax_high_at'] ?? 0.10);
        if ($sellTax === null) {
            $out[] = RiskSignalDraft::unknown('sell_tax', $g, 'Sell tax could not be measured — treated as UNKNOWN, never 0%.', 'goplus', applicable: $gp->isEvm());
        } elseif ($sellTax >= $criticalAt) {
            $out[] = RiskSignalDraft::bad('sell_tax', $g, round($sellTax * 100).'%', $sellTax, RiskSignal::SEVERITY_CRITICAL, 'goplus', $ctx->now,
                'Sell tax is effectively total — the position cannot be exited.', 1.0, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_CRITICAL);
        } elseif ($sellTax >= $highAt) {
            $out[] = RiskSignalDraft::bad('sell_tax', $g, round($sellTax * 100, 1).'%', $sellTax, RiskSignal::SEVERITY_HIGH, 'goplus', $ctx->now,
                'Sell tax is punitive.', min(1.0, $sellTax * 3), hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH);
        } else {
            $out[] = $this->taxBandSignal('sell_tax', $g, $sellTax, $ctx);
        }

        // Buy tax.
        $buyTax = $gp->decimal('buy_tax');
        if ($buyTax === null) {
            $out[] = RiskSignalDraft::unknown('buy_tax', $g, 'Buy tax could not be measured — treated as UNKNOWN, never 0%.', 'goplus', applicable: $gp->isEvm());
        } elseif ($buyTax >= $highAt) {
            $out[] = RiskSignalDraft::bad('buy_tax', $g, round($buyTax * 100, 1).'%', $buyTax, RiskSignal::SEVERITY_HIGH, 'goplus', $ctx->now,
                'Buy tax is punitive.', min(1.0, $buyTax * 3), hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH);
        } else {
            $out[] = $this->taxBandSignal('buy_tax', $g, $buyTax, $ctx);
        }

        // Tax mutability + transfer pausable.
        $out[] = $this->evmFlag($ctx, $g, 'slippage_modifiable', 'Tax / slippage can be changed by the contract owner — today\'s tax is not fixed.',
            RiskSignal::SEVERITY_HIGH, RiskAssessment::LEVEL_HIGH);
        $out[] = $this->evmFlag($ctx, $g, 'transfer_pausable', 'Transfers can be paused by the contract.',
            RiskSignal::SEVERITY_MEDIUM, null, softContribution: 0.5);

        // Solana exit-safety analogues.
        if ($gp->isSolana()) {
            $freeze = $gp->solanaAuthorityActive('freezable');
            if ($freeze === true) {
                $critical = (bool) ($cfg['solana_freeze_authority_critical'] ?? true);
                $out[] = RiskSignalDraft::bad('solana_freeze_authority', $g, 'active', 1.0, RiskSignal::SEVERITY_CRITICAL, 'goplus', $ctx->now,
                    'A live freeze authority can freeze token accounts — the position may not be sellable.', 1.0,
                    hardOverride: $critical, hardOverrideLevel: $critical ? RiskAssessment::LEVEL_CRITICAL : null);
            } elseif ($freeze === false) {
                $out[] = RiskSignalDraft::measured('solana_freeze_authority', $g, 'renounced', 0.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now, 'Freeze authority is renounced.');
            } else {
                $out[] = RiskSignalDraft::unknown('solana_freeze_authority', $g, 'Freeze authority state unknown.', 'goplus');
            }

            $nonTransferable = $gp->solanaAuthorityActive('non_transferable');
            if ($nonTransferable === true) {
                $out[] = RiskSignalDraft::bad('solana_non_transferable', $g, 'true', 1.0, RiskSignal::SEVERITY_CRITICAL, 'goplus', $ctx->now,
                    'Token is non-transferable — it cannot be traded.', 1.0, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_CRITICAL);
            }
        }

        return $out;
    }

    private function taxBandSignal(string $key, string $group, float $tax, TokenRiskContext $ctx): RiskSignalDraft
    {
        $cfg = config('risk.contract');
        $elevated = (float) ($cfg['tax_elevated_at'] ?? 0.02);
        $warning = (float) ($cfg['tax_warning_at'] ?? 0.05);
        [$sev, $contrib] = match (true) {
            $tax >= $warning => [RiskSignal::SEVERITY_MEDIUM, 0.5],
            $tax >= $elevated => [RiskSignal::SEVERITY_LOW, 0.25],
            default => [RiskSignal::SEVERITY_NONE, 0.0],
        };

        return RiskSignalDraft::measured($key, $group, round($tax * 100, 1).'%', $tax, 'fraction',
            $sev, 'goplus', $ctx->now,
            $tax > 0 ? 'Trading tax within tolerated bands.' : 'No trading tax.', $contrib > 0 ? $contrib : null);
    }

    // --- CONTRACT SECURITY -----------------------------------------------

    /** @return list<RiskSignalDraft> */
    private function contractSecuritySignals(TokenRiskContext $ctx): array
    {
        $g = RiskSignal::GROUP_CONTRACT_SECURITY;
        $gp = $ctx->goplus;
        $gt = $ctx->geckoterminal;
        $out = [];
        $cfg = config('risk.contract');
        $mintableLevel = (string) ($cfg['mintable_level'] ?? RiskAssessment::LEVEL_HIGH);

        // Chain not covered by any security provider => whole dimension UNKNOWN,
        // NOT an automatic HIGH RISK.
        if ($gp->outcome === GoPlusSecurityLookup::OUTCOME_UNSUPPORTED_CHAIN && ! $gt->hasData()) {
            $out[] = RiskSignalDraft::unknown('contract_security_coverage', $g,
                'Contract security could not be checked on this chain — reported as unknown, not high risk.', 'goplus', applicable: false);

            return $out;
        }
        if (! $gp->hasData() && ! $gt->hasData()) {
            $out[] = RiskSignalDraft::unknown('contract_security_coverage', $g,
                'No contract-security data available for this token yet.', 'goplus');

            return $out;
        }

        // Scam / fake-token flags.
        $out[] = $this->evmFlag($ctx, $g, 'is_airdrop_scam', 'Flagged as an airdrop scam by GoPlus.',
            RiskSignal::SEVERITY_CRITICAL, RiskAssessment::LEVEL_CRITICAL);
        if ($gp->flag('fake_token') === true) {
            $out[] = RiskSignalDraft::bad('fake_token', $g, 'true', 1.0, RiskSignal::SEVERITY_CRITICAL, 'goplus', $ctx->now,
                'Flagged as a fake / impersonating token.', 1.0, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_CRITICAL);
        }

        // Mintable — the SINGLE documented exception. Explicit `true` => at least
        // HIGH. UNKNOWN is NOT claimed to be mintable and does NOT force a level
        // (it still counts against data completeness). See docs/risk-screening.md.
        $mintEvm = $gp->flag('is_mintable');
        $mintSolana = $gp->isSolana() ? $gp->solanaAuthorityActive('mintable') : null;
        $mintGt = $gt->mintAuthorityActive();
        $mintable = $mintEvm ?? $mintSolana ?? $mintGt;
        if ($mintable === true) {
            $out[] = RiskSignalDraft::bad('is_mintable', $g, 'true', 1.0, RiskSignal::SEVERITY_HIGH, 'goplus', $ctx->now,
                'Supply can be minted — the top memecoin rug vector.', 1.0,
                hardOverride: true, hardOverrideLevel: $mintableLevel);
        } elseif ($mintable === false) {
            $out[] = RiskSignalDraft::measured('is_mintable', $g, 'false', 0.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now,
                'Mint is renounced / supply is fixed (measured).');
        } else {
            $out[] = RiskSignalDraft::unknown('is_mintable', $g,
                'Mintability could not be verified — NOT claimed present; treated as unknown per risk policy.', 'goplus');
        }

        // Proxy / upgradeable logic.
        $proxy = $gp->flag('is_proxy');
        if ($proxy === true) {
            $out[] = RiskSignalDraft::bad('is_proxy', $g, 'true', 1.0, RiskSignal::SEVERITY_HIGH, 'goplus', $ctx->now,
                'Upgradeable proxy with a live implementation — every other security reading is provisional.', 0.9,
                hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH);
        } elseif ($proxy === false) {
            $out[] = RiskSignalDraft::measured('is_proxy', $g, 'false', 0.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now, 'Immutable (non-proxy) contract logic.');
        } else {
            $out[] = RiskSignalDraft::unknown('is_proxy', $g, 'Proxy status unknown.', 'goplus', applicable: $gp->isEvm());
        }

        // Hidden owner.
        $out[] = $this->evmFlag($ctx, $g, 'hidden_owner', 'The contract owner is obscured behind another contract.',
            RiskSignal::SEVERITY_HIGH, RiskAssessment::LEVEL_HIGH);

        // Owner can rewrite balances.
        $out[] = $this->evmFlag($ctx, $g, 'owner_change_balance', 'The owner can modify holder balances.',
            RiskSignal::SEVERITY_CRITICAL, RiskAssessment::LEVEL_CRITICAL);

        // Self-destruct / arbitrary external calls.
        $out[] = $this->evmFlag($ctx, $g, 'selfdestruct', 'The contract can self-destruct.',
            RiskSignal::SEVERITY_HIGH, RiskAssessment::LEVEL_HIGH);
        $out[] = $this->evmFlag($ctx, $g, 'external_call', 'The contract calls arbitrary external contracts on transfer.',
            RiskSignal::SEVERITY_MEDIUM, null, softContribution: 0.5);

        // Owner renounce state (soft) + can_take_back_ownership.
        $owner = $gp->string('owner_address');
        $burnAddrs = array_map('mb_strtolower', (array) config('risk.holders.burn_addresses', []));
        if ($gp->isEvm()) {
            if ($owner === null && array_key_exists('owner_address', $gp->raw)) {
                $out[] = RiskSignalDraft::measured('owner_renounced', $g, 'renounced', 0.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now, 'Owner role is renounced (empty owner).');
            } elseif ($owner !== null && in_array(mb_strtolower($owner), $burnAddrs, true)) {
                $out[] = RiskSignalDraft::measured('owner_renounced', $g, 'renounced (dead address)', 0.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now, 'Owner role is renounced to a dead address.');
            } elseif ($owner !== null) {
                $out[] = RiskSignalDraft::measured('owner_renounced', $g, 'active owner', 1.0, 'bool', RiskSignal::SEVERITY_LOW, 'goplus', $ctx->now,
                    'A live owner retains owner-gated powers.', 0.35);
            } else {
                $out[] = RiskSignalDraft::unknown('owner_renounced', $g, 'Owner address not reported — renounce state unknown, never assumed.', 'goplus');
            }
            $out[] = $this->evmFlag($ctx, $g, 'can_take_back_ownership', 'A renounced owner could be reclaimed.',
                RiskSignal::SEVERITY_MEDIUM, null, softContribution: 0.45);
            $out[] = $this->evmFlag($ctx, $g, 'is_blacklisted', 'The contract has an address-blacklist mechanism.',
                RiskSignal::SEVERITY_LOW, null, softContribution: 0.3);

            // Open source (soft — false is elevated, never a hard fail).
            $openSource = $gp->flag('is_open_source');
            if ($openSource === false) {
                $out[] = RiskSignalDraft::bad('is_open_source', $g, 'false', 0.0, RiskSignal::SEVERITY_MEDIUM, 'goplus', $ctx->now,
                    'Contract source is not verified/published.', 0.5);
            } elseif ($openSource === true) {
                $out[] = RiskSignalDraft::measured('is_open_source', $g, 'true', 1.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now, 'Contract source is verified.');
            } else {
                $out[] = RiskSignalDraft::unknown('is_open_source', $g, 'Source-verification status unknown.', 'goplus');
            }

            // rugpull_detecting composite (best-effort, merged into raw).
            $rug = $gp->raw['_rugpull'] ?? null;
            if (is_array($rug) && $rug !== []) {
                $flags = array_filter($rug, fn ($v): bool => $v === '1' || $v === 1 || $v === true);
                if ($flags !== []) {
                    $out[] = RiskSignalDraft::bad('rugpull_detecting', $g, implode(',', array_keys($flags)), (float) count($flags),
                        RiskSignal::SEVERITY_HIGH, 'goplus', $ctx->now, 'GoPlus rug-pull detection raised one or more flags.', 0.8);
                } else {
                    $out[] = RiskSignalDraft::measured('rugpull_detecting', $g, 'no flags', 0.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now, 'GoPlus rug-pull detection raised no flags.');
                }
            } else {
                $out[] = RiskSignalDraft::unknown('rugpull_detecting', $g, 'GoPlus rug-pull detection not available for this token.', 'goplus', applicable: false);
            }
        }

        // Solana authority-model contract signals.
        if ($gp->isSolana()) {
            $balanceMutable = $gp->solanaAuthorityActive('balance_mutable_authority');
            if ($balanceMutable === true) {
                $critical = (bool) ($cfg['solana_balance_mutable_critical'] ?? true);
                $out[] = RiskSignalDraft::bad('solana_balance_mutable', $g, 'active', 1.0, RiskSignal::SEVERITY_CRITICAL, 'goplus', $ctx->now,
                    'A live balance-mutate authority can rewrite token balances.', 1.0,
                    hardOverride: $critical, hardOverrideLevel: $critical ? RiskAssessment::LEVEL_CRITICAL : null);
            }
            $hook = $gp->solanaHasNode('transfer_hook');
            if ($hook === true) {
                $out[] = RiskSignalDraft::bad('solana_transfer_hook', $g, 'present', 1.0, RiskSignal::SEVERITY_HIGH, 'goplus', $ctx->now,
                    'A transfer hook runs arbitrary logic on every transfer.', 0.9, hardOverride: true, hardOverrideLevel: RiskAssessment::LEVEL_HIGH);
            }
            $metaMutable = $gp->solanaAuthorityActive('metadata_mutable');
            if ($metaMutable === true) {
                $out[] = RiskSignalDraft::measured('solana_metadata_mutable', $g, 'mutable', 1.0, 'bool', RiskSignal::SEVERITY_LOW, 'goplus', $ctx->now,
                    'Token metadata is mutable — low/medium risk on its own.', 0.3);
            }
        }

        return $out;
    }

    /**
     * Standard tri-state handling for a GoPlus EVM "1"/"0" flag where `true` is
     * dangerous. `$hardLevel` null => soft signal (no override).
     */
    private function evmFlag(
        TokenRiskContext $ctx,
        string $group,
        string $key,
        string $badExplanation,
        string $badSeverity,
        ?string $hardLevel,
        float $softContribution = 1.0,
    ): RiskSignalDraft {
        $flag = $ctx->goplus->flag($key);

        if ($flag === true) {
            return RiskSignalDraft::bad($key, $group, 'true', 1.0, $badSeverity, 'goplus', $ctx->now, $badExplanation,
                $softContribution, hardOverride: $hardLevel !== null, hardOverrideLevel: $hardLevel);
        }
        if ($flag === false) {
            return RiskSignalDraft::measured($key, $group, 'false', 0.0, null, RiskSignal::SEVERITY_NONE, 'goplus', $ctx->now, 'Not present (measured).');
        }

        return RiskSignalDraft::unknown($key, $group, 'Not reported for this token/chain — treated as unknown, never "no".', 'goplus',
            applicable: $ctx->goplus->isEvm());
    }
}
