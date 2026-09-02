<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Evidence;
use App\Models\HistoricalPeakEvidence;
use App\Models\MarketSnapshot;
use App\Models\MonthlyRanking;
use App\Models\PumpEvent;
use App\Models\PumpExplanation;
use App\Models\QualificationEvent;
use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use App\Models\TokenNarrativeReport;
use App\Models\TokenNarrativeSource;
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

            // Step 20 — the "$5M crossing" timeline (when / how the token first
            // cleared the threshold). Empty `events` + null fields when no
            // crossing has been recorded (the read API never creates one).
            'qualification_timeline' => $this->qualificationTimeline($snapshot?->market_cap),

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

            // Step 21 — token-level narrative intelligence. Two separate
            // evidence-grounded syntheses (origin + popularity). Never triggers
            // research; `pending` when no report exists yet.
            'token_narrative' => $this->tokenNarrative(),

            // Step 22 — the calendar months this token won as "Meme Champion".
            // Read-only; `championships: []` when it has never won a month.
            'monthly_champion' => $this->monthlyChampion(),

            // Step 24 — the deterministic risk assessment. Read-only; NEVER
            // triggers screening. `status: "pending"` when not yet screened.
            // Never uses the word "safe".
            'risk_assessment' => $this->riskAssessment(),

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
     *   qualified = true  only for a VERIFIED / OBSERVED market-cap peak in
     *                     [$5M, $1B) (CURRENT_OBSERVATION or HISTORICAL_VERIFIED).
     *   peak_value        the qualifying market cap — NEVER an FDV estimate; null
     *                     for HISTORICAL_ESTIMATE / UNKNOWN / above-ceiling.
     *   status            the raw evidence label (all four possible on the detail
     *                     page); UNKNOWN means "not verified", NOT "never reached
     *                     the threshold".
     *   ineligible_reason "peak_above_ceiling" when a verified/observed peak
     *                     cleared $5M but reached or exceeded $1B; null otherwise.
     *
     * The FDV estimate, if any, is reported separately in `historical_estimate`.
     *
     * @return array{status:string,qualified:bool,peak_value:?float,peak_at:?string,source:?string,basis:?string,confidence:?string,ineligible_reason:?string}
     */
    private function qualification(): array
    {
        /** @var HistoricalPeakEvidence|null $evidence */
        $evidence = $this->historicalPeakEvidence;

        $min = (float) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $max = (float) config('dexscreener.filters.observed_peak_market_cap_max_usd');

        // Verified/observed market-cap qualification (peak in [$5M, $1B)).
        if ($evidence !== null && $evidence->qualifies($min, $max)) {
            return [
                'status' => $evidence->status,
                'qualified' => true,
                'peak_value' => $evidence->peak_value_usd,
                'peak_at' => $evidence->peak_observed_at?->toIso8601String(),
                'source' => $evidence->evidence_source,
                'basis' => $evidence->evidence_basis,
                'confidence' => $evidence->confidence,
                'ineligible_reason' => null,
            ];
        }

        // Derived CURRENT_OBSERVATION when there is no evidence row yet.
        if ($this->observed_peak_market_cap !== null
            && $this->observed_peak_market_cap >= $min
            && $this->observed_peak_market_cap < $max) {
            return [
                'status' => HistoricalPeakEvidence::STATUS_CURRENT_OBSERVATION,
                'qualified' => true,
                'peak_value' => $this->observed_peak_market_cap,
                'peak_at' => $this->observed_peak_market_cap_at?->toIso8601String(),
                'source' => HistoricalPeakEvidence::SOURCE_DEXSCREENER,
                'basis' => HistoricalPeakEvidence::BASIS_CURRENT_MARKET_CAP,
                'confidence' => 'high',
                'ineligible_reason' => null,
            ];
        }

        // Not qualified for the main list. Expose NO market-cap value here (an
        // FDV estimate is never a qualification market cap). Flag the
        // above-ceiling case so the reason is not silent.
        $aboveCeiling = ($evidence !== null && $evidence->peakAboveCeiling($min, $max))
            || ($this->observed_peak_market_cap !== null && $this->observed_peak_market_cap >= $max);

        return [
            'status' => $evidence?->status ?? HistoricalPeakEvidence::STATUS_UNKNOWN,
            'qualified' => false,
            'peak_value' => null,
            'peak_at' => null,
            'source' => null,
            'basis' => null,
            'confidence' => null,
            'ineligible_reason' => $aboveCeiling ? 'peak_above_ceiling' : null,
        ];
    }

    /**
     * The "$5M crossing" timeline (Step 20).
     *
     *   crossed_at / crossing_type / crossing_source / crossing_market_cap_value
     *     come from the REPRESENTATIVE event (HISTORICAL_VERIFIED over
     *     CURRENT_OBSERVATION).
     *   currently_below_threshold — the latest snapshot MC is under $5M (the
     *     token stays qualified anyway; the floor is a peak rule).
     *   events — every recorded crossing, strongest first.
     *
     * All-null / empty when no crossing has been recorded. The read API never
     * creates a crossing.
     *
     * @return array{crossed_at:?string,crossing_type:?string,crossing_source:?string,crossing_market_cap_value:?float,recently_crossed:bool,currently_below_threshold:?bool,threshold_usd:int,events:list<array<string,mixed>>}
     */
    private function qualificationTimeline(?float $currentMarketCap): array
    {
        $floor = (int) config('dexscreener.filters.observed_peak_market_cap_min_usd');
        $hours = (int) config('dexscreener.recent_crossing.hours');
        $cutoff = CarbonImmutable::now()->subHours($hours);

        /** @var Collection<int, QualificationEvent> $events */
        $events = ($this->relationLoaded('qualificationEvents') ? $this->qualificationEvents : collect())
            ->sort(fn ($a, $b): int => $a->precedenceRank() <=> $b->precedenceRank())
            ->values();

        $representative = $events->first();

        return [
            'crossed_at' => $representative?->crossed_at?->toIso8601String(),
            'crossing_type' => $representative?->type,
            'crossing_source' => $representative?->source,
            'crossing_market_cap_value' => $representative?->market_cap_value,
            'recently_crossed' => $representative?->crossed_at !== null
                && $representative->crossed_at->greaterThanOrEqualTo($cutoff),
            'currently_below_threshold' => $currentMarketCap === null ? null : $currentMarketCap < $floor,
            'threshold_usd' => $floor,
            'events' => $events->map(fn ($event): array => [
                'type' => $event->type,
                'crossed_at' => $event->crossed_at?->toIso8601String(),
                'source' => $event->source,
                'market_cap_value' => $event->market_cap_value,
            ])->all(),
        ];
    }

    /**
     * The (month, chain-bucket) slots this token led as "Monthly Chain Champion"
     * (Step 22, corrected).
     *
     * Read-only — the detail endpoint never recomputes a ranking. `is_champion`
     * is a convenience flag; `championships` is newest-month first and carries
     * the chain bucket + historical source/confidence. Never called "best
     * investment" / "best return" / "safest coin".
     *
     * @return array<string,mixed>
     */
    private function monthlyChampion(): array
    {
        /** @var Collection<int, MonthlyRanking> $rankings */
        $rankings = ($this->relationLoaded('monthlyRankings') ? $this->monthlyRankings : collect())
            ->filter(fn ($r): bool => $r->token_id !== null
                && in_array($r->status, MonthlyRanking::STATUSES_WITH_TOKEN, true));

        return [
            'is_champion' => $rankings->isNotEmpty(),
            'championships' => $rankings->map(fn ($r): array => [
                'year' => $r->year,
                'month' => $r->month,
                'month_name' => CarbonImmutable::create($r->year, $r->month, 1)->format('F'),
                'chain_bucket' => $r->chain_bucket,
                'rank' => (int) $r->rank,
                'status' => $r->status,
                'performance_score' => $r->performance_score,
                'holder_count' => $r->holder_count,               // null => UNKNOWN
                'monthly_volume' => $r->monthly_volume_usd,
                'market_cap' => $r->month_market_cap,             // month-peak observed/verified MC
                'market_cap_growth_pct' => $r->market_cap_growth_pct,   // info-only
                'observation_coverage_ratio' => $r->observation_coverage_ratio,
                'source_type' => $r->source_type,
                'source_reference' => $r->source_reference,
                'source_evidence' => $r->source_evidence ?? [],
                'age_uncertain' => (bool) $r->age_uncertain,
                'confidence' => $r->confidence,
                'finalized_at' => $r->finalized_at?->toIso8601String(),
            ])->sortBy(fn (array $c): string => sprintf('%04d%02d%d', $c['year'], $c['month'], $c['rank']))->values()->all(),
        ];
    }

    /**
     * Token-level narrative intelligence (Step 21).
     *
     * Read-only. NEVER triggers research. `origin` / `popularity` each report
     * their own status (`pending` when no report exists, `failed` without any
     * provider error detail). `sources` are the persisted, ranked source rows
     * the synthesis cites by id.
     *
     * @return array<string,mixed>
     */
    private function tokenNarrative(): array
    {
        /** @var TokenNarrativeReport|null $report */
        $report = $this->relationLoaded('narrativeReport') ? $this->narrativeReport : null;

        if ($report === null) {
            return [
                'status' => 'pending',
                'generated_at' => null,
                'origin' => $this->narrativeSectionPayload(null, 'pending'),
                'popularity' => $this->narrativeSectionPayload(null, 'pending'),
                'sources' => [],
            ];
        }

        /** @var Collection<int, TokenNarrativeSource> $sources */
        $sources = $report->relationLoaded('sources') ? $report->sources : collect();

        return [
            'status' => $report->overall_status,
            'generated_at' => $report->generated_at?->toIso8601String(),
            'model_provider' => $report->model_provider,
            'research_providers_used' => $report->research_providers_used ?? [],
            'origin' => $this->narrativeSectionPayload($report->origin_explanation_json, $report->origin_status),
            'popularity' => $this->narrativeSectionPayload($report->popularity_explanation_json, $report->popularity_status),
            'sources' => $sources
                ->sortBy('id')
                ->map(fn (TokenNarrativeSource $s): array => [
                    'id' => (int) $s->id,
                    'section' => $s->section,
                    'source_type' => $s->source_type,
                    'source_name' => $s->source_name,
                    'title' => $s->title,
                    'source_url' => $s->source_url,
                    'published_at' => $s->published_at?->toIso8601String(),
                    'confidence' => $s->confidence,
                    'claim' => $s->claim,
                    'relevance_score' => (int) $s->relevance_score,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $json
     * @return array<string,mixed>
     */
    private function narrativeSectionPayload(?array $json, string $status): array
    {
        $base = [
            'status' => $status,
            'headline' => null,
            'summary' => null,
            'confidence' => null,
            'caveats' => [],
            'unknowns' => [],
        ];

        if (! is_array($json) || $status !== 'completed') {
            // Only expose the validated body for a completed section.
            return $base;
        }

        return [
            ...$base,
            'headline' => $json['headline'] ?? null,
            'summary' => $json['summary'] ?? null,
            'origin_type' => $json['origin_type'] ?? null,
            'supporting_facts' => $json['supporting_facts'] ?? [],
            'timeline' => $json['timeline'] ?? [],
            'dominant_factors' => $json['dominant_factors'] ?? [],
            'confidence' => $json['confidence'] ?? null,
            'caveats' => $json['caveats'] ?? [],
            'unknowns' => $json['unknowns'] ?? [],
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
     * The Step 24 deterministic risk assessment.
     *
     * Read-only — NEVER triggers screening. `status: "pending"` when the token
     * has not been screened yet. Signals are grouped; each carries its
     * tri-state (`MEASURED` / `BAD` / `UNKNOWN` / `NOT_AVAILABLE`), severity,
     * source and checked-at time so the UI can show exactly which fields were
     * unavailable. No provider error detail is ever exposed. Never "safe".
     *
     * @return array<string,mixed>
     */
    private function riskAssessment(): array
    {
        /** @var RiskAssessment|null $assessment */
        $assessment = $this->relationLoaded('riskAssessment') ? $this->riskAssessment : null;

        if ($assessment === null) {
            return [
                'status' => 'pending',
                'risk_level' => null,
                'risk_score' => null,
                'data_completeness' => null,
                'screened_at' => null,
                'hard_override_signal' => null,
                'main_list_eligible' => false,
                'signals' => [],
                'disclaimer' => 'Risk screening is a heuristic filter, not a guarantee of safety. It is not investment advice.',
            ];
        }

        /** @var Collection<int, RiskSignal> $signals */
        $signals = $assessment->relationLoaded('signals') ? $assessment->signals : collect();

        return [
            'status' => $assessment->screening_status,
            'risk_level' => $assessment->risk_level,
            'risk_score' => $assessment->risk_score,
            'data_completeness' => round((float) $assessment->data_completeness, 3),
            'screened_at' => $assessment->screened_at?->toIso8601String(),
            'provider_version' => $assessment->provider_version,
            'hard_override_signal' => $assessment->hard_override_signal,
            'main_list_eligible' => (bool) $assessment->main_list_eligible,
            'signals' => $signals
                ->sortBy(['signal_group', 'signal_key'])
                ->map(fn (RiskSignal $s): array => [
                    'group' => $s->signal_group,
                    'key' => $s->signal_key,
                    'state' => $s->state,
                    'value' => $s->value,
                    'unit' => $s->unit,
                    'severity' => $s->severity,
                    'source' => $s->source,
                    'source_checked_at' => $s->source_checked_at?->toIso8601String(),
                    'explanation' => $s->explanation,
                ])
                ->values()
                ->all(),
            'disclaimer' => 'Risk screening is a heuristic filter, not a guarantee of safety. It is not investment advice.',
        ];
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
