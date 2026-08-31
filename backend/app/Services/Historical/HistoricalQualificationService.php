<?php

declare(strict_types=1);

namespace App\Services\Historical;

use App\Models\HistoricalPeakEvidence;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Historical qualification engine (Strategy D).
 *
 * Runs AFTER age filter + observation persistence. For each age-eligible token
 * it produces exactly one {@see HistoricalPeakEvidence} row (upserted,
 * re-evaluable) and mirrors the headline onto the Token's `historical_peak_*`
 * columns — WITHOUT ever touching `observed_peak_market_cap`.
 *
 * Order of evidence:
 *   1. CURRENT_OBSERVATION  our own observed peak >= threshold (no external call)
 *   2. HISTORICAL_VERIFIED  CoinGecko non-zero historical market cap >= threshold
 *   3. HISTORICAL_ESTIMATE  GeckoTerminal peak price x immutable total supply >= threshold
 *   4. UNKNOWN              none of the above (never "did not reach threshold")
 *
 * Only 1 and 2 QUALIFY a token for the main >= $5M universe. 3 is stored as an
 * informational secondary signal (an estimated historical FDV, NOT a verified
 * market cap) and mirrored to `historical_estimate_fdv_usd`, never
 * `historical_peak_value`.
 *
 * External lookups are gated by: age-eligible AND observed peak < threshold AND
 * a re-lookup cooldown AND a per-run budget.
 */
class HistoricalQualificationService
{
    private float $threshold;

    private int $cooldownHours;

    private int $maxLookupsPerRun;

    /** @var array<string,array{coingecko:string,geckoterminal:string}> */
    private array $chainMap;

    public function __construct(
        private readonly CoinGeckoClient $coinGecko,
        private readonly GeckoTerminalClient $geckoTerminal,
    ) {
        $this->threshold = (float) config('historical.min_peak_market_cap_usd');
        $this->cooldownHours = max(0, (int) config('historical.lookup_cooldown_hours'));
        $this->maxLookupsPerRun = max(0, (int) config('historical.max_lookups_per_run'));
        /** @var array<string,array{coingecko:string,geckoterminal:string}> $map */
        $map = config('historical.chain_map', []);
        $this->chainMap = $map;
    }

    public function threshold(): float
    {
        return $this->threshold;
    }

    /**
     * Resolve evidence for a batch of age-eligible tokens.
     *
     * @param  list<array{token:Token,chain_id:string,token_address:string}>  $eligible
     * @return array{
     *     evidence: array<int,HistoricalPeakEvidence>,
     *     stats: array<string,int>
     * }
     */
    public function qualify(array $eligible, CarbonImmutable $now): array
    {
        $this->coinGecko->resetBudget();
        $this->geckoTerminal->resetBudget();

        $stats = [
            'historical_current_observation' => 0,
            'historical_verified' => 0,
            'historical_estimate' => 0,
            'historical_unknown' => 0,
            'historical_lookups_performed' => 0,
            'historical_lookups_skipped_cooldown' => 0,
            'historical_lookups_skipped_budget' => 0,
        ];

        /** @var array<int,HistoricalPeakEvidence> $evidence */
        $evidence = [];

        // Pass 1 — free CURRENT_OBSERVATION path.
        /** @var list<array{token:Token,chain_id:string,token_address:string}> $needExternal */
        $needExternal = [];

        foreach ($eligible as $row) {
            $token = $row['token'];
            $observedPeak = $token->observed_peak_market_cap;

            if ($observedPeak !== null && $observedPeak >= $this->threshold) {
                $evidence[$token->id] = $this->write($token, [
                    'status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
                    'peak_value_usd' => $observedPeak,
                    'peak_observed_at' => $token->observed_peak_market_cap_at,
                    'evidence_source' => HistoricalPeakEvidence::SOURCE_DEXSCREENER,
                    'evidence_basis' => HistoricalPeakEvidence::BASIS_CURRENT_MARKET_CAP,
                    'source_reference' => 'market_snapshots',
                    'historical_window_start' => $token->first_observed_at,
                    'historical_window_end' => $now,
                    'confidence' => 'high',
                    'checked_at' => null,
                    'notes' => 'our own snapshots observed market cap >= threshold',
                ]);
                $stats['historical_current_observation']++;

                continue;
            }

            $needExternal[] = $row;
        }

        // Pass 2 — cooldown / budget gate, then external lookup.
        // Priority: never-checked first, then oldest checked_at.
        usort($needExternal, function (array $a, array $b): int {
            $ca = $a['token']->historicalPeakEvidence?->checked_at?->getTimestamp() ?? -1;
            $cb = $b['token']->historicalPeakEvidence?->checked_at?->getTimestamp() ?? -1;

            return $ca <=> $cb;
        });

        $cooldownCutoff = $now->subHours($this->cooldownHours);

        foreach ($needExternal as $row) {
            $token = $row['token'];
            $existing = $token->historicalPeakEvidence;

            // HISTORICAL_VERIFIED is terminal — a past peak >= threshold does
            // not un-happen.
            if ($existing !== null && $existing->status === HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED) {
                $evidence[$token->id] = $existing;
                $this->mirrorToToken($token, $existing);
                $this->tallyExisting($existing, $stats);

                continue;
            }

            $withinCooldown = $existing !== null
                && $existing->checked_at !== null
                && $existing->checked_at->greaterThan($cooldownCutoff);

            if ($withinCooldown) {
                $evidence[$token->id] = $existing;
                $this->mirrorToToken($token, $existing);
                $this->tallyExisting($existing, $stats);
                $stats['historical_lookups_skipped_cooldown']++;

                continue;
            }

            if ($stats['historical_lookups_performed'] >= $this->maxLookupsPerRun) {
                $stats['historical_lookups_skipped_budget']++;
                $evidence[$token->id] = $existing ?? $this->write($token, [
                    'status' => HistoricalPeakEvidence::STATUS_UNKNOWN,
                    'peak_value_usd' => null,
                    'peak_observed_at' => null,
                    'evidence_source' => null,
                    'evidence_basis' => null,
                    'source_reference' => null,
                    'historical_window_start' => null,
                    'historical_window_end' => null,
                    'confidence' => null,
                    // Not actually checked — retried next run (cooldown treats
                    // null checked_at as expired).
                    'checked_at' => null,
                    'notes' => 'not yet checked — per-run lookup budget exhausted',
                ]);
                $this->tallyExisting($evidence[$token->id], $stats);

                continue;
            }

            $stats['historical_lookups_performed']++;
            $attrs = $this->lookup($token, $row['chain_id'], $row['token_address'], $now);
            $evidence[$token->id] = $this->write($token, $attrs);
            $this->tallyExisting($evidence[$token->id], $stats);
        }

        return ['evidence' => $evidence, 'stats' => $stats];
    }

