<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\RiskAssessment;
use App\Models\RiskSignal;
use App\Models\Token;
use Carbon\CarbonImmutable;

/**
 * The single source of truth for "does this market-cap-qualified token belong on
 * the MAIN LIST?" (Step 24).
 *
 * Used by `GET /api/memecoins` (main list). Market-cap qualification is assumed
 * to be already applied by the caller's query — this only adds the maturity +
 * risk-screening gate. It never calls a provider. A token that fails this gate
 * is excluded from the list (its assessment is still on its detail page).
 *
 * MAIN LIST requires ALL of:
 *   B. age >= MEMECOIN_MAIN_MIN_AGE_HOURS   (skipped when $requireMaturity is false)
 *   C. risk_level in {LOWER, MEDIUM}
 *   D. data completeness >= MEMECOIN_RISK_MIN_DATA_COMPLETENESS
 *   E. no CRITICAL hard failure
 *   F. no configured hard-risk (HIGH) failure
 *
 * `RecentlyCrossedQualifier` reuses this with `$requireMaturity = false` — the
 * "🔥 Recently Crossed $5M" section screens the SAME risk conditions (C–F) but
 * has no ≥72h maturity floor.
 */
final class MainListDecision
{
    /**
     * @param  list<string>  $reasons  human-facing reason codes when NOT eligible
     */
    private function __construct(
        public readonly bool $eligible,
        public readonly array $reasons,
        public readonly ?string $riskLevel,
        public readonly ?int $riskScore,
        public readonly ?float $dataCompleteness,
        public readonly ?RiskAssessment $assessment,
    ) {}

    public static function for(Token $token, ?CarbonImmutable $now = null, bool $requireMaturity = true): self
    {
        $now ??= CarbonImmutable::now();

        $minAgeHours = (int) config('risk.main_list.min_age_hours', 72);
        $requireScreening = (bool) config('risk.main_list.require_screening', true);

        $reasons = [];

        // B — maturity (only when the caller requires it — the Recently Crossed
        // screen reuses C–F without a maturity floor).
        $ageHours = $token->earliest_pair_created_at !== null
            ? ($now->getTimestamp() - $token->earliest_pair_created_at->getTimestamp()) / 3600.0
            : null;
        $matureEnough = ! $requireMaturity || ($ageHours !== null && $ageHours >= $minAgeHours);
        if (! $matureEnough) {
            $reasons[] = 'too_young';
        }

        /** @var RiskAssessment|null $assessment */
        $assessment = $token->relationLoaded('riskAssessment') ? $token->riskAssessment : $token->riskAssessment()->first();

        if ($assessment === null) {
            if ($requireScreening) {
                $reasons[] = 'not_screened';

                return new self(false, $reasons, RiskAssessment::LEVEL_UNKNOWN, null, null, null);
            }

            // Screening not required — maturity alone gates.
            return new self($matureEnough, $reasons, null, null, null, null);
        }

        $minCompleteness = (float) config('risk.min_data_completeness', 0.5);

        // C — level.
        if (! in_array($assessment->risk_level, RiskAssessment::MAIN_LIST_LEVELS, true)) {
            $reasons[] = match ($assessment->risk_level) {
                RiskAssessment::LEVEL_CRITICAL => 'risk_critical',
                RiskAssessment::LEVEL_HIGH => 'risk_high',
                default => 'risk_unknown',
            };
        }

        // D — data completeness.
        if ($assessment->screening_status !== RiskAssessment::STATUS_COMPLETED) {
            $reasons[] = 'screening_incomplete';
        }
        if ((float) $assessment->data_completeness < $minCompleteness) {
            $reasons[] = 'insufficient_security_data';
        }

        // E / F — a recorded hard override.
        if ($assessment->hard_override_signal !== null) {
            $reasons[] = 'hard_filter:'.$assessment->hard_override_signal;
        }

        $eligible = $matureEnough && $reasons === [];

        return new self(
            $eligible,
            array_values(array_unique($reasons)),
            $assessment->risk_level,
            $assessment->risk_score,
            (float) $assessment->data_completeness,
            $assessment,
        );
    }

    /**
     * Concise, pre-written phrases explaining why a token failed the MAIN LIST
     * gate (never dynamically generated prose). Retained for diagnostics.
     *
     * @return list<string>
     */
    public function reasonLabels(): array
    {
        $map = [
            'too_young' => 'Token is younger than the main-list maturity minimum.',
            'not_screened' => 'Risk unknown — not yet screened for security data.',
            'risk_critical' => 'Critical risk flag — avoid.',
            'risk_high' => 'High risk flags present.',
            'risk_unknown' => 'Risk unknown — insufficient security data.',
            'screening_incomplete' => 'Security screening did not complete.',
            'insufficient_security_data' => 'Risk unknown — insufficient security data.',
        ];

        return array_values(array_map(function (string $reason) use ($map): string {
            if (str_starts_with($reason, 'hard_filter:')) {
                return 'Hard safety filter failed: '.str_replace('hard_filter:', '', $reason).'.';
            }

            return $map[$reason] ?? $reason;
        }, $this->reasons));
    }

    /**
     * A compact "risk_summary" for the MAIN LIST rows — up to 3 short phrases
     * from the assessment's most severe measured signals. Empty for an
     * un-screened token.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        if ($this->assessment === null || ! $this->assessment->relationLoaded('signals')) {
            return [];
        }

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'none' => 4];

        return $this->assessment->signals
            ->filter(fn (RiskSignal $s): bool => in_array($s->state, [RiskSignal::STATE_BAD, RiskSignal::STATE_MEASURED], true)
                && in_array($s->severity, [RiskSignal::SEVERITY_MEDIUM, RiskSignal::SEVERITY_HIGH, RiskSignal::SEVERITY_CRITICAL], true))
            ->sortBy(fn (RiskSignal $s): int => $rank[$s->severity] ?? 9)
            ->take(3)
            ->map(fn (RiskSignal $s): string => (string) $s->explanation)
            ->values()
            ->all();
    }
}
