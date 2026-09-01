<?php

declare(strict_types=1);

namespace App\Services\Trending;

use App\Services\DexScreener\DexScreenerClient;
use Carbon\CarbonImmutable;

/**
 * Builds the trending candidate universe from the DOCUMENTED DexScreener APIs
 * only: `GET /metas/trending/v1` -> `GET /metas/meta/v1/{slug}`.
 *
 * The undocumented `io.dexscreener.com` WebSocket (`trendingScoreH6/H24`, binary
 * frames, Cloudflare-bot-walled) is NOT used — see
 * docs/trending-discovery-reconnaissance.md.
 *
 * Output: one {@see TrendingCandidate} per (chain, token), deduplicated to the
 * highest-liquidity pair, carrying 6h + 24h market data + the meta provenance.
 */
class TrendingMetaCollector
{
    public function __construct(private readonly DexScreenerClient $client) {}

    /**
     * @return array{candidates:list<TrendingCandidate>,diagnostics:array<string,mixed>}
     */
    public function collect(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $maxMetas = max(0, (int) config('trending.collect.max_metas', 18));

        $diagnostics = [
            'meta_count' => 0,
            'slugs_used' => [],
            'pairs_seen' => 0,
            'ad_or_malformed_skipped' => 0,
            'unique_tokens' => 0,
        ];

        // 1. /metas/trending/v1 — the trending narratives, in DexScreener's order.
        $selected = [];
        foreach ($this->client->trendingMetas() as $meta) {
            if (count($selected) >= $maxMetas) {
                break;
            }
            $slug = is_array($meta) && is_string($meta['slug'] ?? null) ? trim($meta['slug']) : '';
            if ($slug === '') {
                continue;
            }
            $selected[$slug] = is_string($meta['name'] ?? null) && $meta['name'] !== '' ? trim($meta['name']) : $slug;
        }

        $diagnostics['meta_count'] = count($selected);
        $diagnostics['slugs_used'] = array_keys($selected);

        // 2. /metas/meta/v1/{slug} — the member pairs, full market objects.
        /** @var array<string,array<string,mixed>> $byToken key => raw accumulator */
        $byToken = [];

        foreach ($selected as $slug => $listName) {
            $detail = $this->client->metaBySlug($slug);
            $metaName = is_string($detail['name'] ?? null) && $detail['name'] !== '' ? $detail['name'] : $listName;
            $pairs = is_array($detail['pairs'] ?? null) ? $detail['pairs'] : [];

            foreach ($pairs as $pair) {
                if (! is_array($pair)) {
                    continue;
                }
                $diagnostics['pairs_seen']++;

                $base = is_array($pair['baseToken'] ?? null) ? $pair['baseToken'] : [];
                $chainId = is_string($pair['chainId'] ?? null) ? mb_strtolower(trim($pair['chainId'])) : null;
                $addr = is_string($base['address'] ?? null) ? trim($base['address']) : null;
                $pairAddress = is_string($pair['pairAddress'] ?? null) ? trim($pair['pairAddress']) : null;

                // The documented /metas/meta response never carries the paid
                // narrative-bar ad, but reject anything that is not a real
                // member pair so paid placement can never leak in.
                if ($chainId === null || $chainId === '' || $addr === null || $addr === '' || $pairAddress === null || $pairAddress === '') {
                    $diagnostics['ad_or_malformed_skipped']++;

                    continue;
                }

                $key = $chainId.':'.mb_strtolower($addr);
                $liquidity = $this->floatOrNull(($pair['liquidity']['usd'] ?? null));

                $existing = $byToken[$key] ?? null;
                $existingLiq = $existing !== null ? ($this->floatOrNull($existing['pair']['liquidity']['usd'] ?? null) ?? -1.0) : -1.0;

                if ($existing === null || ($liquidity ?? -1.0) > $existingLiq) {
                    $byToken[$key] = [
                        'chain_id' => $chainId,
                        'token_address' => $addr,
                        'pair' => $pair,
                        'meta_slug' => $slug,
                        'meta_name' => $metaName,
                        'meta_slugs' => $existing['meta_slugs'] ?? [],
                    ];
                }

                $byToken[$key]['meta_slugs'][] = $slug;
            }
        }

        $diagnostics['unique_tokens'] = count($byToken);

        $candidates = [];
        foreach ($byToken as $row) {
            $candidates[] = $this->toCandidate($row, $now);
        }

        return ['candidates' => $candidates, 'diagnostics' => $diagnostics];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function toCandidate(array $row, CarbonImmutable $now): TrendingCandidate
    {
        /** @var array<string,mixed> $pair */
        $pair = $row['pair'];
        $base = is_array($pair['baseToken'] ?? null) ? $pair['baseToken'] : [];
        $volume = is_array($pair['volume'] ?? null) ? $pair['volume'] : [];
        $priceChange = is_array($pair['priceChange'] ?? null) ? $pair['priceChange'] : [];
        $txns = is_array($pair['txns'] ?? null) ? $pair['txns'] : [];

        $createdMs = $this->intOrNull($pair['pairCreatedAt'] ?? null);

        return new TrendingCandidate(
            chainId: (string) $row['chain_id'],
            tokenAddress: (string) $row['token_address'],
            pairAddress: $this->stringOrNull($pair['pairAddress'] ?? null),
            dexId: $this->stringOrNull($pair['dexId'] ?? null),
            symbol: $this->stringOrNull($base['symbol'] ?? null),
            name: $this->stringOrNull($base['name'] ?? null),
            marketCap: $this->floatOrNull($pair['marketCap'] ?? null),
            liquidityUsd: $this->floatOrNull($pair['liquidity']['usd'] ?? null),
            pairCreatedAt: $createdMs !== null && $createdMs > 0 ? CarbonImmutable::createFromTimestampMs($createdMs) : null,
            volume6h: $this->floatOrNull($volume['h6'] ?? null),
            volume24h: $this->floatOrNull($volume['h24'] ?? null),
            priceChange6h: $this->floatOrNull($priceChange['h6'] ?? null),
            priceChange24h: $this->floatOrNull($priceChange['h24'] ?? null),
            txns6h: $this->txnTotal($txns['h6'] ?? null),
            txns24h: $this->txnTotal($txns['h24'] ?? null),
            trendingMetaSlug: (string) $row['meta_slug'],
            trendingMetaName: (string) $row['meta_name'],
            metaSlugs: array_values(array_unique(array_map('strval', $row['meta_slugs']))),
            capturedAt: $now,
        );
    }

    private function txnTotal(mixed $bucket): ?int
    {
        if (! is_array($bucket)) {
            return null;
        }

        $buys = $this->intOrNull($bucket['buys'] ?? null);
        $sells = $this->intOrNull($bucket['sells'] ?? null);

        if ($buys === null && $sells === null) {
            return null;
        }

        return (int) ($buys ?? 0) + (int) ($sells ?? 0);
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