    /**
     * One external lookup: CoinGecko (verified) → GeckoTerminal (estimate) →
     * UNKNOWN. Returns the attribute array for {@see write()}.
     *
     * @return array<string,mixed>
     */
    private function lookup(Token $token, string $chainId, string $tokenAddress, CarbonImmutable $now): array
    {
        $base = [
            'status' => HistoricalPeakEvidence::STATUS_UNKNOWN,
            'peak_value_usd' => null,
            'peak_observed_at' => null,
            'evidence_source' => null,
            'evidence_basis' => null,
            'source_reference' => null,
            'historical_window_start' => null,
            'historical_window_end' => $now,
            'confidence' => null,
            'checked_at' => $now,
            'notes' => null,
        ];

        $map = $this->chainMap[$chainId] ?? null;
        if ($map === null) {
            $base['notes'] = "chain '{$chainId}' is not mapped to CoinGecko / GeckoTerminal";

            return $base;
        }

        $windowStart = $token->earliest_pair_created_at
            ?? $token->first_observed_at
            ?? $now->subDays(30);
        // Never ask for data before the 30-day window we care about.
        $floor = $now->subDays(30);
        if ($windowStart->lessThan($floor)) {
            $windowStart = $floor;
        }
        $base['historical_window_start'] = $windowStart;

        $notes = [];

        // 1. CoinGecko -----------------------------------------------------
        try {
            $cg = $this->coinGecko->historicalPeak($map['coingecko'], $tokenAddress, $windowStart, $now, $this->threshold);
        } catch (Throwable $e) {
            Log::warning('CoinGecko lookup threw', ['token' => $token->id, 'error' => $e->getMessage()]);
            $cg = CoinGeckoLookup::unavailable('coingecko: '.$e->getMessage());
        }

        if ($cg->outcome === 'verified') {
            return [
                'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED,
                'peak_value_usd' => $cg->peakMarketCapUsd,
                'peak_observed_at' => $cg->peakAt,
                'first_verified_crossing_at' => $cg->firstCrossingAt ?? $cg->peakAt,
                'evidence_source' => HistoricalPeakEvidence::SOURCE_COINGECKO,
                'evidence_basis' => HistoricalPeakEvidence::BASIS_MARKET_CAP,
                'source_reference' => 'coingecko:'.$cg->coinId,
                'historical_window_start' => $cg->windowStart ?? $windowStart,
                'historical_window_end' => $cg->windowEnd ?? $now,
                'confidence' => 'high',
                'checked_at' => $now,
                'notes' => 'coingecko verified historical market cap',
            ];
        }
        if ($cg->note !== null) {
            $notes[] = $cg->note;
        }

        // 2. GeckoTerminal ----------------------------------------------
        try {
            $gt = $this->geckoTerminal->historicalEstimate($map['geckoterminal'], $tokenAddress, $windowStart, $now);
        } catch (Throwable $e) {
            Log::warning('GeckoTerminal lookup threw', ['token' => $token->id, 'error' => $e->getMessage()]);
            $gt = GeckoTerminalLookup::unavailable('geckoterminal: '.$e->getMessage());
        }

        if ($gt->outcome === 'estimate' && $gt->estimateUsd !== null && $gt->estimateUsd >= $this->threshold) {
            return [
                'status' => HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE,
                'peak_value_usd' => $gt->estimateUsd,
                'peak_observed_at' => $gt->peakAt,
                'evidence_source' => HistoricalPeakEvidence::SOURCE_GECKOTERMINAL,
                'evidence_basis' => HistoricalPeakEvidence::BASIS_FDV_TOTAL_SUPPLY,
                'source_reference' => 'geckoterminal:pool:'.$gt->poolAddress,
                'historical_window_start' => $gt->windowStart ?? $windowStart,
                'historical_window_end' => $gt->windowEnd ?? $now,
                'confidence' => $gt->confidence ?? 'low',
                'checked_at' => $now,
                'notes' => trim(($gt->note ?? 'fdv-basis estimate').' | '.implode('; ', $notes), ' |'),
            ];
        }
        if ($gt->note !== null) {
            $notes[] = $gt->note;
        }

        if ($gt->outcome === 'estimate' && $gt->estimateUsd !== null) {
            $notes[] = sprintf('geckoterminal estimate $%.0f below threshold', $gt->estimateUsd);
        }

        $base['notes'] = $notes === []
            ? 'no historical evidence available'
            : mb_substr(implode('; ', $notes), 0, 480);

        return $base;
    }

