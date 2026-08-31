<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Models\TokenNarrativeReport;

/**
 * Never trust arbitrary model JSON. Validates the `origin` and `popularity`
 * sections INDEPENDENTLY (so one can be `completed` while the other `failed`).
 *
 * A section is rejected when it:
 *  - is malformed / missing required keys,
 *  - uses `origin_type` / timeline `type` / `confidence` outside the allowed set,
 *  - cites a `source_ids` value that was not supplied to the model,
 *  - makes a factual claim (`supporting_facts` / `timeline`) with no source id,
 *  - asserts creator intent ("the creator wanted…", "was designed to…") — origin,
 *  - uses causal language ("caused / triggered / led to / popular because") —
 *    popularity.
 *
 * The popularity timeline is SORTED chronologically (null dates last) rather
 * than rejected, so ordering is always deterministic.
 */
class NarrativeExplanationValidator
{
    private const MAX_SUMMARY = 2000;

    private const MAX_STATEMENT = 700;

    /** Unsupported-creator-intent phrases — rejected in the origin section. */
    private const INTENT_PATTERNS = [
        '/\bthe creator(s)?\s+(wanted|intended|aimed|set out|designed)\b/i',
        '/\bwas (created|designed|built|launched)\s+(to|in order to|so that|for the purpose of)\b/i',
        '/\bthe (team|developer|founder)(s)?\s+(wanted|intended|planned)\b/i',
        '/\bwith the (goal|intention|aim) of\b/i',
    ];

    /** Causal phrases — rejected in the popularity section. */
    private const CAUSAL_PATTERNS = [
        '/\bcaus(e|ed|es|ing)\b/i',
        '/\btrigger(ed|s|ing)?\s+(the|this|a)?\s*(pump|rally|surge|spike|move|interest|attention)/i',
        '/\b(led to|leads to|resulted in|responsible for)\b/i',
        '/\bpopular\s+because\b/i',
        '/\bbecame popular\s+(due to|because of)\b/i',
        '/\bwas the (reason|cause)\b/i',
    ];

    /**
     * @param  list<int>  $allowedIds
     * @return array<string,mixed> the cleaned origin structure
     *
     * @throws InvalidNarrativeException
     */
    public function validateOrigin(mixed $raw, array $allowedIds): array
    {
        if (! is_array($raw)) {
            throw new InvalidNarrativeException('`origin` must be an object');
        }

        $headline = $this->string($raw, 'headline', 300);
        $summary = $this->string($raw, 'summary', self::MAX_SUMMARY);
        $originType = $this->enum($raw, 'origin_type', TokenNarrativeReport::ORIGIN_TYPES);
        $confidence = $this->enum($raw, 'confidence', TokenNarrativeReport::CONFIDENCE);

        $facts = $this->factList($raw['supporting_facts'] ?? [], $allowedIds, 'supporting_facts');
        $caveats = $this->stringList($raw['caveats'] ?? [], 'caveats');
        $unknowns = $this->stringList($raw['unknowns'] ?? [], 'unknowns');

        // A non-UNKNOWN origin must rest on at least one cited source.
        if ($originType !== 'UNKNOWN' && $facts === []) {
            throw new InvalidNarrativeException("origin_type '{$originType}' has no cited supporting_facts");
        }
        if ($originType === 'UNKNOWN' && $unknowns === []) {
            throw new InvalidNarrativeException('origin_type UNKNOWN must list at least one entry in `unknowns`');
        }

        foreach (array_merge([$summary, $headline], array_column($facts, 'statement')) as $text) {
            $this->rejectIntent($text);
        }

        return [
            'headline' => $headline,
            'summary' => $summary,
            'origin_type' => $originType,
            'supporting_facts' => $facts,
            'confidence' => $confidence,
            'caveats' => $caveats,
            'unknowns' => $unknowns,
            'cited_source_ids' => $this->collectIds($facts),
        ];
    }

