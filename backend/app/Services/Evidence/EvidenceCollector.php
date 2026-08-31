<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use App\Models\PumpEvent;
use App\Models\Token;

/**
 * A collector gathers timestamped FACTS present around a PumpEvent inside the
 * {@see EvidenceWindow}. It never interprets and never claims causality.
 */
interface EvidenceCollector
{
    /**
     * @return list<EvidenceCandidate>
     */
    public function collect(PumpEvent $event, Token $token, EvidenceWindow $window): array;

    /** Short stable name for logging / failure counters. */
    public function name(): string;

    /** Does this collector make external HTTP requests? */
    public function isExternal(): bool;
}
