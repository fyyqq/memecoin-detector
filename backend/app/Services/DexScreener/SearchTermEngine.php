<?php

declare(strict_types=1);

namespace App\Services\DexScreener;

/**
 * Builds the per-run `/latest/dex/search` term list.
 *
 * Deterministic and reproducible (no rotation / randomness yet). Priority:
 *
 *   1. core meme terms        (config `dexscreener.search_terms`)
 *   2. trending meta slugs     (from `/metas/trending/v1`)
 *   3. trending meta names     (from `/metas/trending/v1`)
 *   4. ecosystem terms         (config `dexscreener.search.ecosystem_terms`) —
 *                              supplementary signals only, NOT chain filters
 *
 * Terms are lowercased, trimmed, de-duplicated across categories, and the list
 * is truncated to `dexscreener.search.term_budget` (default 25).
 */
final class SearchTermEngine
{
    public function __construct(private readonly DexScreenerClient $client) {}

    /**
     * @return array{
     *     terms: list<string>,
     *     categories: array{core:int,meta_slug:int,meta_name:int,ecosystem:int},
     *     budget: int,
     *     meta_terms_considered: int
     * }
     */
    public function build(): array
    {
        $budget = max(0, (int) config('dexscreener.search.term_budget', 25));
        $metaCap = max(0, (int) config('dexscreener.trending_meta_terms', 0));

        $core = $this->normalize((array) config('dexscreener.search_terms', []));
        $ecosystem = $this->normalize((array) config('dexscreener.search.ecosystem_terms', []));
        [$metaSlugs, $metaNames] = $this->trendingMetaTerms($metaCap);

        /** @var array<string,string> $picked term => category */
        $picked = [];
        $categories = ['core' => 0, 'meta_slug' => 0, 'meta_name' => 0, 'ecosystem' => 0];

        $groups = [
            'core' => $core,
            'meta_slug' => $metaSlugs,
            'meta_name' => $metaNames,
            'ecosystem' => $ecosystem,
        ];

        foreach ($groups as $category => $terms) {
            foreach ($terms as $term) {
                if (isset($picked[$term])) {
                    continue; // de-duplicate across categories
                }
                if (count($picked) >= $budget) {
                    break 2; // budget reached
                }
                $picked[$term] = $category;
                $categories[$category]++;
            }
        }

        return [
            'terms' => array_keys($picked),
            'categories' => $categories,
            'budget' => $budget,
            'meta_terms_considered' => count($metaSlugs) + count($metaNames),
        ];
    }

    /**
     * @return array{0:list<string>,1:list<string>} [slugs, names], each ≤ $cap
     */
    private function trendingMetaTerms(int $cap): array
    {
        if ($cap <= 0) {
            return [[], []];
        }

        $slugs = [];
        $names = [];

        foreach ($this->client->trendingMetas() as $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $slug = is_string($meta['slug'] ?? null) ? trim($meta['slug']) : '';
            $name = is_string($meta['name'] ?? null) ? trim($meta['name']) : '';

            if ($slug !== '') {
                $slugs[] = $slug;
            }
            if ($name !== '') {
                $names[] = $name;
            }

            if (count($slugs) >= $cap && count($names) >= $cap) {
                break;
            }
        }

        return [
            array_slice($this->normalize($slugs), 0, $cap),
            array_slice($this->normalize($names), 0, $cap),
        ];
    }

    /**
     * Lowercase, trim, drop empties, de-duplicate — order preserved.
     *
     * @param  array<mixed>  $terms
     * @return list<string>
     */
    private function normalize(array $terms): array
    {
        /** @var array<string,true> $out */
        $out = [];

        foreach ($terms as $term) {
            if (! is_string($term)) {
                continue;
            }
            $term = mb_strtolower(trim($term));
            if ($term === '' || isset($out[$term])) {
                continue;
            }
            $out[$term] = true;
        }

        return array_keys($out);
    }
}
