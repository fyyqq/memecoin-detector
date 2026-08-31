<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One normalized article row from the GDELT 2.1 DOC API (`mode=ArtList`).
 * Raw JSON is never persisted — only these fields survive.
 */
final readonly class GdeltArticle
{
    public function __construct(
        public string $url,
        public string $title,
        public ?CarbonImmutable $seenAt,
        public string $domain,
        public ?string $language,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';
        $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';

        if ($url === '' || $title === '') {
            return null;
        }

        $seenAt = null;
        if (is_string($row['seendate'] ?? null) && $row['seendate'] !== '') {
            try {
                $parsed = CarbonImmutable::createFromFormat('Ymd\THis\Z', $row['seendate'], 'UTC');
                $seenAt = $parsed instanceof CarbonImmutable ? $parsed : null;
            } catch (Throwable) {
                $seenAt = null;
            }
        }

        $domain = is_string($row['domain'] ?? null) ? mb_strtolower(trim($row['domain'])) : '';

        return new self(
            url: $url,
            title: $title,
            seenAt: $seenAt,
            domain: $domain,
            language: is_string($row['language'] ?? null) && $row['language'] !== '' ? $row['language'] : null,
        );
    }
}
