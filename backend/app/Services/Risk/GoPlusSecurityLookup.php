<?php

declare(strict_types=1);

namespace App\Services\Risk;

/**
 * Normalized, tri-state view of one GoPlus Security response
 * (`token_security/{evm_id}` or `solana/token_security`).
 *
 * Absence is UNKNOWN, never "no". Every accessor returns `null` when the field
 * was missing / empty (`""`) / an unrecognised shape — the caller must treat
 * that as UNKNOWN and never coerce it to a safe or unsafe default.
 */
final class GoPlusSecurityLookup
{
    public const OUTCOME_OK = 'ok';

    public const OUTCOME_UNSUPPORTED_CHAIN = 'unsupported_chain';

    public const OUTCOME_NOT_INDEXED = 'not_indexed';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_DISABLED = 'disabled';

    /**
     * @param  'evm'|'solana'|null  $chainKind
     * @param  array<string,mixed>  $raw  the provider's `result[address]` object, untouched shape
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $chainKind = null,
        public readonly array $raw = [],
        public readonly ?string $note = null,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function ok(string $chainKind, array $raw): self
    {
        return new self(self::OUTCOME_OK, $chainKind, $raw);
    }

    public static function unsupportedChain(string $chainId): self
    {
        return new self(self::OUTCOME_UNSUPPORTED_CHAIN, note: "chain '{$chainId}' not covered by GoPlus");
    }

    public static function notIndexed(string $chainKind): self
    {
        return new self(self::OUTCOME_NOT_INDEXED, $chainKind, note: 'token not indexed by GoPlus yet');
    }

    public static function error(string $note): self
    {
        return new self(self::OUTCOME_ERROR, note: mb_substr($note, 0, 300));
    }

    public static function disabled(): self
    {
        return new self(self::OUTCOME_DISABLED, note: 'GoPlus disabled');
    }

    public function hasData(): bool
    {
        return $this->outcome === self::OUTCOME_OK && $this->raw !== [];
    }

    public function isEvm(): bool
    {
        return $this->chainKind === 'evm';
    }

    public function isSolana(): bool
    {
        return $this->chainKind === 'solana';
    }

    /**
     * A GoPlus EVM boolean flag ("1"/"0"). Missing / "" => null (UNKNOWN).
     */
    public function flag(string $key): ?bool
    {
        if (! array_key_exists($key, $this->raw)) {
            return null;
        }
        $v = $this->raw[$key];
        if ($v === '1' || $v === 1 || $v === true) {
            return true;
        }
        if ($v === '0' || $v === 0 || $v === false) {
            return false;
        }

        return null;
    }

    /**
     * A GoPlus decimal string (e.g. `buy_tax` "0.05"). Missing / "" => null.
     * GoPlus expresses tax as a fraction (0.05 == 5%).
     */
    public function decimal(string $key): ?float
    {
        $v = $this->raw[$key] ?? null;
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return (float) $v;
    }

    public function string(string $key): ?string
    {
        $v = $this->raw[$key] ?? null;

        return (is_string($v) && $v !== '') ? $v : null;
    }

    /** @return list<array<string,mixed>> */
    public function holders(): array
    {
        $h = $this->raw['holders'] ?? [];

        return is_array($h) ? array_values(array_filter($h, 'is_array')) : [];
    }

    /** @return list<array<string,mixed>> */
    public function lpHolders(): array
    {
        $h = $this->raw['lp_holders'] ?? [];

        return is_array($h) ? array_values(array_filter($h, 'is_array')) : [];
    }

    public function holderCount(): ?int
    {
        $v = $this->raw['holder_count'] ?? null;

        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    public function ownerPercent(): ?float
    {
        return $this->percent('owner_percent');
    }

    public function creatorPercent(): ?float
    {
        return $this->percent('creator_percent');
    }

    private function percent(string $key): ?float
    {
        $v = $this->raw[$key] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * Solana authority-model field: `{status: "0"|"1", authority: [...]}`.
     * Returns true when the power is LIVE (status "1" or a non-empty authority),
     * false when explicitly renounced, null when the key is absent (UNKNOWN).
     */
    public function solanaAuthorityActive(string $key): ?bool
    {
        if (! array_key_exists($key, $this->raw)) {
            return null;
        }
        $node = $this->raw[$key];
        if (! is_array($node)) {
            return null;
        }
        $status = $node['status'] ?? null;
        $authority = $node['authority'] ?? [];
        if ($status === '1' || (is_array($authority) && $authority !== [])) {
            return true;
        }
        if ($status === '0') {
            return false;
        }

        return null;
    }

    /**
     * Pool / pair / vault addresses this token trades on, from GoPlus `dex[]`
     * (both EVM and Solana). Used to exclude the AMM pool from holder
     * concentration — on Solana the top "holder" is almost always the pool.
     *
     * @return list<string> raw (case preserved — Solana base58 is case-sensitive)
     */
    public function dexPoolAddresses(): array
    {
        $dex = $this->raw['dex'] ?? [];
        if (! is_array($dex)) {
            return [];
        }

        $out = [];
        foreach ($dex as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach (['pair', 'id', 'address', 'lp_address'] as $key) {
                $v = $entry[$key] ?? null;
                if (is_string($v) && $v !== '') {
                    $out[] = $v;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** Solana transfer hook / transfer fee presence. Missing => null. */
    public function solanaHasNode(string $key): ?bool
    {
        if (! array_key_exists($key, $this->raw)) {
            return null;
        }
        $node = $this->raw[$key];
        if (is_array($node)) {
            return $node !== [];
        }

        return null;
    }
}
