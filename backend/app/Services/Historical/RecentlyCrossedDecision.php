<?php

declare(strict_types=1);

namespace App\Services\Historical;

/**
 * The outcome of running one token through {@see RecentlyCrossedQualifier}.
 *
 * `qualifies` is true only when every deterministic quality gate passed.
 * `rejectReason` is one concise code (the FIRST gate that failed) — for run
 * diagnostics / tests, never shown to end users.
 *
 * `hardRedFlag` distinguishes a REJECT that should also REVOKE an existing
 * "previously approved" stamp (momentum anomaly, post-crossing collapse, an
 * unscreenable chain) from a SOFT miss (stale discovery, a gentle cool below
 * $5M, a covered-chain HIGH/CRITICAL rescreen) which keeps the stamp so the
 * token's Post-30-Day lineage survives. It is consulted ONLY by
 * {@see RecentlyCrossedApprovalMarker}'s revocation pass — never by the read
 * controller.
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

    public const REASON_MOMENTUM_ANOMALY = 'momentum_anomaly';

    public const REASON_POST_CROSSING_COLLAPSE = 'post_crossing_collapse';

    public const REASON_TOO_YOUNG = 'too_young';

    private function __construct(
        public bool $qualifies,
        public ?string $rejectReason,
        public bool $hardRedFlag = false,
    ) {}

    public static function pass(): self
    {
        return new self(true, null);
    }

    public static function reject(string $reason, bool $hardRedFlag = false): self
    {
        return new self(false, $reason, $hardRedFlag);
    }
}
