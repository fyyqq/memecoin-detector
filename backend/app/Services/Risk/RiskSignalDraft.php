<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\RiskSignal;
use Carbon\CarbonImmutable;

/**
 * One evaluated risk signal, before persistence. Immutable value object.
 *
 * Every signal is tri-state (+ NOT_AVAILABLE): MEASURED / BAD / UNKNOWN /
 * NOT_AVAILABLE. `contributesToScore()` gates which signals feed
 * {@see RiskScoreCalculator} — UNKNOWN and NOT_AVAILABLE never do.
 */
final class RiskSignalDraft
{
    /**
     * @param  RiskSignal::GROUP_*  $group
     * @param  RiskSignal::STATE_*  $state
     * @param  RiskSignal::SEVERITY_*  $severity
     * @param  float|null  $scoreContribution  0..1 fraction of this signal's dimension budget it uses
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $state,
        public readonly ?string $value,
        public readonly ?float $numericValue,
        public readonly ?string $unit,
        public readonly string $severity,
        public readonly ?string $source,
        public readonly ?CarbonImmutable $sourceCheckedAt,
        public readonly string $explanation,
        public readonly ?float $scoreContribution = null,
        public readonly bool $hardOverride = false,
        public readonly ?string $hardOverrideLevel = null,
        public readonly bool $applicable = true,
    ) {}

    public static function measured(
        string $key,
        string $group,
        ?string $value,
        ?float $numericValue,
        ?string $unit,
        string $severity,
        ?string $source,
        ?CarbonImmutable $checkedAt,
        string $explanation,
        ?float $scoreContribution = null,
        bool $hardOverride = false,
        ?string $hardOverrideLevel = null,
    ): self {
        return new self($key, $group, RiskSignal::STATE_MEASURED, $value, $numericValue, $unit, $severity, $source, $checkedAt, $explanation, $scoreContribution, $hardOverride, $hardOverrideLevel);
    }

    public static function bad(
        string $key,
        string $group,
        ?string $value,
        ?float $numericValue,
        string $severity,
        ?string $source,
        ?CarbonImmutable $checkedAt,
        string $explanation,
        float $scoreContribution = 1.0,
        bool $hardOverride = false,
        ?string $hardOverrideLevel = null,
    ): self {
        return new self($key, $group, RiskSignal::STATE_BAD, $value, $numericValue, null, $severity, $source, $checkedAt, $explanation, $scoreContribution, $hardOverride, $hardOverrideLevel);
    }

    public static function unknown(string $key, string $group, string $explanation, ?string $source = null, bool $applicable = true): self
    {
        return new self($key, $group, RiskSignal::STATE_UNKNOWN, null, null, null, RiskSignal::SEVERITY_NONE, $source, null, $explanation, null, false, null, $applicable);
    }

    public static function notAvailable(string $key, string $group, string $explanation): self
    {
        return new self($key, $group, RiskSignal::STATE_NOT_AVAILABLE, '—', null, null, RiskSignal::SEVERITY_NONE, null, null, $explanation, null, false, null, false);
    }

    /** A signal counts toward data completeness only if it is applicable to this token/chain. */
    public function countsForCompleteness(): bool
    {
        return $this->applicable && $this->state !== RiskSignal::STATE_NOT_AVAILABLE;
    }

    public function wasMeasured(): bool
    {
        return in_array($this->state, [RiskSignal::STATE_MEASURED, RiskSignal::STATE_BAD], true);
    }

    public function contributesToScore(): bool
    {
        return $this->state === RiskSignal::STATE_BAD
            || ($this->state === RiskSignal::STATE_MEASURED && $this->scoreContribution !== null && $this->scoreContribution > 0.0);
    }

    /** @return array<string,mixed> */
    public function toRow(): array
    {
        return [
            'signal_key' => $this->key,
            'signal_group' => $this->group,
            'state' => $this->state,
            'value' => $this->value,
            'numeric_value' => $this->numericValue,
            'unit' => $this->unit,
            'severity' => $this->severity,
            'source' => $this->source,
            'source_checked_at' => $this->sourceCheckedAt,
            'explanation' => $this->explanation,
        ];
    }
}
