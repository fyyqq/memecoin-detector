<?php

declare(strict_types=1);

namespace App\Services\Historical;

use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\QualificationEvent;
use App\Models\Token;
use Carbon\CarbonImmutable;

/**
 * Step 20 — records "$5M crossing" events during the ingestion / qualification
 * pipeline.
 *
 * For each age-eligible token whose {@see HistoricalPeakEvidence} proves a
 * VERIFIED / OBSERVED market cap cleared the floor, this ensures the matching
 * {@see QualificationEvent} row exists:
 *
 *   evidence CURRENT_OBSERVATION -> a CURRENT_OBSERVATION crossing at the
 *     earliest snapshot whose market_cap >= threshold
 *   evidence HISTORICAL_VERIFIED -> a HISTORICAL_VERIFIED crossing at the
 *     earliest CoinGecko-verified >= threshold point
 *
 * HISTORICAL_ESTIMATE and UNKNOWN never produce an event.
 *
 * Idempotent — the `(token_id, type)` unique constraint plus a pre-load of
 * existing rows means repeated scheduler runs create nothing new and never
 * rewrite a recorded `crossed_at`. A token may accumulate BOTH types over its
 * life; an existing row of the other type is preserved.
 *
 * This runs ONLY in the pipeline — a read API never calls it and never scans
 * snapshots.
 */
class QualificationEventRecorder
{
    /**
     * @param  list<array{token:Token,evidence:?HistoricalPeakEvidence}>  $entries
     * @param  float|null  $ceiling  the $200M ceiling — a verified/observed peak
     *                               above it is outside the tracked universe and
     *                               gets no crossing event (matches the main-list
     *                               "qualified" definition). Null = floor only.
     * @return array{qualification_events_created:int,qualification_events_existing:int}
     */
    public function recordBatch(array $entries, CarbonImmutable $now, float $threshold, ?float $ceiling = null): array
    {
        $stats = ['qualification_events_created' => 0, 'qualification_events_existing' => 0];

        // Only tokens whose evidence proves a VERIFIED / OBSERVED crossing that
        // sits in the tracked [$5M, $200M] universe — the same "qualified"
        // definition the main list uses. Age is already enforced upstream (only
        // age-eligible tokens reach the recorder); an existing event for a token
        // that later ages out or dumps is never deleted.
        $relevant = [];
        foreach ($entries as $entry) {
            $evidence = $entry['evidence'];
            if ($evidence === null || ! $evidence->qualifies($threshold, $ceiling)) {
                continue;
            }
            $relevant[] = $entry;
        }

        if ($relevant === []) {
            return $stats;
        }

        $tokenIds = array_values(array_unique(array_map(
            static fn (array $e): int => (int) $e['token']->id,
            $relevant,
        )));

        // One query: every existing event for the batch, keyed token_id => type => row.
        $existing = QualificationEvent::query()
            ->whereIn('token_id', $tokenIds)
            ->get()
            ->groupBy('token_id')
            ->map(fn ($rows) => $rows->keyBy('type'));

        foreach ($relevant as $entry) {
            $token = $entry['token'];
            $evidence = $entry['evidence'];
            $tokenId = (int) $token->id;

            $type = $evidence->status === HistoricalPeakEvidence::STATUS_HISTORICAL_VERIFIED
                ? QualificationEvent::TYPE_HISTORICAL_VERIFIED
                : QualificationEvent::TYPE_CURRENT_OBSERVATION;

            if (isset($existing[$tokenId][$type])) {
                $stats['qualification_events_existing']++;

                continue;
            }

            $attrs = $type === QualificationEvent::TYPE_HISTORICAL_VERIFIED
                ? $this->verifiedCrossing($evidence, $now)
                : $this->currentObservationCrossing($token, $threshold, $now);

            QualificationEvent::query()->create([
                'token_id' => $tokenId,
                'type' => $type,
                'threshold_usd' => (int) $threshold,
                'evidence_status' => $evidence->status,
                ...$attrs,
            ]);

            $stats['qualification_events_created']++;
        }

        return $stats;
    }

    /**
     * @return array{crossed_at:CarbonImmutable,source:string,market_cap_value:?float}
     */
    private function verifiedCrossing(HistoricalPeakEvidence $evidence, CarbonImmutable $now): array
    {
        return [
            'crossed_at' => $evidence->first_verified_crossing_at
                ?? $evidence->peak_observed_at
                ?? $now,
            'source' => HistoricalPeakEvidence::SOURCE_COINGECKO,
            'market_cap_value' => $evidence->peak_value_usd,
        ];
    }

    /**
     * The earliest of OUR OWN snapshots whose market cap cleared the floor. One
     * indexed query, run only when the event does not yet exist.
     *
     * @return array{crossed_at:CarbonImmutable,source:string,market_cap_value:?float}
     */
    private function currentObservationCrossing(Token $token, float $threshold, CarbonImmutable $now): array
    {
        /** @var MarketSnapshot|null $first */
        $first = $token->marketSnapshots()
            ->whereNotNull('market_cap')
            ->where('market_cap', '>=', $threshold)
            ->orderBy('observed_at')
            ->orderBy('id')
            ->first();

        if ($first !== null) {
            return [
                'crossed_at' => $first->observed_at ?? $now,
                'source' => HistoricalPeakEvidence::SOURCE_DEXSCREENER,
                'market_cap_value' => $first->market_cap,
            ];
        }

        // Defensive fallback — evidence says CURRENT_OBSERVATION so a snapshot
        // >= threshold exists in principle; use the recorded observed peak.
        return [
            'crossed_at' => $token->observed_peak_market_cap_at ?? $now,
            'source' => HistoricalPeakEvidence::SOURCE_DEXSCREENER,
            'market_cap_value' => $token->observed_peak_market_cap,
        ];
    }
}
