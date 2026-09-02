<?php

declare(strict_types=1);

namespace App\Services\Historical;

/**
 * The outcome of running one token through {@see RecentlyCrossedQualifier}.
 *
 * `qualifies` is true only when every deterministic quality gate passed.
 * `rejectReason` is one concise code (the FIRST gate that failed) — for run
 * diagnostics / tests, never shown to end users.
 */
final readonly class RecentlyCrossedDecision
{
    public const REASON_DISCOVERY_STALE = 'discovery_stale';

    public const REASON_RISK_SCREEN_FAILED = 'risk_screen_failed';

    public const REASON_HOLDER_EVIDENCE_MISSING = 'holder_evidence_missing';

    public const REASON_HOLDER_ANOMALY = 'holder_anomaly';

    public const REASON_VOLUME_MISSING = 'volume_missing';

    public const REASON_VOLUME_TOO_THIN = 'volume_too_thin';

    public const REASON_LIQUIDITY_MISSING = 'liquidity_missing';

    public const REASON_LIQUIDITY_TOO_THIN = 'liquidity_too_thin';

    private function __construct(
        public bool $qualifies,
        public ?string $rejectReason,
    ) {}

    public static function pass(): self
    {
        return new self(true, null);
    }

    public static function reject(string $reason): self
    {
        return new self(false, $reason);
    }
}
