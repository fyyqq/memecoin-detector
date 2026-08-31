<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\PumpExplanation;

/**
 * Never trust arbitrary model JSON. This validator rejects any structured
 * explanation that:
 *
 *  - is malformed / missing required keys,
 *  - uses a `primary_catalyst`, secondary-signal `type` or `confidence` value
 *    outside the allowed sets,
 *  - cites an evidence id that was not supplied to the model,
 *  - makes a factual claim (in `evidence` / `secondary_signals`) with no
 *    evidence id, or a non-UNKNOWN catalyst with no cited evidence at all,
 *  - contains causal language ("caused/triggered/led to the pump").
 *
 * A rejection => the explanation is recorded `failed`, never persisted as valid.
 */
class PumpExplanationValidator
{
    private const MAX_SUMMARY = 1500;

    private const MAX_STATEMENT = 600;

    /** Phrases that assert causation. Checked in the summary and every statement. */
    private const CAUSAL_PATTERNS = [
        '/\bcaus(e|ed|es|ing)\b/i',
        '/\btrigger(ed|s|ing)?\s+(the|this|a)?\s*(pump|move|rally|spike|surge)/i',
        '/\b(led to|leads to|resulted in|responsible for)\b/i',
        '/\bpumped\s+because\b/i',
        '/\bwas the (reason|cause)\b/i',
        '/\bdue to\s+.*\b(pump|rally|surge)/i',
    ];

    /**
     * @param  list<int>  $suppliedEvidenceIds
     *
     * @throws InvalidExplanationException
     */
    public function validate(array $output, array $suppliedEvidenceIds): ValidatedExplanation
    {
        $allowedIds = array_values(array_unique(array_map('intval', $suppliedEvidenceIds)));

        $summary = $this->string($output, 'summary', self::MAX_SUMMARY);
        $primaryCatalyst = $this->enum($output, 'primary_catalyst', PumpExplanation::CATALYSTS);
        $confidence = $this->enum($output, 'confidence', [
            PumpExplanation::CONFIDENCE_HIGH,
            PumpExplanation::CONFIDENCE_MEDIUM,
            PumpExplanation::CONFIDENCE_LOW,
        ]);

        $secondary = $this->secondarySignals($output['secondary_signals'] ?? [], $allowedIds);
        $evidence = $this->evidenceClaims($output['evidence'] ?? [], $allowedIds);
        $caveats = $this->stringList($output['caveats'] ?? [], 'caveats');
        $unknowns = $this->stringList($output['unknowns'] ?? [], 'unknowns');

        // Citation rule: a non-UNKNOWN catalyst must rest on at least one cited
        // evidence record.
        if ($primaryCatalyst !== 'UNKNOWN' && $evidence === []) {
            throw new InvalidExplanationException(
                "primary_catalyst '{$primaryCatalyst}' has no cited evidence; every non-UNKNOWN catalyst must cite evidence",
            );
        }

        // UNKNOWN must state at least one unknown.
        if ($primaryCatalyst === 'UNKNOWN' && $unknowns === []) {
            throw new InvalidExplanationException('primary_catalyst UNKNOWN must list at least one entry in `unknowns`');
        }

        // Causal-language guard over the summary and every statement.
        foreach (array_merge(
            [$summary],
            array_column($evidence, 'statement'),
            array_column($secondary, 'statement'),
        ) as $text) {
            $this->rejectCausalLanguage($text);
        }

        $cited = array_column($evidence, 'evidence_id');
        foreach ($secondary as $signal) {
            $cited = array_merge($cited, $signal['evidence_ids']);
        }
        $cited = array_values(array_unique($cited));
        sort($cited);

        return new ValidatedExplanation(
            summary: $summary,
            primaryCatalyst: $primaryCatalyst,
            secondarySignals: $secondary,
            evidence: $evidence,
            confidence: $confidence,
            caveats: $caveats,
            unknowns: $unknowns,
            citedEvidenceIds: $cited,
        );
    }

