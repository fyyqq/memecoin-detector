<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use App\Models\Evidence;
use App\Models\PumpEvent;
use App\Services\Evidence\Collectors\MarketEvidenceCollector;
use App\Services\Evidence\Collectors\NewsEvidenceCollector;
use App\Services\Evidence\Collectors\RelatedTokenEvidenceCollector;
use App\Services\Evidence\Collectors\TokenMetadataEvidenceCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates evidence collection around recent {@see PumpEvent}s.
 *
 * WHAT it does: for each recent event, build the bounded investigation window,
 * run every collector, persist the facts they return, stamp the event as
 * investigated. WHAT it never does: interpret those facts or assert causality
 * (that is Step 16C).
 *
 * Resilience: a collector that throws is logged, counted as a provider failure,
 * and skipped — the run continues. Idempotency: the per-event cooldown plus the
 * recorder's dedupe key mean a 10-minute scheduler cadence does not pile up
 * duplicates.
 */
class EvidenceCollectionService
{
    public function __construct(
        private readonly EvidenceRecorder $recorder,
        private readonly MarketEvidenceCollector $market,
        private readonly TokenMetadataEvidenceCollector $tokenMetadata,
        private readonly RelatedTokenEvidenceCollector $relatedToken,
        private readonly NewsEvidenceCollector $news,
    ) {}

    public function collect(bool $force = false): EvidenceCollectionResult
    {
        $startedAt = microtime(true);
        $now = CarbonImmutable::now();

        $recentHours = (int) config('evidence.recent_event_hours', 48);
        $cooldownHours = (int) config('evidence.collection_cooldown_hours', 2);
        $maxEvents = max(1, (int) config('evidence.max_events_per_run', 20));
        $cooldownCutoff = $now->subHours($cooldownHours);
        $recentCutoff = $now->subHours($recentHours);

        $this->news->resetBudget();

        $skippedByCooldown = $force ? 0 : PumpEvent::query()
            ->where('peak_at', '>=', $recentCutoff)
            ->whereNotNull('evidence_collected_at')
            ->where('evidence_collected_at', '>=', $cooldownCutoff)
            ->count();

        $events = PumpEvent::query()
            ->where('peak_at', '>=', $recentCutoff)
            ->when(! $force, fn ($q) => $q->where(function ($inner) use ($cooldownCutoff) {
                $inner->whereNull('evidence_collected_at')
                    ->orWhere('evidence_collected_at', '<', $cooldownCutoff);
            }))
            ->with('token')
            ->orderByDesc('started_at')
            ->limit($maxEvents)
            ->get();

        /** @var list<EvidenceCollector> $collectors */
        $collectors = [$this->market, $this->tokenMetadata, $this->relatedToken, $this->news];

        $recordsByCategory = [];
        $newRecords = 0;
        $eventsWithNew = 0;
        $providerFailures = 0;

        foreach ($events as $event) {
            $token = $event->token;
            if ($token === null) {
                continue;
            }

            $window = EvidenceWindow::for($event);
            $eventHadNew = false;

            foreach ($collectors as $collector) {
                try {
                    $candidates = $collector->collect($event, $token, $window);
                } catch (Throwable $e) {
                    $providerFailures++;
                    Log::warning('Evidence collector failed', [
                        'collector' => $collector->name(),
                        'pump_event_id' => $event->id,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                if ($collector instanceof NewsEvidenceCollector && $this->news->lastProviderCallFailed()) {
                    $providerFailures++;
                }

                foreach ($candidates as $candidate) {
                    $evidence = $this->recorder->record($event, $token, $candidate);
                    $recordsByCategory[$candidate->category] = ($recordsByCategory[$candidate->category] ?? 0) + 1;

                    if ($evidence->wasRecentlyCreated) {
                        $newRecords++;
                        $eventHadNew = true;
                    }
                }
            }

            $event->forceFill(['evidence_collected_at' => $now])->save();

            if ($eventHadNew) {
                $eventsWithNew++;
            }
        }

        $result = new EvidenceCollectionResult(
            eventsAnalyzed: $events->count(),
            eventsSkippedByCooldown: $skippedByCooldown,
            eventsWithNewEvidence: $eventsWithNew,
            recordsByCategory: $recordsByCategory,
            totalEvidenceRecords: array_sum($recordsByCategory),
            newEvidenceRecords: $newRecords,
            providerFailures: $providerFailures,
            durationSeconds: round(microtime(true) - $startedAt, 2),
        );

        Log::info('Evidence collection completed', $result->toArray());

        return $result;
    }

    /**
     * Category keys the command prints even when zero rows were produced.
     *
     * @return list<string>
     */
    public static function reportableCategories(): array
    {
        return [
            Evidence::CATEGORY_NEWS,
            Evidence::CATEGORY_MARKET,
            Evidence::CATEGORY_RELATED_TOKEN,
            Evidence::CATEGORY_ORIGIN,
            Evidence::CATEGORY_TOKEN_METADATA,
        ];
    }
}
