<?php

declare(strict_types=1);

namespace App\Services\Risk;

/**
 * Normalized view of GeckoTerminal `/networks/{net}/tokens/{addr}/info` — the
 * SECONDARY risk source (holder buckets, Solana mint/freeze authority, honeypot
 * flag, gt_score).
 *
 * All accessors return null when absent → the caller treats that as UNKNOWN.
 */
final class GeckoTerminalInfoLookup
{
    public const OUTCOME_OK = 'ok';

    public const OUTCOME_UNSUPPORTED_CHAIN = 'unsupported_chain';

    public const OUTCOME_NOT_INDEXED = 'not_indexed';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_DISABLED = 'disabled';

    /** @param array<string,mixed> $attr */
    private function __construct(
        public readonly string $outcome,
        public readonly array $attr = [],
        public readonly ?string $note = null,
    ) {}

    /** @param array<string,mixed> $attr */
    public static function ok(array $attr): self
    {
        return new self(self::OUTCOME_OK, $attr);
    }

    public static function unsupportedChain(string $chainId): self
    {
        return new self(self::OUTCOME_UNSUPPORTED_CHAIN, note: "chain '{$chainId}' not mapped to GeckoTerminal");
    }

    public static function notIndexed(): self
    {
        return new self(self::OUTCOME_NOT_INDEXED, note: 'token not indexed by GeckoTerminal');
    }

    public static function error(string $note): self
    {
        return new self(self::OUTCOME_ERROR, note: mb_substr($note, 0, 300));
    }

    public static function disabled(): self
    {
        return new self(self::OUTCOME_DISABLED, note: 'GeckoTerminal /info disabled');
    }

    public function hasData(): bool
    {
        return $this->outcome === self::OUTCOME_OK && $this->attr !== [];
    }

    public function holderCount(): ?int
    {
        $v = data_get($this->attr, 'holders.count');

        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    /** Top-10 holder concentration as a fraction (0..1). */
    public function top10Fraction(): ?float
    {
        $v = data_get($this->attr, 'holders.distribution_percentage.top_10');

        return is_numeric($v) ? ((float) $v) / 100.0 : null;
    }

    /** true = mint authority live, false = renounced, null = unknown. */
    public function mintAuthorityActive(): ?bool
    {
        return $this->authorityActive('mint_authority');
    }

    public function freezeAuthorityActive(): ?bool
    {
        return $this->authorityActive('freeze_authority');
    }

    private function authorityActive(string $key): ?bool
    {
        if (! array_key_exists($key, $this->attr)) {
            return null;
        }
        $v = $this->attr[$key];
        if ($v === null) {
            return false;
        }
        if (is_string($v)) {
            return trim($v) !== '';
        }

        return null;
    }

    /** GeckoTerminal's own honeypot flag — often null (UNKNOWN). */
    public function isHoneypot(): ?bool
    {
        $v = $this->attr['is_honeypot'] ?? null;

        return is_bool($v) ? $v : null;
    }

    public function gtScore(): ?float
    {
        $v = $this->attr['gt_score'] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }
}
