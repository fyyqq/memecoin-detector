<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

use App\DTOs\DexScreener\TokenCandidateData;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Persists one normalized candidate as a Token (find-or-create on
 * chain_id + token_address) plus an appended MarketSnapshot, and maintains the
 * running `observed_peak_market_cap`.
 *
 * "Observed peak" = the highest market cap captured by our own snapshots since
 * `first_observed_at`. Never a lifetime / all-time high.
 */
class TokenObservationService
{
    public function record(TokenCandidateData $candidate, CarbonImmutable $observedAt): RecordedObservation
    {
        return DB::transaction(function () use ($candidate, $observedAt): RecordedObservation {
            $token = $this->findOrCreateToken($candidate, $observedAt);
            $wasCreated = $token->wasRecentlyCreated;
            $previousPeak = $token->observed_peak_market_cap;

            if ($candidate->name !== null) {
                $token->name = $candidate->name;
            }

            if ($candidate->symbol !== null) {
                $token->symbol = $candidate->symbol;
            }

            if ($candidate->earliestPairCreatedAt !== null) {
                $token->earliest_pair_created_at = $candidate->earliestPairCreatedAt;
            }

            // first_observed_at is set once and never moved forward.
            if ($token->first_observed_at === null) {
                $token->first_observed_at = $observedAt;
            }

            $token->last_observed_at = $observedAt;

            $peakUpdated = $this->maybeRaisePeak($token, $candidate->marketCap, $observedAt);

            $token->save();

            $snapshot = $token->marketSnapshots()->create([
                'observed_at' => $observedAt,
                'price_usd' => $candidate->priceUsd,
                'market_cap' => $candidate->marketCap,
                'fdv' => $candidate->fdv,
                'liquidity_usd' => $candidate->liquidityUsd,
                'volume_h24' => $candidate->volumeH24,
                'price_change_h24' => $candidate->priceChangeH24,
                'txns_h24' => $candidate->txnsH24,
                'buys_h24' => $candidate->buysH24,
                'sells_h24' => $candidate->sellsH24,
                'primary_pair_address' => $candidate->primaryPairAddress,
                'primary_dex_id' => $candidate->primaryDexId,
                'earliest_pair_created_at' => $candidate->earliestPairCreatedAt,
            ]);

            return new RecordedObservation($token->refresh(), $snapshot, $wasCreated, $peakUpdated, $previousPeak);
        });
    }

    private function findOrCreateToken(TokenCandidateData $candidate, CarbonImmutable $observedAt): Token
    {
        $identity = [
            'chain_id' => $candidate->chainId,
            'token_address' => $candidate->tokenAddress,
        ];

        try {
            return Token::query()->firstOrCreate($identity, ['first_observed_at' => $observedAt]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent create — the row now exists.
            return Token::query()->where($identity)->firstOrFail();
        }
    }

    /**
     * Raise the observed peak only when the current market cap is known and
     * strictly higher. A null market cap never lowers or clears the peak.
     */
    private function maybeRaisePeak(Token $token, ?float $currentMarketCap, CarbonImmutable $observedAt): bool
    {
        if ($currentMarketCap === null) {
            return false;
        }

        if ($token->observed_peak_market_cap !== null && $currentMarketCap <= $token->observed_peak_market_cap) {
            return false;
        }

        $token->observed_peak_market_cap = $currentMarketCap;
        $token->observed_peak_market_cap_at = $observedAt;

        return true;
    }
}
