<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use Carbon\CarbonImmutable;

/**
 * Test helper (Step 24). A MAIN LIST token must now ALSO pass the risk screen,
 * so tests that assert on `GET /api/memecoins` stand up a risk assessment for
 * their fixture tokens with these helpers.
 */
trait CreatesRiskAssessments
{
    protected function passRisk(Token $token, string $level = RiskAssessment::LEVEL_LOWER, float $completeness = 1.0): RiskAssessment
    {
        return $this->makeRiskAssessment($token, [
            'risk_level' => $level,
            'risk_score' => $level === RiskAssessment::LEVEL_MEDIUM ? 30 : 8,
            'data_completeness' => $completeness,
            'screening_status' => RiskAssessment::STATUS_COMPLETED,
            'hard_override_signal' => null,
            'main_list_eligible' => true,
        ], [
            ['is_mintable', RiskSignal::GROUP_CONTRACT_SECURITY, RiskSignal::STATE_MEASURED, 'false', RiskSignal::SEVERITY_NONE, 'Mint is renounced / supply is fixed (measured).'],
            ['is_honeypot', RiskSignal::GROUP_EXIT_SAFETY, RiskSignal::STATE_MEASURED, 'false', RiskSignal::SEVERITY_NONE, 'Not simulated as a honeypot.'],
        ]);
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:string,4:string,5:string}>  $badSignals
     */
    protected function failRisk(Token $token, string $level = RiskAssessment::LEVEL_HIGH, array $badSignals = [], float $completeness = 1.0): RiskAssessment
    {
        $hardOverride = $level === RiskAssessment::LEVEL_CRITICAL ? 'is_honeypot'
            : ($level === RiskAssessment::LEVEL_HIGH ? 'is_mintable' : null);

        $signals = $badSignals !== [] ? $badSignals : match ($level) {
            RiskAssessment::LEVEL_CRITICAL => [['is_honeypot', RiskSignal::GROUP_EXIT_SAFETY, RiskSignal::STATE_BAD, 'true', RiskSignal::SEVERITY_CRITICAL, 'Simulated as a honeypot — the position cannot be sold.']],
            RiskAssessment::LEVEL_HIGH => [['is_mintable', RiskSignal::GROUP_CONTRACT_SECURITY, RiskSignal::STATE_BAD, 'true', RiskSignal::SEVERITY_HIGH, 'Supply can be minted — the top memecoin rug vector.']],
            default => [],
        };

        return $this->makeRiskAssessment($token, [
            'risk_level' => $level,
            'risk_score' => match ($level) {
                RiskAssessment::LEVEL_CRITICAL => 90,
                RiskAssessment::LEVEL_HIGH => 60,
                RiskAssessment::LEVEL_UNKNOWN => 10,
                default => 30,
            },
            'data_completeness' => $level === RiskAssessment::LEVEL_UNKNOWN ? min($completeness, 0.3) : $completeness,
            'screening_status' => $level === RiskAssessment::LEVEL_UNKNOWN ? RiskAssessment::STATUS_PARTIAL : RiskAssessment::STATUS_COMPLETED,
            'hard_override_signal' => $level === RiskAssessment::LEVEL_UNKNOWN ? null : $hardOverride,
            'main_list_eligible' => false,
        ], $signals);
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @param  list<array{0:string,1:string,2:string,3:string,4:string,5:string}>  $signals  [key, group, state, value, severity, explanation]
     */
    private function makeRiskAssessment(Token $token, array $attributes, array $signals): RiskAssessment
    {
        $now = CarbonImmutable::now();

        /** @var RiskAssessment $assessment */
        $assessment = RiskAssessment::query()->updateOrCreate(['token_id' => $token->id], [
            'screened_at' => $now,
            'provider_version' => 'test',
            ...$attributes,
        ]);

        $assessment->signals()->delete();
        foreach ($signals as [$key, $group, $state, $value, $severity, $explanation]) {
            $assessment->signals()->create([
                'token_id' => $token->id,
                'signal_key' => $key,
                'signal_group' => $group,
                'state' => $state,
                'value' => $value,
                'severity' => $severity,
                'source' => 'goplus',
                'source_checked_at' => $now,
                'explanation' => $explanation,
            ]);
        }

        $token->setRelation('riskAssessment', $assessment->load('signals'));

        return $assessment;
    }
}
