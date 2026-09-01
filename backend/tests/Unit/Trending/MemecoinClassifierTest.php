<?php

declare(strict_types=1);

namespace Tests\Unit\Trending;

use App\Services\Trending\MemecoinClassifier;
use App\Services\Trending\TrendingCandidate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MemecoinClassifier — deterministic, config-driven, no AI. TRUE = a clear
 * memecoin, FALSE = a clear non-memecoin, UNKNOWN = ambiguous (kept OUT of
 * Trending Now).
 */
class MemecoinClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('trending.memecoin.deny_symbols', ['usdc', 'usdt', 'weth', 'jitosol']);
        config()->set('trending.memecoin.deny_name_patterns', ['staked ether', 'wrapped ', 'lending protocol']);
        config()->set('trending.memecoin.meme_meta_slugs', ['dog', 'cat', 'frog', 'degen', 'meme', 'animal']);
        config()->set('trending.memecoin.utility_meta_slugs', ['ai', 'nft', 'defi']);
        config()->set('trending.memecoin.meme_keywords', ['pepe', 'doge', 'wif', 'bonk', 'inu', 'moon', 'cat']);
    }

    /**
     * @param  list<string>  $metaSlugs
     */
    private function candidate(string $name, string $symbol, array $metaSlugs = []): TrendingCandidate
    {
        return new TrendingCandidate(
            chainId: 'solana', tokenAddress: 'X'.$symbol, pairAddress: 'P', dexId: 'raydium',
            symbol: $symbol, name: $name, marketCap: 10_000_000.0, liquidityUsd: 100_000.0,
            pairCreatedAt: CarbonImmutable::now()->subDays(5),
            volume6h: 1.0, volume24h: 1.0, priceChange6h: 1.0, priceChange24h: 1.0, txns6h: 1, txns24h: 1,
            trendingMetaSlug: $metaSlugs[0] ?? 'x', trendingMetaName: 'X', metaSlugs: $metaSlugs,
            capturedAt: CarbonImmutable::now(),
        );
    }

    private function classifier(): MemecoinClassifier
    {
        return new MemecoinClassifier;
    }

    #[Test]
    public function a_meme_narrative_meta_makes_it_true(): void
    {
        $this->assertSame(MemecoinClassifier::TRUE, $this->classifier()->classify($this->candidate('Zorptron', 'ZORP', ['degen'])));
    }

    #[Test]
    public function a_meme_keyword_in_the_name_makes_it_true_even_from_a_utility_meta(): void
    {
        $this->assertSame(MemecoinClassifier::TRUE, $this->classifier()->classify($this->candidate('AI Pepe', 'AIPEPE', ['ai'])));
    }

    #[Test]
    public function a_stablecoin_symbol_is_false(): void
    {
        $this->assertSame(MemecoinClassifier::FALSE, $this->classifier()->classify($this->candidate('USD Coin', 'USDC', ['degen'])));
    }

    #[Test]
    public function a_wrapped_asset_is_false(): void
    {
        $this->assertSame(MemecoinClassifier::FALSE, $this->classifier()->classify($this->candidate('Wrapped Ether', 'WETH', [])));
        $this->assertSame(MemecoinClassifier::FALSE, $this->classifier()->classify($this->candidate('Wrapped BTC', 'WBTC', ['dog'])));
    }

    #[Test]
    public function a_liquid_staking_token_is_false(): void
    {
        $this->assertSame(MemecoinClassifier::FALSE, $this->classifier()->classify($this->candidate('Lido Staked Ether', 'STETH', [])));
        $this->assertSame(MemecoinClassifier::FALSE, $this->classifier()->classify($this->candidate('Jito Staked SOL', 'JITOSOL', ['degen'])));
    }

    #[Test]
    public function an_ambiguous_token_with_no_signal_is_unknown_and_not_eligible(): void
    {
        $verdict = $this->classifier()->classify($this->candidate('Neural Compute Net', 'NEURA', ['ai']));
        $this->assertSame(MemecoinClassifier::UNKNOWN, $verdict);
        $this->assertFalse($this->classifier()->isEligibleForTrending($verdict));
    }

    #[Test]
    public function only_true_is_eligible_for_trending(): void
    {
        $c = $this->classifier();
        $this->assertTrue($c->isEligibleForTrending(MemecoinClassifier::TRUE));
        $this->assertFalse($c->isEligibleForTrending(MemecoinClassifier::FALSE));
        $this->assertFalse($c->isEligibleForTrending(MemecoinClassifier::UNKNOWN));
    }

    #[Test]
    public function it_is_deterministic(): void
    {
        $candidate = $this->candidate('Some Frog Coin', 'FROG', ['frog']);
        $this->assertSame($this->classifier()->classify($candidate), $this->classifier()->classify($candidate));
    }
}
