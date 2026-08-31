<?php

declare(strict_types=1);

namespace App\DTOs\DexScreener;

/**
 * The small slice of token metadata the DexScreener pair `info` object exposes.
 * Persisted onto `tokens` so the evidence engine can read it without re-calling
 * DexScreener. DexScreener does NOT expose a token description.
 */
final readonly class TokenLinks
{
    public function __construct(
        public ?string $website = null,
        public ?string $twitter = null,
        public ?string $telegram = null,
        public ?string $image = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->website === null
            && $this->twitter === null
            && $this->telegram === null
            && $this->image === null;
    }
}
