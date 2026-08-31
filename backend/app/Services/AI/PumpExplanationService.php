<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\PumpEvent;
use App\Models\PumpExplanation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates evidence-grounded AI explanations for recent pump events.
 *
 * For each recent event WITH evidence: rank + cap its evidence, ask the
 * configured {@see PumpExplanationProvider} for a structured interpretation,
 * validate it hard, and upsert one {@see PumpExplanation} row. Nothing else is
 * ever written — pump metrics, evidence, observed peak and historical
 * qualification are untouched.
 *
 * Failure (provider error or invalid output) => the row is stored `failed` with
 * a concise message and the run continues. No fabricated fallback.
 *
 * Regeneratable: `generated_at` + a cooldown; `--force` ignores the cooldown.
 */
class PumpExplanationService
{
    public function __construct(
        private readonly PumpExplanationProvider $provider,
        private readonly PumpExplanationPromptBuilder $promptBuilder,
        private readonly PumpExplanationValidator $validator,
    ) {}

    public function explain(bool $force = false): PumpExplanationRunResult
    {
        $startedAt = microtime(true);
        $now = CarbonImmutable::now();

        $recentCutoff = $now->subHours((int) config('ai.explanation.recent_event_hours', 48));
        $cooldownCutoff = $now->subHours((int) config('ai.explanation.cooldown_hours', 6));
        $maxEvents = max(1, (int) config('ai.explanation.max_events_per_run', 15));

        $events = PumpEvent::query()
            ->where('peak_at', '>=', $recentCutoff)
            ->with(['token', 'evidences', 'explanation'])
            ->orderByDesc('started_at')
            ->limit($maxEvents)
            ->get();

        $generated = 0;
        $failed = 0;
        $skippedCooldown = 0;
        $skippedNoEvidence = 0;

        foreach ($events as $event) {
            $evidence = $event->evidences;

            if ($evidence->isEmpty()) {
                // Never spend an AI call on an event with nothing to interpret.
                $skippedNoEvidence++;

                continue;
            }

            /** @var PumpExplanation|null $existing */
            $existing = $event->explanation;
            if (! $force
                && $existing !== null
                && $existing->status === PumpExplanation::STATUS_COMPLETED
                && $existing->generated_at !== null
                && $existing->generated_at->greaterThanOrEqualTo($cooldownCutoff)
            ) {
                $skippedCooldown++;

                continue;
            }

            $prompt = $this->promptBuilder->build($event, $evidence);
            $evidenceCount = count($prompt->suppliedEvidenceIds);

            try {
                $providerResult = $this->provider->generate($prompt);
                $validated = $this->validator->validate($providerResult->structuredOutput, $prompt->suppliedEvidenceIds);
            } catch (PumpExplanationProviderException|InvalidExplanationException $e) {
                $this->persistFailure($event, $existing, $evidenceCount, $e->getMessage());
                $failed++;
                Log::warning('Pump explanation failed', [
                    'pump_event_id' => $event->id,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            } catch (Throwable $e) {
                $this->persistFailure($event, $existing, $evidenceCount, 'Unexpected error: '.$e->getMessage());
                $failed++;
                Log::error('Pump explanation crashed', [
                    'pump_event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            PumpExplanation::query()->updateOrCreate(
                ['pump_event_id' => $event->id],
                [
                    'status' => PumpExplanation::STATUS_COMPLETED,
                    'summary' => $validated->summary,
                    'primary_catalyst' => $validated->primaryCatalyst,
                    'confidence' => $validated->confidence,
                    'explanation_json' => $validated->toArray(),
                    'evidence_count' => $evidenceCount,
                    'model_provider' => $this->provider->name(),
                    'model_name' => $providerResult->modelName,
                    'error_message' => null,
                    'generated_at' => $now,
                ],
            );
            $generated++;
        }

        $result = new PumpExplanationRunResult(
            eventsAnalyzed: $events->count(),
            explanationsGenerated: $generated,
            skippedCooldown: $skippedCooldown,
            skippedNoEvidence: $skippedNoEvidence,
            failed: $failed,
            durationSeconds: round(microtime(true) - $startedAt, 2),
        );

        Log::info('Pump explanation run completed', $result->toArray());

        return $result;
    }

    private function persistFailure(PumpEvent $event, ?PumpExplanation $existing, int $evidenceCount, string $message): void
    {
        // A prior GOOD explanation is never downgraded to `failed` by a
        // transient regeneration failure — keep it visible, just record the
        // error. Only a never-succeeded event becomes `failed`.
        $keepCompleted = $existing !== null
            && $existing->status === PumpExplanation::STATUS_COMPLETED
            && $existing->explanation_json !== null;

        PumpExplanation::query()->updateOrCreate(
            ['pump_event_id' => $event->id],
            [
                'status' => $keepCompleted ? PumpExplanation::STATUS_COMPLETED : PumpExplanation::STATUS_FAILED,
                'evidence_count' => $evidenceCount,
                'model_provider' => $this->provider->name(),
                'error_message' => mb_substr($message, 0, 1000),
            ],
        );
    }
}
