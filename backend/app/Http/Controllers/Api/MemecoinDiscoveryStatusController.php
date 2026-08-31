<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IngestionRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/memecoins/discovery-status
 *
 * Read-only discovery-coverage report. **PostgreSQL only** — never calls
 * DexScreener (or any external provider), never writes. Everything comes from
 * the `ingestion_runs` aggregate columns.
 */
class MemecoinDiscoveryStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $latest = IngestionRun::query()->latest('started_at')->latest('id')->first();

        $latestCompleted = ($latest?->status === IngestionRun::STATUS_COMPLETED)
            ? $latest
            : IngestionRun::query()
                ->where('status', IngestionRun::STATUS_COMPLETED)
                ->latest('started_at')->latest('id')->first();

        return response()->json([
            'data' => [
                'latest_run' => $this->runSummary($latest),
                'latest_completed_run' => $latest?->id === $latestCompleted?->id
                    ? null
                    : $this->runSummary($latestCompleted),
                'discovery' => $this->discovery($latestCompleted),
                'chains' => $latestCompleted?->chains_discovered ?? new \stdClass,
            ],
            'meta' => [
                'retrieved_at' => CarbonImmutable::now()->toIso8601String(),
                'source' => 'ingestion_runs',
                'coverage_note' => 'Activity- and keyword-driven sample across chains; not an exhaustive token census.',
            ],
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function runSummary(?IngestionRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function discovery(?IngestionRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'raw_candidates' => $run->raw_candidates,
            'unique_candidates' => $run->unique_candidates,
            'selected_for_enrichment' => $run->selected_for_enrichment,
            'candidate_cap_dropped' => $run->candidate_cap_dropped,
            'enriched_candidates' => $run->enriched_candidates,
            'age_eligible' => $run->age_eligible,
            'snapshots_written' => $run->snapshots_written,
            'qualified' => $run->qualified,
            'search_terms_used' => $run->search_terms_used,
            'search_terms_with_results' => $run->search_terms_with_results,
        ];
    }
}
