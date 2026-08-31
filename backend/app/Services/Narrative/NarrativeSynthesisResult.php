<?php

declare(strict_types=1);

namespace App\Services\Narrative;

/**
 * Outcome of one AI synthesis call — each section validated INDEPENDENTLY so one
 * can succeed while the other fails (partial report).
 */
final readonly class NarrativeSynthesisResult
{
    /**
     * @param  array<string,mixed>|null  $originData  validated origin structure, or null when the section failed validation
     * @param  array<string,mixed>|null  $popularityData
     */
    public function __construct(
        public string $providerName,
        public string $modelName,
        public ?array $originData,
        public ?string $originError,
        public ?array $popularityData,
        public ?string $popularityError,
    ) {}

    public function originOk(): bool
    {
        return $this->originData !== null;
    }

    public function popularityOk(): bool
    {
        return $this->popularityData !== null;
    }
}
