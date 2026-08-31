<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use RuntimeException;

/** A narrative AI provider failed. The message is concise and non-sensitive. */
class NarrativeExplanationProviderException extends RuntimeException {}
