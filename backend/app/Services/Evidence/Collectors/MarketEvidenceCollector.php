<?php

declare(strict_types=1);

namespace App\Services\Evidence\Collectors;

use App\Models\Evidence;
use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use App\Models\Token;
use App\Services\Evidence\EvidenceCandidate;
use App\Services\Evidence\EvidenceCollector;
use App\Services\Evidence\EvidenceWindow;

/**
 * MARKET evidence — describes the OBSERVABLE market behaviour of the pump.
 *
 * PostgreSQL only, **no external HTTP**. Reads the PumpEvent's own metrics plus
 * the token's snapshots inside the investigation window. Every record is a
 * neutral fact — market activity is NEVER called "the cause".
 */
class MarketEvidenceCollector implements EvidenceCollector
{
    public function name(): string
    {
        return 'market';
    }

    public function isExternal(): bool
    {
        return false;
    }

    /**
     * @return list<EvidenceCandidate>
     */
    public function collect(PumpEvent $event, Token $token, EvidenceWindow $window): array
    {
        /** @var list<MarketSnapshot> $snapshots */
        $snapshots = $token->marketSnapshots()
            ->whereBetween('observed_at', [$window->investigationStart, $window->investigationEnd])
            ->orderBy('observed_at')
            ->orderBy('id')
            ->limit(60)
            ->get()
            ->all();

        $out = [];
        $observedAt = $event->peak_at;
        $ref = 'pump_event:'.$event->id;

        // 1. Market-cap move (from the event's own start → peak figures).
        if ($event->start_market_cap !== null && $event->start_market_cap > 0 && $event->peak_market_cap !== null) {
            $x = $event->peak_market_cap / $event->start_market_cap;
            $out[] = new EvidenceCandidate(
                category: Evidence::CATEGORY_MARKET,
                source: 'internal',
                sourceUrl: null,
                title: 'Observed market-cap move',
                observedAt: $observedAt,
                publishedAt: null,
                relevanceScore: 100,
                confidence: Evidence::CONFIDENCE_HIGH,
                summary: sprintf(
                    'Observed market cap increased %.1f× (from $%s to $%s) between the event start and peak over %d minutes.',
                    $x,
                    $this->money($event->start_market_cap),
                    $this->money($event->peak_market_cap),
                    (int) ($event->duration_minutes ?? 0),
                ),
                rawReference: $ref,
            );
        }

        // 2. Price move.
        if ($event->start_price_usd !== null && $event->start_price_usd > 0 && $event->peak_price_usd !== null) {
            $x = $event->peak_price_usd / $event->start_price_usd;
            $out[] = new EvidenceCandidate(
                category: Evidence::CATEGORY_MARKET,
                source: 'internal',
                sourceUrl: null,
                title: 'Observed price move',
                observedAt: $observedAt,
                publishedAt: null,
                relevanceScore: 95,
                confidence: Evidence::CONFIDENCE_HIGH,
                summary: sprintf(
                    'Observed USD price increased %.1f× between the event start and peak.',
                    $x,
                ),
                rawReference: $ref,
            );
        }

        // 3. Rolling-24h volume & transaction change ratios (labelled as rolling).
        if ($event->volume_h24_change_ratio !== null || $event->txns_h24_change_ratio !== null) {
            $parts = [];
            if ($event->volume_h24_change_ratio !== null) {
                $parts[] = sprintf('rolling 24h volume ratio %.2f×', $event->volume_h24_change_ratio);
            }
            if ($event->txns_h24_change_ratio !== null) {
                $parts[] = sprintf('rolling 24h transaction ratio %.2f×', $event->txns_h24_change_ratio);
            }
            $out[] = new EvidenceCandidate(
                category: Evidence::CATEGORY_MARKET,
                source: 'internal',
                sourceUrl: null,
                title: 'Rolling-24h activity change',
                observedAt: $observedAt,
                publishedAt: null,
                relevanceScore: 72,
                confidence: Evidence::CONFIDENCE_MEDIUM,
                summary: 'Between the event start and peak observations: '.implode(', ', $parts)
                    .'. These compare rolling 24-hour metrics, not interval volume or transaction counts.',
                rawReference: $ref,
            );
        }

        // 4. Liquidity + order-flow context at the peak observation.
        $peakSnapshot = $this->closestSnapshot($snapshots, $event->peak_at?->getTimestamp());
        $startSnapshot = $this->closestSnapshot($snapshots, $event->started_at?->getTimestamp());
        if ($peakSnapshot !== null) {
            $bits = [];
            if ($peakSnapshot->liquidity_usd !== null) {
                $bits[] = 'liquidity $'.$this->money($peakSnapshot->liquidity_usd)
                    .($startSnapshot?->liquidity_usd !== null ? ' (event-start observation: $'.$this->money($startSnapshot->liquidity_usd).')' : '');
            }
            if ($peakSnapshot->buys_h24 !== null && $peakSnapshot->sells_h24 !== null) {
                $total = $peakSnapshot->buys_h24 + $peakSnapshot->sells_h24;
                $pct = $total > 0 ? round($peakSnapshot->buys_h24 / $total * 100) : 0;
                $bits[] = sprintf('rolling 24h order flow %d buys / %d sells (%d%% buys)', $peakSnapshot->buys_h24, $peakSnapshot->sells_h24, $pct);
            }
            if ($bits !== []) {
                $out[] = new EvidenceCandidate(
                    category: Evidence::CATEGORY_MARKET,
                    source: 'internal',
                    sourceUrl: null,
                    title: 'Liquidity & order-flow context',
                    observedAt: $peakSnapshot->observed_at,
                    publishedAt: null,
                    relevanceScore: 60,
                    confidence: Evidence::CONFIDENCE_MEDIUM,
                    summary: 'At the peak observation: '.implode('; ', $bits).'.',
                    rawReference: 'market_snapshot:'.$peakSnapshot->id,
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<MarketSnapshot>  $snapshots
     */
    private function closestSnapshot(array $snapshots, ?int $targetTs): ?MarketSnapshot
    {
        if ($snapshots === [] || $targetTs === null) {
            return $snapshots[count($snapshots) - 1] ?? null;
        }

        $best = null;
        $bestDelta = PHP_INT_MAX;
        foreach ($snapshots as $s) {
            $delta = abs($s->observed_at->getTimestamp() - $targetTs);
            if ($delta < $bestDelta) {
                $best = $s;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    private function money(float $value): string
    {
        $abs = abs($value);

        return match (true) {
            $abs >= 1_000_000_000 => number_format($value / 1_000_000_000, 2).'B',
            $abs >= 1_000_000 => number_format($value / 1_000_000, 2).'M',
            $abs >= 1_000 => number_format($value / 1_000, 1).'K',
            default => number_format($value, 0),
        };
    }
}
