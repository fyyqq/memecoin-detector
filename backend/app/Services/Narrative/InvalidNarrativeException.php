<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use RuntimeException;

/**
 * The model returned structured output that violates the grounding rules
 * (missing keys, value outside an allowed set, cited a source id that was not
 * supplied, an uncited factual claim, a fabricated creator-intent claim, or
 * causal language). The affected section is recorded `failed`, never persisted
 * as valid.
 */
class InvalidNarrativeException extends RuntimeException {}
