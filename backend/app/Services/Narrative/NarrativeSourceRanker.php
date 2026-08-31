<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Models\TokenNarrativeSource;

/**
 * De-duplicates + ranks + caps the candidate sources for one section before
 * they are persisted and fed to the model.
 *
 * Quality tiers (HIGH > MEDIUM > LOW):
 *
 *   HIGH   — official project source; well-established reference site
 *            (Know Your Meme, Wikipedia); reputable news outlet; our own
 *            internal MARKET facts (reliable AS FACTS — timing, not causation).
 *   MEDIUM — established crypto publication; credible community source;
 *            documented secondary reporting.
 *   LOW    — anonymous post, repost, low-quality blog, unsourced social claim.
 *
 * Sort is (tier, relevance_score, published-date-present, id) so a single strong
 * primary source always outranks a pile of low-quality reposts, and the cap can
 * never silently drop every HIGH/MEDIUM source in favour of LOW ones.
 */
class NarrativeSourceRanker
{
    private const TIER_HIGH = 2;

    private const TIER_MEDIUM = 1;

    private const TIER_LOW = 0;

    /**
     * @param  list<NarrativeSourceCandidate>  $candidates
     * @return list<NarrativeSourceCandidate> ranked, de-duplicated, capped
     */
    public function rank(array $candidates, int $cap): array
    {
        $byHash = [];
        foreach ($candidates as $candidate) {
            $hash = $candidate->dedupeHash();
            // On a collision keep the higher-tier / higher-relevance one.
            if (! isset($byHash[$hash]) || $this->betterOf($candidate, $byHash[$hash])) {
                $byHash[$hash] = $candidate;
            }
        }

        $unique = array_values($byHash);

        usort($unique, function (NarrativeSourceCandidate $a, NarrativeSourceCandidate $b): int {
            return [
                $this->tier($b),
                $b->relevanceScore,
                $b->publishedAt !== null ? 1 : 0,
                mb_strtolower($a->sourceName),
            ] <=> [
                $this->tier($a),
                $a->relevanceScore,
                $a->publishedAt !== null ? 1 : 0,
                mb_strtolower($b->sourceName),
            ];
        });

        return array_slice($unique, 0, max(1, $cap));
    }

    public function tierLabel(NarrativeSourceCandidate|TokenNarrativeSource $source): string
    {
        $tier = $source instanceof NarrativeSourceCandidate
            ? $this->tier($source)
            : $this->tierFor($source->source_type, (string) $source->source_url, $source->confidence);

        return match ($tier) {
            self::TIER_HIGH => 'high',
            self::TIER_MEDIUM => 'medium',
            default => 'low',
        };
    }

    private function betterOf(NarrativeSourceCandidate $a, NarrativeSourceCandidate $b): bool
    {
        return [$this->tier($a), $a->relevanceScore] > [$this->tier($b), $b->relevanceScore];
    }

    private function tier(NarrativeSourceCandidate $candidate): int
    {
        return $this->tierFor($candidate->sourceType, $candidate->sourceUrl ?? '', $candidate->confidence);
    }

    private function tierFor(string $sourceType, string $url, string $confidence): int
    {
        $domain = $this->domain($url);
        $trusted = $this->inList($domain, (array) config('narrative.trusted_domains', []));
        $reference = $this->inList($domain, (array) config('narrative.reference_domains', []));

        return match (true) {
            $sourceType === TokenNarrativeSource::TYPE_OFFICIAL => self::TIER_HIGH,
            $sourceType === TokenNarrativeSource::TYPE_MARKET => self::TIER_HIGH,
            $sourceType === TokenNarrativeSource::TYPE_REFERENCE && ($reference || $confidence === 'high') => self::TIER_HIGH,
            $sourceType === TokenNarrativeSource::TYPE_REFERENCE => self::TIER_MEDIUM,
            $sourceType === TokenNarrativeSource::TYPE_NEWS && $trusted => self::TIER_HIGH,
            $sourceType === TokenNarrativeSource::TYPE_NEWS => self::TIER_MEDIUM,
            $sourceType === TokenNarrativeSource::TYPE_SOCIAL && $confidence === 'high' => self::TIER_MEDIUM,
            $sourceType === TokenNarrativeSource::TYPE_SOCIAL => self::TIER_LOW,
            $sourceType === TokenNarrativeSource::TYPE_COMMUNITY && $confidence === 'high' => self::TIER_MEDIUM,
            default => self::TIER_LOW,
        };
    }

    private function domain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? mb_strtolower((string) preg_replace('/^www\./', '', $host)) : '';
    }

    /**
     * @param  list<string>  $list
     */
    private function inList(string $domain, array $list): bool
    {
        if ($domain === '') {
            return false;
        }
        foreach ($list as $entry) {
            $entry = mb_strtolower(trim((string) $entry));
            if ($entry !== '' && ($domain === $entry || str_ends_with($domain, '.'.$entry))) {
                return true;
            }
        }

        return false;
    }
}
