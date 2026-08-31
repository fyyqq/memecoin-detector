<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Models\Token;
use App\Models\TokenNarrativeReport;
use App\Models\TokenNarrativeSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one narrative-research run (Step 21). For each token needing
 * research:
 *
 *   1. find sources (origin + popularity) from every available provider
 *   2. rank + de-duplicate + cap them
 *   3. persist them as token_narrative_sources (BEFORE any AI call)
 *   4. build the grounded prompt and ask the configured AI provider
 *   5. validate each section independently
 *   6. persist the report — `completed` / `partial` / `failed` per section
 *
 * Never fabricates a missing section. A provider outage (research or AI) never
 * destroys an existing good report and never fails the whole run.
 */
class NarrativeResearchService
{
    public function __construct(
        private readonly TokenOriginResearchService $originResearch,
        private readonly TokenPopularityResearchService $popularityResearch,
        private readonly NarrativeSourceRanker $ranker,
        private readonly NarrativeEvidenceRecorder $recorder,
        private readonly NarrativePromptBuilder $promptBuilder,
        private readonly NarrativeExplanationService $explanation,
    ) {}

    /**
     * @param  list<string>  $onlyTokenKeys  optional "chain:address" filter (live verification)
     */
    public function research(bool $force = false, array $onlyTokenKeys = []): NarrativeResearchRunResult
    {
        $startedAt = microtime(true);
        $now = CarbonImmutable::now();

        $cooldownHours = max(1, (int) config('narrative.research.cooldown_hours', 24));
        $cooldownCutoff = $now->subHours($cooldownHours);
        $maxTokens = max(1, (int) config('narrative.research.max_tokens_per_run', 10));
        $cap = max(1, (int) config('narrative.research.max_sources_per_section', 12));
        $minSources = max(0, (int) config('narrative.research.min_sources_per_section', 1));

        $tokens = $this->tokensNeedingResearch($maxTokens, $onlyTokenKeys);

        $completed = 0;
        $partial = 0;
        $failed = 0;
        $skippedCooldown = 0;
        $sourcesRecorded = 0;
        $providerFailures = 0;

        foreach ($tokens as $token) {
            /** @var TokenNarrativeReport $report */
            $report = TokenNarrativeReport::query()->firstOrNew(['token_id' => $token->id]);

            // Cooldown: skip a token whose last research attempt OR last
            // successful generation is still within the window.
            $withinCooldown = $report->exists
                && (
                    ($report->research_started_at !== null && $report->research_started_at->greaterThanOrEqualTo($cooldownCutoff))
                    || ($report->generated_at !== null && $report->generated_at->greaterThanOrEqualTo($cooldownCutoff))
                );

            if (! $force && $withinCooldown) {
                $skippedCooldown++;

                continue;
            }

            $report->fill(['research_started_at' => $now, 'research_completed_at' => null])->save();

            try {
                [$stats, $status] = $this->researchToken($token, $report, $now, $cap, $minSources);
            } catch (Throwable $e) {
                Log::error('Narrative research crashed for token', ['token_id' => $token->id, 'error' => $e->getMessage()]);
                $this->recordCrash($report, $e->getMessage(), $now);
                $failed++;

                continue;
            }

            $sourcesRecorded += $stats['sources'];
            $providerFailures += $stats['provider_failures'];

            match ($status) {
                TokenNarrativeReport::STATUS_COMPLETED => $completed++,
                TokenNarrativeReport::STATUS_PARTIAL => $partial++,
                default => $failed++,
            };
        }

        $result = new NarrativeResearchRunResult(
            tokensConsidered: $tokens->count(),
            completed: $completed,
            partial: $partial,
            failed: $failed,
            skippedCooldown: $skippedCooldown,
            sourcesRecorded: $sourcesRecorded,
            providerFailures: $providerFailures,
            durationSeconds: round(microtime(true) - $startedAt, 2),
        );

        Log::info('Narrative research run completed', $result->toArray());

        return $result;
    }

