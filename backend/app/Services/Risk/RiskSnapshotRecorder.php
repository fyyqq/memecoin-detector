<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists a {@see RiskAssessmentResult} for a token (Step 24).
 *
 * One CURRENT assessment per token (upserted on `token_id`); the full signal
 * set is REPLACED on every rescan so there is never an uncontrolled duplicate.
 * No provider payloads are stored — only the concise structured signals.
 */
class RiskSnapshotRecorder
{
    public function record(Token $token, RiskAssessmentResult $result, CarbonImmutable $now): RiskAssessment
    {
        $providerVersion = (string) config('risk.run.provider_version', 'risk');

        return DB::transaction(function () use ($token, $result, $now, $providerVersion): RiskAssessment {
            /** @var RiskAssessment $assessment */
            $assessment = RiskAssessment::query()->updateOrCreate(
                ['token_id' => $token->id],
                [
                    'risk_level' => $result->level,
                    'risk_score' => $result->score,
                    'data_completeness' => $result->dataCompleteness,
                    'screening_status' => $result->screeningStatus,
                    'hard_override_signal' => $result->hardOverrideSignal,
                    'main_list_eligible' => $result->mainListEligible,
                    'screened_at' => $now,
                    'provider_version' => $providerVersion,
                    'notes' => mb_substr($result->notes, 0, 500) ?: null,
                ],
            );

            $assessment->signals()->delete();

            $rows = [];
            foreach ($result->signals as $draft) {
                $rows[] = [
                    ...$draft->toRow(),
                    'source_checked_at' => $draft->sourceCheckedAt,
                    'risk_assessment_id' => $assessment->id,
                    'token_id' => $token->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                RiskSignal::query()->insert($rows);
            }

            return $assessment;
        });
    }
}
