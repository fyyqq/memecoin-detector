<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * A model explanation that has passed {@see PumpExplanationValidator}: every
 * value is within the allowed sets, every factual claim cites a real supplied
 * evidence id, and no causal language slipped through.
 *
 * `toArray()` is exactly what gets stored in `pump_explanations.explanation_json`.
 */
final readonly class ValidatedExplanation
{
    /**
     * @param  list<array{type:string,statement:string,evidence_ids:list<int>}>  $secondarySignals
     * @param  list<array{evidence_id:int,statement:string}>  $evidence
     * @param  list<string>  $caveats
     * @param  list<string>  $unknowns
     * @param  list<int>  $citedEvidenceIds
     */
    public function __construct(
        public string $summary,
        public string $primaryCatalyst,
        public array $secondarySignals,
        public array $evidence,
        public string $confidence,
        public array $caveats,
        public array $unknowns,
        public array $citedEvidenceIds,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'primary_catalyst' => $this->primaryCatalyst,
            'secondary_signals' => $this->secondarySignals,
            'evidence' => $this->evidence,
            'confidence' => $this->confidence,
            'caveats' => $this->caveats,
            'unknowns' => $this->unknowns,
        ];
    }
}