    /**
     * @param  list<int>  $allowedIds
     * @return array<string,mixed> the cleaned popularity structure (timeline sorted)
     *
     * @throws InvalidNarrativeException
     */
    public function validatePopularity(mixed $raw, array $allowedIds): array
    {
        if (! is_array($raw)) {
            throw new InvalidNarrativeException('`popularity` must be an object');
        }

        $headline = $this->string($raw, 'headline', 300);
        $summary = $this->string($raw, 'summary', self::MAX_SUMMARY);
        $confidence = $this->enum($raw, 'confidence', TokenNarrativeReport::CONFIDENCE);

        $timeline = $this->timeline($raw['timeline'] ?? [], $allowedIds);
        $dominant = $this->stringList($raw['dominant_factors'] ?? [], 'dominant_factors');
        $caveats = $this->stringList($raw['caveats'] ?? [], 'caveats');
        $unknowns = $this->stringList($raw['unknowns'] ?? [], 'unknowns');

        if ($timeline === [] && $dominant !== []) {
            throw new InvalidNarrativeException('`dominant_factors` given but the `timeline` is empty — factors must be evidenced');
        }

        foreach (array_merge(
            [$summary, $headline],
            $dominant,
            array_column($timeline, 'description'),
            array_column($timeline, 'title'),
        ) as $text) {
            $this->rejectCausal($text);
        }

        return [
            'headline' => $headline,
            'summary' => $summary,
            'timeline' => $timeline,
            'dominant_factors' => $dominant,
            'confidence' => $confidence,
            'caveats' => $caveats,
            'unknowns' => $unknowns,
            'cited_source_ids' => $this->collectIds($timeline),
        ];
    }

    // ---- primitives ---------------------------------------------------------