    /**
     * @return array{0:array{sources:int,provider_failures:int},1:string} [stats, overall status]
     */
    private function researchToken(Token $token, TokenNarrativeReport $report, CarbonImmutable $now, int $cap, int $minSources): array
    {
        $origin = $this->originResearch->research($token, $now);
        $popularity = $this->popularityResearch->research($token, $now);

        $providersUsed = array_values(array_unique([...$origin->providersUsed, ...$popularity->providersUsed]));
        $providerFailures = array_values(array_unique([...$origin->providerFailures, ...$popularity->providerFailures]));

        $originRanked = $this->ranker->rank($origin->candidates, $cap);
        $popularityRanked = $this->ranker->rank($popularity->candidates, $cap);

        $originRows = $this->recorder->record($report, $token, $originRanked, $now);
        $this->recorder->pruneSection($report, TokenNarrativeSource::SECTION_ORIGIN, $originRows);

        $popularityRows = $this->recorder->record($report, $token, $popularityRanked, $now);
        $this->recorder->pruneSection($report, TokenNarrativeSource::SECTION_POPULARITY, $popularityRows);

        // Re-read persisted rows so ids are stable + ordering is deterministic.
        $originRows = $report->sources()->where('section', TokenNarrativeSource::SECTION_ORIGIN)->orderBy('id')->get();
        $popularityRows = $report->sources()->where('section', TokenNarrativeSource::SECTION_POPULARITY)->orderBy('id')->get();
        $sourcesCount = $originRows->count() + $popularityRows->count();

        $prompt = $this->promptBuilder->build($token, $originRows, $popularityRows);

        $priorOriginOk = $report->origin_status === TokenNarrativeReport::STATUS_COMPLETED && is_array($report->origin_explanation_json);
        $priorPopularityOk = $report->popularity_status === TokenNarrativeReport::STATUS_COMPLETED && is_array($report->popularity_explanation_json);

        try {
            $synthesis = $this->explanation->synthesize($prompt);
        } catch (NarrativeExplanationProviderException $e) {
            // AI unavailable — keep any existing good section, mark the rest failed.
            // Sources are already persisted (research output, not AI output).
            $report->fill([
                'origin_status' => $priorOriginOk ? TokenNarrativeReport::STATUS_COMPLETED : TokenNarrativeReport::STATUS_FAILED,
                'popularity_status' => $priorPopularityOk ? TokenNarrativeReport::STATUS_COMPLETED : TokenNarrativeReport::STATUS_FAILED,
                'research_providers_used' => $providersUsed,
                'research_completed_at' => $now,
                'error_message' => 'Narrative AI synthesis unavailable.',
            ]);
            $overall = $this->overallStatus($report->origin_status, $report->popularity_status);
            $report->overall_status = $overall;
            $report->overall_confidence = $this->overallConfidence($report);
            $report->save();

            return [['sources' => $sourcesCount, 'provider_failures' => count($providerFailures) + 1], $overall];
        }

        $originStatus = $synthesis->originOk() ? TokenNarrativeReport::STATUS_COMPLETED : TokenNarrativeReport::STATUS_FAILED;
        $popularityStatus = $synthesis->popularityOk() ? TokenNarrativeReport::STATUS_COMPLETED : TokenNarrativeReport::STATUS_FAILED;

        // Preserve a previously-good section if this run failed to reproduce it.
        if ($originStatus === TokenNarrativeReport::STATUS_FAILED && $priorOriginOk) {
            $originStatus = TokenNarrativeReport::STATUS_COMPLETED;
        }
        if ($popularityStatus === TokenNarrativeReport::STATUS_FAILED && $priorPopularityOk) {
            $popularityStatus = TokenNarrativeReport::STATUS_COMPLETED;
        }

        $report->fill([
            'origin_status' => $originStatus,
            'origin_summary' => $synthesis->originData['summary'] ?? $report->origin_summary,
            'origin_explanation_json' => $synthesis->originData ?? $report->origin_explanation_json,
            'popularity_status' => $popularityStatus,
            'popularity_summary' => $synthesis->popularityData['summary'] ?? $report->popularity_summary,
            'popularity_explanation_json' => $synthesis->popularityData ?? $report->popularity_explanation_json,
            'model_provider' => $synthesis->providerName,
            'model_name' => $synthesis->modelName,
            'research_providers_used' => $providersUsed,
            'generated_at' => $now,
            'research_completed_at' => $now,
            'error_message' => $this->sectionErrorNote($synthesis),
        ]);

        $overall = $this->overallStatus($originStatus, $popularityStatus);
        $report->overall_status = $overall;
        $report->overall_confidence = $this->overallConfidence($report);
        $report->save();

        return [['sources' => $sourcesCount, 'provider_failures' => count($providerFailures)], $overall];
    }

