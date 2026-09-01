<?php

declare(strict_types=1);

namespace App\Services\Ranking;

/**
 * One research source behind a historical monthly-champion candidate.
 *
 * We store the source NAME, URL, a short CLAIM, and the publication date where
 * available — never a scraped page body.
 */
final readonly class MonthlyResearchSource
{
    public function __construct(
        public string $name,
        public ?string $url,
        public string $claim,
        public ?string $publishedAt,
        /** primary_market_data | historical_provider | archived_dexscreener | reputable_reporting | secondary | low_quality */
        public string $credibility = 'secondary',
    ) {}

    /**
     * @param  array<string,mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            name: mb_substr(trim((string) ($row['name'] ?? 'unknown')), 0, 120) ?: 'unknown',
            url: isset($row['url']) && is_string($row['url']) && $row['url'] !== '' ? mb_substr($row['url'], 0, 500) : null,
            claim: mb_substr(trim((string) ($row['claim'] ?? '')), 0, 300),
            publishedAt: isset($row['published_at']) && is_string($row['published_at']) && $row['published_at'] !== ''
                ? mb_substr($row['published_at'], 0, 32)
                : null,
            credibility: in_array($row['credibility'] ?? null, self::CREDIBILITY, true)
                ? (string) $row['credibility']
                : 'secondary',
        );
    }

    /** Highest → lowest. */
    public const CREDIBILITY = [
        'primary_market_data',
        'historical_provider',
        'archived_dexscreener',
        'reputable_reporting',
        'secondary',
        'low_quality',
    ];

    public function credibilityRank(): int
    {
        $i = array_search($this->credibility, self::CREDIBILITY, true);

        return $i === false ? count(self::CREDIBILITY) : $i;
    }

    /** primary market data / historical provider / archived DexScreener / reputable reporting. */
    public function isStrong(): bool
    {
        return $this->credibilityRank() <= 3;
    }

    /** primary market data / historical provider / archived DexScreener — the top tier. */
    public function isPrimary(): bool
    {
        return $this->credibilityRank() <= 2;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'claim' => $this->claim,
            'published_at' => $this->publishedAt,
            'credibility' => $this->credibility,
        ];
    }
}