    /**
     * @param  array<string,mixed>  $out
     */
    private function string(array $out, string $key, int $max): string
    {
        $value = $out[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidNarrativeException("`{$key}` is required and must be a non-empty string");
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidNarrativeException("`{$key}` exceeds {$max} characters");
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>  $out
     * @param  list<string>  $allowed
     */
    private function enum(array $out, string $key, array $allowed): string
    {
        $value = $out[$key] ?? null;
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidNarrativeException("`{$key}` must be one of: ".implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * @param  list<int>  $allowedIds
     * @return list<array{statement:string,source_ids:list<int>}>
     */
    private function factList(mixed $raw, array $allowedIds, string $key): array
    {
        if (! is_array($raw)) {
            throw new InvalidNarrativeException("`{$key}` must be an array");
        }

        $out = [];
        foreach ($raw as $i => $item) {
            if (! is_array($item)) {
                throw new InvalidNarrativeException("`{$key}[{$i}]` must be an object");
            }
            $statement = $item['statement'] ?? null;
            if (! is_string($statement) || trim($statement) === '' || mb_strlen(trim($statement)) > self::MAX_STATEMENT) {
                throw new InvalidNarrativeException("`{$key}[{$i}].statement` must be a non-empty string");
            }
            $ids = $this->idList($item['source_ids'] ?? null, $allowedIds, "{$key}[{$i}].source_ids");
            if ($ids === []) {
                throw new InvalidNarrativeException("`{$key}[{$i}]` is a factual claim and must cite at least one source id");
            }
            $out[] = ['statement' => trim($statement), 'source_ids' => $ids];
        }

        return $out;
    }

    /**
     * @param  list<int>  $allowedIds
     * @return list<array{date:?string,title:string,description:string,type:string,source_ids:list<int>,confidence:string}>
     */
    private function timeline(mixed $raw, array $allowedIds): array
    {
        if (! is_array($raw)) {
            throw new InvalidNarrativeException('`timeline` must be an array');
        }

        $out = [];
        foreach ($raw as $i => $item) {
            if (! is_array($item)) {
                throw new InvalidNarrativeException("`timeline[{$i}]` must be an object");
            }

            $date = $item['date'] ?? null;
            $normalizedDate = null;
            if ($date !== null && $date !== '') {
                if (! is_string($date) || ! preg_match('/^\d{4}(-\d{2}(-\d{2})?)?/', $date)) {
                    throw new InvalidNarrativeException("`timeline[{$i}].date` must be an ISO date string or null");
                }
                $normalizedDate = mb_substr($date, 0, 10);
            }

            $title = $item['title'] ?? null;
            if (! is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > self::MAX_STATEMENT) {
                throw new InvalidNarrativeException("`timeline[{$i}].title` must be a non-empty string");
            }
            $description = $item['description'] ?? null;
            if (! is_string($description) || trim($description) === '' || mb_strlen(trim($description)) > self::MAX_STATEMENT) {
                throw new InvalidNarrativeException("`timeline[{$i}].description` must be a non-empty string");
            }
            $type = $item['type'] ?? null;
            if (! is_string($type) || ! in_array($type, TokenNarrativeReport::POPULARITY_EVENT_TYPES, true)) {
                throw new InvalidNarrativeException("`timeline[{$i}].type` must be one of the allowed event types");
            }
            $confidence = $item['confidence'] ?? 'low';
            if (! is_string($confidence) || ! in_array($confidence, TokenNarrativeReport::CONFIDENCE, true)) {
                throw new InvalidNarrativeException("`timeline[{$i}].confidence` must be high/medium/low");
            }
            $ids = $this->idList($item['source_ids'] ?? null, $allowedIds, "timeline[{$i}].source_ids");
            if ($ids === []) {
                throw new InvalidNarrativeException("`timeline[{$i}]` is a factual claim and must cite at least one source id");
            }

            $out[] = [
                'date' => $normalizedDate,
                'title' => trim($title),
                'description' => trim($description),
                'type' => $type,
                'source_ids' => $ids,
                'confidence' => $confidence,
            ];
        }

        // Deterministic chronological order — real dates ascending, nulls last.
        usort($out, static function (array $a, array $b): int {
            $da = $a['date'];
            $db = $b['date'];
            if ($da === $db) {
                return 0;
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }

            return strcmp($da, $db);
        });

        return $out;
    }

    /**
     * @param  list<int>  $allowedIds
     * @return list<int>
     */
    private function idList(mixed $raw, array $allowedIds, string $path): array
    {
        if (! is_array($raw)) {
            throw new InvalidNarrativeException("`{$path}` must be an array of source ids");
        }
        $ids = [];
        foreach ($raw as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new InvalidNarrativeException("`{$path}` must contain only integers");
            }
            $id = (int) $id;
            if (! in_array($id, $allowedIds, true)) {
                throw new InvalidNarrativeException("`{$path}` cites source id {$id} which was not supplied to the model");
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
            throw new InvalidNarrativeException("`{$key}` must be an array of strings");
        }
        $out = [];
        foreach ($raw as $i => $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidNarrativeException("`{$key}[{$i}]` must be a non-empty string");
            }
            if (mb_strlen(trim($item)) > self::MAX_STATEMENT) {
                throw new InvalidNarrativeException("`{$key}[{$i}]` exceeds ".self::MAX_STATEMENT.' characters');
            }
            $out[] = trim($item);
        }

        return $out;
    }

    /**
     * @param  list<array{source_ids:list<int>}>  $items
     * @return list<int>
     */
    private function collectIds(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids = array_merge($ids, $item['source_ids']);
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function rejectIntent(string $text): void
    {
        foreach (self::INTENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                throw new InvalidNarrativeException(
                    'unsupported creator-intent claim ("'.mb_substr($text, 0, 120).'") — origin must be evidence-backed, not inferred intent',
                );
            }
        }
    }

    private function rejectCausal(string $text): void
    {
        foreach (self::CAUSAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                throw new InvalidNarrativeException(
                    'causal language is not allowed ("'.mb_substr($text, 0, 120).'") — sources show temporal association, not causation',
                );
            }
        }
    }
}
