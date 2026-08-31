<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Evidence;
use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use App\Models\PumpExplanation;
use App\Models\Token;
use App\Services\AI\PumpExplanationPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Full token detail for the detail page.
 *
 * Read-only, PostgreSQL only. Identity + peak state come from the {@see Token};
 * `latest` comes from its most recent {@see MarketSnapshot}; `qualification`
 * comes from its {@see HistoricalPeakEvidence} (or is derived); `snapshots` is a
 * bounded, newest-first window of recent observations.
 *
 * Three figures are reported **separately and never merged**:
 *   - `observed.peak_market_cap` — the highest market cap THIS detector has
 *     captured since `first_observed_at` (our own snapshots).
 *   - `qualification.peak_value` — a VERIFIED / OBSERVED market cap the token
 *     has reached (CURRENT_OBSERVATION or CoinGecko HISTORICAL_VERIFIED). This
 *     is what qualifies the token for the main ≥ $5M list.
 *   - `historical_estimate.estimated_fdv_usd` — a GeckoTerminal FDV-basis
 *     ESTIMATE (peak price × total supply). Informational ONLY. It is NEVER a
 *     market cap and NEVER qualifies the token for the main list.
 *
 * Missing values are JSON `null`, never coerced to `0`.
 *
 * @mixin Token
 */
class MemecoinDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MarketSnapshot|null $snapshot */
        $snapshot = $this->latestSnapshot;

        /** @var Collection<int, MarketSnapshot> $recent */
        $recent = $this->recentSnapshots;

        $qualification = $this->qualification();
        $historicalEstimate = $this->historicalEstimate();

        return [
            'id' => $this->id,

            'chain_id' => $this->chain_id,
            'token_address' => $this->token_address,
            'name' => $this->name,
            'symbol' => $this->symbol,

            'age_days' => $this->ageDays(),

            'qualification' => $qualification,

            // Secondary informational signal — an estimated historical FDV, NOT
            // a verified/observed market cap. null unless a HISTORICAL_ESTIMATE
            // evidence row exists. Never folded into `qualification`.
            'historical_estimate' => $historicalEstimate,

            'observed' => [
                'peak_market_cap' => $this->observed_peak_market_cap,
                'peak_at' => $this->observed_peak_market_cap_at?->toIso8601String(),
                'first_observed_at' => $this->first_observed_at?->toIso8601String(),
                'last_observed_at' => $this->last_observed_at?->toIso8601String(),
            ],

            'latest' => [
                'market_cap' => $snapshot?->market_cap,
                'price_usd' => $snapshot?->price_usd,
                'fdv' => $snapshot?->fdv,
                'liquidity_usd' => $snapshot?->liquidity_usd,
                'volume_h24' => $snapshot?->volume_h24,
                'price_change_h24' => $snapshot?->price_change_h24,
                'txns_h24' => $snapshot?->txns_h24,
                'buys_h24' => $snapshot?->buys_h24,
                'sells_h24' => $snapshot?->sells_h24,
                'primary_dex_id' => $snapshot?->primary_dex_id,
                'primary_pair_address' => $snapshot?->primary_pair_address,
                'observed_at' => $snapshot?->observed_at?->toIso8601String(),
            ],

            'pair' => [
                'earliest_pair_created_at' => $this->earliest_pair_created_at?->toIso8601String(),
                // Not captured by Sprint 1 persistence — null, never a fabricated count.
                'pair_count' => null,
            ],

            'snapshots' => $recent->map(fn (MarketSnapshot $row): array => [
                'observed_at' => $row->observed_at?->toIso8601String(),
                'price_usd' => $row->price_usd,
                'market_cap' => $row->market_cap,
                'fdv' => $row->fdv,
                'liquidity_usd' => $row->liquidity_usd,
                'volume_h24' => $row->volume_h24,
                'price_change_h24' => $row->price_change_h24,
                'txns_h24' => $row->txns_h24,
                'buys_h24' => $row->buys_h24,
                'sells_h24' => $row->sells_h24,
            ])->all(),

            'pump_intelligence' => [
                'events' => $this->pumpIntelligenceEvents(),
            ],

            'provenance' => [
                'data_source' => 'dexscreener',
                'last_observed_at' => $this->last_observed_at?->toIso8601String(),
                'historical_qualification_source' => $qualification['source'],
                'observed_peak_note' => 'Observed peak is the highest market cap captured by this detector since first_observed_at — not a guaranteed lifetime / all-time high.',
                'historical_estimate_note' => $historicalEstimate !== null
                    ? 'The historical figure shown for this token is an FDV basis estimate (peak price × total supply), not a verified circulating market cap. It does not qualify the token for the main $5M market-cap list.'
                    : null,
            ],
        ];
    }

    /**
     * The token's MAIN-LIST qualification.
     *
     *   qualified = true  only for a VERIFIED / OBSERVED market cap that clears
     *                     the threshold (CURRENT_OBSERVATION or HISTORICAL_VERIFIED).
     *   peak_value        the qualifying market cap — NEVER an FDV estimate; null
     *                     for HISTORICAL_ESTIMATE and UNKNOWN.
     *   status            the raw evidence label (all four possible on the detail
     *                     page); UNKNOWN means "not verified", NOT "never reached
     *                     the threshold".
     *
     * The FDV estimate, if any, is reported separately in `historical_estimate`.
     *
     * @return array{status:string,qualified:bool,peak_value:?float,peak_at:?string,source:?string,basis:?string,confidence:?string}
     */
    private function qualification(): array
    {
        /** @var HistoricalPeakEvidence|null $evidence */
        $evidence = $this->historicalPeakEvidence;

        $threshold = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');

        // Verified/observed market-cap qualification.
        if ($evidence !== null && $evidence->qualifies($threshold)) {
            return [
                'status' => $evidence->status,
                'qualified' => true,
                'peak_value' => $evidence->peak_value_usd,
                'peak_at' => $evidence->peak_observed_at?->toIso8601String(),
                'source' => $evidence->evidence_source,
                'basis' => $evidence->evidence_basis,
                'confidence' => $evidence->confidence,
            ];
        }

        // Derived CURRENT_OBSERVATION when there is no evidence row yet.
        if ($this->observed_peak_market_cap !== null && $this->observed_peak_market_cap >= $threshold) {
            return [
                'status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
                'qualified' => true,
                'peak_value' => $this->observed_peak_market_cap,
                'peak_at' => $this->observed_peak_market_cap_at?->toIso8601String(),
                'source' => HistoricalPeakEvidence::SOURCE_DEXSCREENER,
                'basis' => HistoricalPeakEvidence::BASIS_CURRENT_MARKET_CAP,
                'confidence' => 'high',
            ];
        }

        // Not qualified for the main list. Keep the raw status
        // (HISTORICAL_ESTIMATE / UNKNOWN) but expose NO market-cap value here —
        // an FDV estimate is never a qualification market cap.
        return [
            'status' => $evidence?->status ?? HistoricalPeakEvidence::STATUS_UNKNOWN,
            'qualified' => false,
            'peak_value' => null,
            'peak_at' => null,
            'source' => null,
            'basis' => null,
            'confidence' => null,
        ];
    }

    /**
     * The FDV-basis historical estimate, when one exists. Informational only —
     * explicitly NOT a market cap. Uses `historical_estimated_fdv`-style naming,
     * never `historical_market_cap`.
     *
     * @return array{estimated_fdv_usd:?float,estimate_source:?string,estimate_basis:?string,estimate_confidence:?string,estimate_at:?string,note:string}|null
     */
    private function historicalEstimate(): ?array
    {
        /** @var HistoricalPeakEvidence|null $evidence */
        $evidence = $this->historicalPeakEvidence;

        if ($evidence === null || ! $evidence->isInformationalEstimate()) {
            return null;
        }

        return [
            'estimated_fdv_usd' => $evidence->peak_value_usd,
            'estimate_source' => $evidence->evidence_source,
            'estimate_basis' => $evidence->evidence_basis,
            'estimate_confidence' => $evidence->confidence,
            'estimate_at' => $evidence->peak_observed_at?->toIso8601String(),
            'note' => 'Estimated historical FDV ≥ $5M based on historical peak price × defensible total supply. This does NOT verify that market capitalization reached $5M, and does not qualify the token for the main list.',
        ];
    }

    /**
     * Recent pump events with their persisted AI explanation (Step 16C).
     *
     * Read-only. The explanation is whatever the CLI/scheduler last generated —
     * this method NEVER calls the AI provider. An event with no explanation row
     * yet reports `status: "pending"`.
     *
     * @return list<array<string,mixed>>
     */
    private function pumpIntelligenceEvents(): array
    {
        /** @var Collection<int, PumpEvent> $events */
        $events = $this->recentPumpEvents ?? collect();

        $presenter = app(PumpExplanationPresenter::class);

        return $events->map(function (PumpEvent $event) use ($presenter): array {
            /** @var PumpExplanation|null $explanation */
            $explanation = $event->explanation;

            return [
                'id' => $event->id,
                'started_at' => $event->started_at?->toIso8601String(),
                'peak_at' => $event->peak_at?->toIso8601String(),
                'ended_at' => $event->ended_at?->toIso8601String(),
                'status' => $event->status,
                'start_market_cap' => $event->start_market_cap,
                'peak_market_cap' => $event->peak_market_cap,
                'market_cap_change_pct' => $event->market_cap_change_pct,
                'price_change_pct' => $event->price_change_pct,
                'volume_h24_change_ratio' => $event->volume_h24_change_ratio,
                'txns_h24_change_ratio' => $event->txns_h24_change_ratio,
                'duration_minutes' => $event->duration_minutes,
                'detection_score' => $event->detection_score,
                'detection_confidence' => $event->confidence,
                'explanation' => $this->explanationPayload($event, $explanation, $presenter),
            ];
        })->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function explanationPayload(PumpEvent $event, ?PumpExplanation $explanation, PumpExplanationPresenter $presenter): array
    {
        $pending = [
            'status' => PumpExplanation::STATUS_PENDING,
            'summary' => null,
            'primary_catalyst' => null,
            'secondary_signals' => [],
            'evidence' => [],
            'confidence' => null,
            'caveats' => [],
            'unknowns' => [],
            'model_provider' => null,
            'model_name' => null,
            'generated_at' => null,
            'presented' => null,
            'cited_evidence' => [],
        ];

        if ($explanation === null) {
            return $pending;
        }

        if ($explanation->status !== PumpExplanation::STATUS_COMPLETED || ! is_array($explanation->explanation_json)) {
            return [
                ...$pending,
                'status' => $explanation->status,
                'model_provider' => $explanation->model_provider,
                'generated_at' => $explanation->generated_at?->toIso8601String(),
            ];
        }

        $json = $explanation->explanation_json;

        return [
            'status' => PumpExplanation::STATUS_COMPLETED,
            'summary' => $json['summary'] ?? null,
            'primary_catalyst' => $json['primary_catalyst'] ?? null,
            'secondary_signals' => $json['secondary_signals'] ?? [],
            'evidence' => $json['evidence'] ?? [],
            'confidence' => $json['confidence'] ?? $explanation->confidence,
            'caveats' => $json['caveats'] ?? [],
            'unknowns' => $json['unknowns'] ?? [],
            'model_provider' => $explanation->model_provider,
            'model_name' => $explanation->model_name,
            'generated_at' => $explanation->generated_at?->toIso8601String(),
            'presented' => $presenter->present($explanation),
            'cited_evidence' => $this->citedEvidence($event, $json),
        ];
    }

    /**
     * The evidence records the explanation actually cites, resolved from the
     * event's own evidence (already eager-loaded — no extra query).
     *
     * @param  array<string,mixed>  $json
     * @return list<array<string,mixed>>
     */
    private function citedEvidence(PumpEvent $event, array $json): array
    {
        $ids = [];
        foreach ((array) ($json['evidence'] ?? []) as $claim) {
            if (is_array($claim) && isset($claim['evidence_id'])) {
                $ids[] = (int) $claim['evidence_id'];
            }
        }
        foreach ((array) ($json['secondary_signals'] ?? []) as $signal) {
            foreach ((array) ($signal['evidence_ids'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));

        /** @var Collection<int, Evidence> $evidence */
        $evidence = $event->evidences ?? collect();

        return $evidence
            ->filter(fn (Evidence $e): bool => in_array((int) $e->id, $ids, true))
            ->map(fn (Evidence $e): array => [
                'id' => (int) $e->id,
                'category' => $e->category,
                'source' => $e->source,
                'source_url' => $e->source_url,
                'title' => $e->title,
                'summary' => $e->summary,
                'observed_at' => $e->observed_at?->toIso8601String(),
                'published_at' => $e->published_at?->toIso8601String(),
                'relevance_score' => (int) $e->relevance_score,
                'confidence' => $e->confidence,
            ])
            ->values()
            ->all();
    }

    /**
     * Age from earliest DEX pool creation to now (days, 2dp). NOT token deploy
     * time. Null when we never captured a pool-creation timestamp.
     */
    private function ageDays(): ?float
    {
        if ($this->earliest_pair_created_at === null) {
            return null;
        }

        $seconds = CarbonImmutable::now()->getTimestamp() - $this->earliest_pair_created_at->getTimestamp();

        return round($seconds / 86_400, 2);
    }
}