    /**
     * Upsert the evidence row and mirror its headline onto the Token.
     *
     * @param  array<string,mixed>  $attrs
     */
    private function write(Token $token, array $attrs): HistoricalPeakEvidence
    {
        /** @var HistoricalPeakEvidence $evidence */
        $evidence = HistoricalPeakEvidence::query()->updateOrCreate(
            ['token_id' => $token->id],
            $attrs,
        );

        $this->mirrorToToken($token, $evidence);

        return $evidence;
    }

    /**
     * Copy the evidence headline onto the Token's denormalized columns.
     *
     *   historical_peak_value / _at    <- VERIFIED / OBSERVED market cap only
     *                                     (CURRENT_OBSERVATION / HISTORICAL_VERIFIED)
     *   historical_estimate_fdv_usd/_at <- FDV-basis ESTIMATE only
     *                                     (HISTORICAL_ESTIMATE) — informational
     *
     * `observed_peak_market_cap` is deliberately NOT touched. An FDV estimate
     * NEVER lands in `historical_peak_value`.
     */
    private function mirrorToToken(Token $token, HistoricalPeakEvidence $evidence): void
    {
        $clearsThreshold = $evidence->peak_value_usd !== null
            && $evidence->peak_value_usd >= $this->threshold;

        $isVerifiedMarketCap = $clearsThreshold
            && in_array($evidence->status, HistoricalPeakEvidence::QUALIFYING_STATUSES, true);

        $isEstimate = $clearsThreshold
            && $evidence->status === HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE;

        $token->forceFill([
            'historical_peak_status' => $evidence->status,
            'historical_peak_value' => $isVerifiedMarketCap ? $evidence->peak_value_usd : null,
            'historical_peak_value_at' => $isVerifiedMarketCap ? $evidence->peak_observed_at : null,
            'historical_estimate_fdv_usd' => $isEstimate ? $evidence->peak_value_usd : null,
            'historical_estimate_fdv_at' => $isEstimate ? $evidence->peak_observed_at : null,
        ])->save();

        $token->setRelation('historicalPeakEvidence', $evidence);
    }

    /**
     * @param  array<string,int>  $stats
     */
    private function tallyExisting(HistoricalPeakEvidence $evidence, array &$stats): void
    {
        match ($evidence->status) {
            HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION => $stats['historical_current_observation']++,
            HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED => $stats['historical_verified']++,
            HistoricalPeakEvidence::STATUS_HISTORICAL_ESTIMATE => $stats['historical_estimate']++,
            default => $stats['historical_unknown']++,
        };
    }
}
