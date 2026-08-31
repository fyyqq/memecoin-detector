<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;

/**
 * Thrown by a {@see PumpExplanationProvider} when the AI vendor call fails —
 * transport error, non-success status, missing/blocked API key, or a response
 * with no usable structured output. The service records the event's explanation
 * as `failed` with this message and moves on. It NEVER fabricates a fallback.
 */
class PumpExplanationProviderException extends RuntimeException {}