    /**
     * @param  list<string>  $onlyTokenKeys
     * @return Collection<int, Token>
     */
    private function tokensNeedingResearch(int $maxTokens, array $onlyTokenKeys): Collection
    {
        $query = Token::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            // "notable" tokens only — those with a $5M crossing or an observed pump.
            ->where(function (Builder $q): void {
                $q->whereHas('qualificationEvents')->orWhereHas('pumpEvents');
            })
            ->with('narrativeReport');

        if ($onlyTokenKeys !== []) {
            $query->where(function (Builder $q) use ($onlyTokenKeys): void {
                foreach ($onlyTokenKeys as $key) {
                    [$chain, $addr] = array_pad(explode(':', $key, 2), 2, '');
                    if ($chain === '' || $addr === '') {
                        continue;
                    }
                    $q->orWhere(function (Builder $inner) use ($chain, $addr): void {
                        $inner->whereRaw('lower(chain_id) = ?', [mb_strtolower(trim($chain))])
                            ->whereRaw('lower(token_address) = ?', [mb_strtolower(trim($addr))]);
                    });
                }
            });
        }

        // Candidate tokens, never-attempted first, then oldest research first.
        // The run loop applies the per-token cooldown + counts the skips.
        return $query->get()
            ->sortBy(fn (Token $token): int => $token->narrativeReport?->research_started_at?->getTimestamp() ?? 0)
            ->take($maxTokens)
            ->values();
    }

    private function recordCrash(TokenNarrativeReport $report, string $message, CarbonImmutable $now): void
    {
        $priorOriginOk = $report->origin_status === TokenNarrativeReport::STATUS_COMPLETED;
        $priorPopularityOk = $report->popularity_status === TokenNarrativeReport::STATUS_COMPLETED;

        $report->fill([
            'origin_status' => $priorOriginOk ? TokenNarrativeReport::STATUS_COMPLETED : TokenNarrativeReport::STATUS_FAILED,
            'popularity_status' => $priorPopularityOk ? TokenNarrativeReport::STATUS_COMPLETED : TokenNarrativeReport::STATUS_FAILED,
            'research_completed_at' => $now,
            'error_message' => 'Narrative research error: '.mb_substr($message, 0, 400),
        ]);
        $report->overall_status = $this->overallStatus($report->origin_status, $report->popularity_status);
        $report->save();
    }

    private function overallStatus(string $origin, string $popularity): string
    {
        $completed = TokenNarrativeReport::STATUS_COMPLETED;

        return match (true) {
            $origin === $completed && $popularity === $completed => TokenNarrativeReport::STATUS_COMPLETED,
            $origin === $completed || $popularity === $completed => TokenNarrativeReport::STATUS_PARTIAL,
            default => TokenNarrativeReport::STATUS_FAILED,
        };
    }

    private function overallConfidence(TokenNarrativeReport $report): ?string
    {
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
        $values = [];
        foreach ([$report->origin_explanation_json, $report->popularity_explanation_json] as $section) {
            if (is_array($section) && isset($section['confidence']) && isset($rank[$section['confidence']])) {
                $values[] = $section['confidence'];
            }
        }
        if ($values === []) {
            return null;
        }
        usort($values, static fn (string $a, string $b): int => $rank[$a] <=> $rank[$b]);

        return $values[0];
    }

    private function sectionErrorNote(NarrativeSynthesisResult $synthesis): ?string
    {
        $notes = [];
        if ($synthesis->originError !== null) {
            $notes[] = 'origin: '.$synthesis->originError;
        }
        if ($synthesis->popularityError !== null) {
            $notes[] = 'popularity: '.$synthesis->popularityError;
        }

        return $notes === [] ? null : mb_substr(implode(' | ', $notes), 0, 800);
    }
}