    /**
     * @param  array<string,mixed>  $output
     */
    private function string(array $output, string $key, int $max): string
    {
        $value = $output[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidExplanationException("`{$key}` is required and must be a non-empty string");
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidExplanationException("`{$key}` exceeds {$max} characters");
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $output
     * @param  list<string>  $allowed
     */
    private function enum(array $output, string $key, array $allowed): string
    {
        $value = $output[$key] ?? null;
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidExplanationException("`{$key}` must be one of: ".implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * @param  list<int>  $allowedIds
     * @return list<array{type:string,statement:string,evidence_ids:list<int>}>
     */
    private function secondarySignals(mixed $raw, array $allowedIds): array
    {
        if (! is_array($raw)) {
            throw new InvalidExplanationException('`secondary_signals` must be an array');
        }

        $out = [];
        foreach ($raw as $i => $signal) {
            if (! is_array($signal)) {
                throw new InvalidExplanationException("`secondary_signals[{$i}]` must be an object");
            }
            $type = $signal['type'] ?? null;
            if (! is_string($type) || ! in_array($type, PumpExplanation::CATALYSTS, true)) {
                throw new InvalidExplanationException("`secondary_signals[{$i}].type` must be one of the catalyst categories");
            }
            $statement = $signal['statement'] ?? null;
            if (! is_string($statement) || trim($statement) === '' || mb_strlen(trim($statement)) > self::MAX_STATEMENT) {
                throw new InvalidExplanationException("`secondary_signals[{$i}].statement` must be a non-empty string");
            }
            $ids = $this->evidenceIdList($signal['evidence_ids'] ?? null, $allowedIds, "secondary_signals[{$i}].evidence_ids");
            if ($ids === []) {
                throw new InvalidExplanationException("`secondary_signals[{$i}]` is a factual claim and must cite at least one evidence id");
            }

            $out[] = ['type' => $type, 'statement' => trim($statement), 'evidence_ids' => $ids];
        }

        return $out;
    }

    /**
     * @param  list<int>  $allowedIds
     * @return list<array{evidence_id:int,statement:string}>
     */
    private function evidenceClaims(mixed $raw, array $allowedIds): array
    {
        if (! is_array($raw)) {
            throw new InvalidExplanationException('`evidence` must be an array');
        }

        $out = [];
        foreach ($raw as $i => $claim) {
            if (! is_array($claim)) {
                throw new InvalidExplanationException("`evidence[{$i}]` must be an object");
            }
            $id = $claim['evidence_id'] ?? null;
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new InvalidExplanationException("`evidence[{$i}].evidence_id` must be an integer");
            }
            $id = (int) $id;
            if (! in_array($id, $allowedIds, true)) {
                throw new InvalidExplanationException("`evidence[{$i}].evidence_id` ({$id}) was not supplied to the model");
            }
            $statement = $claim['statement'] ?? null;
            if (! is_string($statement) || trim($statement) === '' || mb_strlen(trim($statement)) > self::MAX_STATEMENT) {
                throw new InvalidExplanationException("`evidence[{$i}].statement` must be a non-empty string");
            }

            $out[] = ['evidence_id' => $id, 'statement' => trim($statement)];
        }

        return $out;
    }

    /**
     * @param  list<int>  $allowedIds
     * @return list<int>
     */
    private function evidenceIdList(mixed $raw, array $allowedIds, string $path): array
    {
        if (! is_array($raw)) {
            throw new InvalidExplanationException("`{$path}` must be an array of evidence ids");
        }

        $ids = [];
        foreach ($raw as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new InvalidExplanationException("`{$path}` must contain only integers");
            }
            $id = (int) $id;
            if (! in_array($id, $allowedIds, true)) {
                throw new InvalidExplanationException("`{$path}` cites evidence id {$id} which was not supplied to the model");
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw, string $key): array
    {
        if (! is_array($raw)) {
            throw new InvalidExplanationException("`{$key}` must be an array of strings");
        }

        $out = [];
        foreach ($raw as $i => $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidExplanationException("`{$key}[{$i}]` must be a non-empty string");
            }
            if (mb_strlen(trim($item)) > self::MAX_STATEMENT) {
                throw new InvalidExplanationException("`{$key}[{$i}]` exceeds ".self::MAX_STATEMENT.' characters');
            }
            $out[] = trim($item);
        }

        return $out;
    }

    private function rejectCausalLanguage(string $text): void
    {
        foreach (self::CAUSAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                throw new InvalidExplanationException(
                    'causal language is not allowed ("'.mb_substr($text, 0, 120).'") — evidence shows correlation, not causation',
                );
            }
        }
    }
}
