<?php

declare(strict_types=1);

namespace Tests\Unit\DexScreener;

use App\Services\DexScreener\DexScreenerNormalizer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DexScreenerNormalizerTest extends TestCase
{
    private DexScreenerNormalizer $normalizer;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new DexScreenerNormalizer;
        $this->now = CarbonImmutable::parse('2026-08-28T00:00:00Z');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function pair(string $tokenAddress, array $overrides = []): array
    {
        return array_replace([
            'chainId' => 'solana',
            'dexId' => 'raydium',
            'pairAddress' => 'PAIR-'.substr(md5($tokenAddress.serialize($overrides)), 0, 8),
            'baseToken' => ['address' => $tokenAddress, 'name' => 'Test Token', 'symbol' => 'TEST'],
            'quoteToken' => ['address' => 'So11111111111111111111111111111111111111112', 'symbol' => 'SOL'],
            'priceUsd' => '0.5',
            'liquidity' => ['usd' => 100_000.0],
            'volume' => ['h24' => 12_345.6],
            'priceChange' => ['h24' => 4.2],
            'txns' => ['h24' => ['buys' => 10, 'sells' => 7]],
            'fdv' => 9_000_000.0,
            'marketCap' => 8_000_000.0,
            'pairCreatedAt' => $this->now->subDays(10)->getTimestampMs(),
        ], $overrides);
    }

    #[Test]
    public function it_selects_the_earliest_pair_created_at_across_multiple_pairs(): void
    {
        $addr = 'Token1111111111111111111111111111111111111';
        $old = $this->now->subDays(200)->getTimestampMs();
        $mid = $this->now->subDays(40)->getTimestampMs();
        $new = $this->now->subDays(3)->getTimestampMs();

        $dto = $this->normalizer->normalize('solana', $addr, [
            $this->pair($addr, ['pairCreatedAt' => $new, 'liquidity' => ['usd' => 10.0]]),
            $this->pair($addr, ['pairCreatedAt' => $old, 'liquidity' => ['usd' => 20.0]]),
            $this->pair($addr, ['pairCreatedAt' => $mid, 'liquidity' => ['usd' => 30.0]]),
        ], ['search'], $this->now);

        $this->assertNotNull($dto);
        $this->assertSame(
            CarbonImmutable::createFromTimestampMs($old)->toIso8601String(),
            $dto->earliestPairCreatedAt?->toIso8601String(),
        );
        $this->assertEqualsWithDelta(200.0, $dto->ageDays, 0.01);
        $this->assertSame(3, $dto->pairCount);
    }

    #[Test]
    public function it_picks_the_highest_liquidity_pair_as_representative(): void
    {
        $addr = 'Token2222222222222222222222222222222222222';

        $dto = $this->normalizer->normalize('solana', $addr, [
            $this->pair($addr, ['liquidity' => ['usd' => 5_000.0], 'dexId' => 'small', 'marketCap' => 1.0]),
            $this->pair($addr, ['liquidity' => ['usd' => 900_000.0], 'dexId' => 'big', 'marketCap' => 7_000_000.0]),
            $this->pair($addr, ['liquidity' => ['usd' => 50_000.0], 'dexId' => 'mid', 'marketCap' => 2.0]),
        ], ['boost'], $this->now);

        $this->assertNotNull($dto);
        $this->assertSame('big', $dto->primaryDexId);
        $this->assertSame(900_000.0, $dto->liquidityUsd);
        $this->assertSame(7_000_000.0, $dto->marketCap);
    }

    #[Test]
    public function null_pair_created_at_yields_null_age_not_an_error(): void
    {
        $addr = 'Token3333333333333333333333333333333333333';

        $dto = $this->normalizer->normalize('solana', $addr, [
            $this->pair($addr, ['pairCreatedAt' => null]),
        ], ['search'], $this->now);

        $this->assertNotNull($dto);
        $this->assertNull($dto->earliestPairCreatedAt);
        $this->assertNull($dto->ageDays);
    }

    #[Test]
    public function null_liquidity_on_every_pair_does_not_crash_and_falls_back_deterministically(): void
    {
        $addr = 'Token4444444444444444444444444444444444444';

        $pairs = [
            $this->pair($addr, ['liquidity' => ['usd' => null], 'pairAddress' => 'zzz', 'dexId' => 'z']),
            $this->pair($addr, ['liquidity' => null, 'pairAddress' => 'aaa', 'dexId' => 'a']),
        ];

        $dto = $this->normalizer->normalize('solana', $addr, $pairs, ['search'], $this->now);

        $this->assertNotNull($dto);
        $this->assertNull($dto->liquidityUsd);
        // Deterministic fallback: lexicographically smallest pairAddress.
        $this->assertSame('a', $dto->primaryDexId);
    }

    #[Test]
    public function same_symbol_different_addresses_produce_distinct_token_keys(): void
    {
        $a = 'AAAA111111111111111111111111111111111111111';
        $b = 'BBBB222222222222222222222222222222222222222';

        $dtoA = $this->normalizer->normalize('solana', $a, [
            $this->pair($a, ['baseToken' => ['address' => $a, 'name' => 'Pepe A', 'symbol' => 'PEPE']]),
        ], ['search'], $this->now);
        $dtoB = $this->normalizer->normalize('solana', $b, [
            $this->pair($b, ['baseToken' => ['address' => $b, 'name' => 'Pepe B', 'symbol' => 'PEPE']]),
        ], ['search'], $this->now);

        $this->assertNotNull($dtoA);
        $this->assertNotNull($dtoB);
        $this->assertSame('PEPE', $dtoA->symbol);
        $this->assertSame('PEPE', $dtoB->symbol);
        $this->assertNotSame($dtoA->tokenKey, $dtoB->tokenKey);
    }

    #[Test]
    public function size_basis_is_fdv_when_market_cap_missing(): void
    {
        $addr = 'Token5555555555555555555555555555555555555';

        $dto = $this->normalizer->normalize('solana', $addr, [
            $this->pair($addr, ['marketCap' => null, 'fdv' => 12_000_000.0]),
        ], ['search'], $this->now);

        $this->assertNotNull($dto);
        $this->assertNull($dto->marketCap);
        $this->assertSame(12_000_000.0, $dto->fdv);
        $this->assertSame('fdv', $dto->sizeBasis);
    }

    #[Test]
    public function empty_pair_list_returns_null(): void
    {
        $dto = $this->normalizer->normalize('solana', 'whatever', [], ['search'], $this->now);

        $this->assertNull($dto);
    }
}
