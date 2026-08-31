<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;

/**
 * Thrown by {@see PumpExplanationValidator} when the model's structured output
 * is malformed, uses a value outside the allowed sets, cites an evidence id that
 * was never supplied, leaves a factual claim uncited, or uses causal language.
 *
 * Malformed model output is REJECTED — the explanation is recorded as `failed`,
 * never persisted as if it were valid.
 */
class InvalidExplanationException extends RuntimeException {}
