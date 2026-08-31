<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Models\Token;
use App\Models\TokenNarrativeReport;
use App\Models\TokenNarrativeSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Persists ranked {@see NarrativeSourceCandidate}s as {@see TokenNarrativeSource}
 * rows, idempotently.
 *
 * Keyed on `(token_narrative_report_id, dedupe_hash)` so re-research refreshes a
 * row instead of duplicating it. Sources are persisted BEFORE the AI call — they
 * are research output, not model output, and must survive an AI failure.
 */
class NarrativeEvidenceRecorder
{
    /**
     * @param  list<NarrativeSourceCandidate>  $candidates
     * @return Collection<int, TokenNarrativeSource> persisted rows (with ids)
     */
    public function record(TokenNarrativeReport $report, Token $token, array $candidates, CarbonImmutable $now): Collection
    {
        $rows = collect();

        foreach ($candidates as $candidate) {
            /** @var TokenNarrativeSource $row */
            $row = TokenNarrativeSource::query()->updateOrCreate(
                [
                    'token_narrative_report_id' => $report->id,
                    'dedupe_hash' => $candidate->dedupeHash(),
                ],
                [
                    ...$candidate->toAttributes($now),
                    'token_id' => $token->id,
                ],
            );
            $rows->push($row);
        }

        return $rows;
    }

    /**
     * Remove stale source rows for a section that this run did not re-produce,
     * so the persisted set matches the latest research.
     *
     * @param  Collection<int, TokenNarrativeSource>  $kept
     */
    public function pruneSection(TokenNarrativeReport $report, string $section, Collection $kept): void
    {
        $keepIds = $kept->pluck('id')->all();

        TokenNarrativeSource::query()
            ->where('token_narrative_report_id', $report->id)
            ->where('section', $section)
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }
}
