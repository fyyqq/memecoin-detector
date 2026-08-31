<?php

declare(strict_types=1);

namespace App\Services\Narrative;

use App\Models\Token;
use Carbon\CarbonImmutable;

/**
 * Everything a {@see NarrativeResearchProvider} needs to research ONE token
 * without ticker-collision mistakes.
 *
 * Identity is always chain + address; a bare symbol is never enough. Providers
 * build queries from the token NAME (+ chain / "crypto" qualifiers) and verify a
 * source actually refers to this token before keeping it.
 */
final readonly class NarrativeResearchContext
{
    /**
     * @param  self::SECTION_*  $section
     */
    public function __construct(
        public Token $token,
        public string $name,
        public string $symbol,
        public string $chainId,
        public string $tokenAddress,
        public ?string $websiteUrl,
        public ?string $websiteDomain,
        public ?string $twitterUrl,
        public ?string $telegramUrl,
        public ?CarbonImmutable $earliestPairCreatedAt,
        public ?CarbonImmutable $firstObservedAt,
        public CarbonImmutable $now,
        public string $section,
    ) {}

    public const SECTION_ORIGIN = 'origin';

    public const SECTION_POPULARITY = 'popularity';

    public static function for(Token $token, string $section, CarbonImmutable $now): self
    {
        $website = self::cleanUrl($token->website_url);

        return new self(
            token: $token,
            name: trim((string) $token->name),
            symbol: trim((string) $token->symbol),
            chainId: (string) $token->chain_id,
            tokenAddress: (string) $token->token_address,
            websiteUrl: $website,
            websiteDomain: $website !== null ? self::domain($website) : null,
            twitterUrl: self::cleanUrl($token->twitter_url),
            telegramUrl: self::cleanUrl($token->telegram_url),
            earliestPairCreatedAt: $token->earliest_pair_created_at,
            firstObservedAt: $token->first_observed_at,
            now: $now,
            section: $section,
        );
    }

    /** A usable name that is specific enough to search on (not a generic word). */
    public function hasResolvableIdentity(): bool
    {
        return mb_strlen($this->name) >= 3 && ! $this->isGenericName();
    }

    public function isGenericName(): bool
    {
        static $generic = [
            'meme', 'memecoin', 'coin', 'token', 'crypto', 'cryptocurrency', 'the',
            'moon', 'pump', 'dump', 'money', 'cash', 'usd', 'baby', 'mini', 'inu',
            'ai', 'gm', 'wagmi', 'hodl', 'test', 'safe',
        ];

        return in_array(mb_strtolower(trim($this->name)), $generic, true);
    }

    private static function cleanUrl(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || ! preg_match('#^https?://#i', $value)) {
            return null;
        }

        return mb_substr($value, 0, 1024);
    }

    private static function domain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_strtolower(preg_replace('/^www\./', '', $host)) : null;
    }
}
