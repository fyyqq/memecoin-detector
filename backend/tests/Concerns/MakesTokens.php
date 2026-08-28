<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Helper for the read-API tests: build a qualified Token + one MarketSnapshot
 * directly in the DB (no ingestion pipeline).
 */
trait MakesTokens
{
    /**
     * @param  array<string,mixed>  $token
     * @param  array<string,mixed>  $snapshot
     */
    protected function makeToken(array $token = [], array $snapshot = []): Token
    {
        $now = CarbonImmutable::now();

        /** @var Token $model */
        $model = Token::query()->create(array_replace([
            'chain_id' => 'solana',
            'token_address' => 'Addr'.Str::random(24),
            'symbol' => 'TKN',
            'name' => 'Test Token',
            'earliest_pair_created_at' => $now->subDays(10),
            'first_observed_at' => $now->subDays(3),
            'last_observed_at' => $now,
            'observed_peak_market_cap' => 8_000_000.0,
            'observed_peak_market_cap_at' => $now->subDay(),
        ], $token));

        $model->marketSnapshots()->create(array_replace([
            'observed_at' => $now,
            'price_usd' => 0.01,
            'market_cap' => 2_000_000.0,
            'fdv' => 2_100_000.0,
            'liquidity_usd' => 500_000.0,
            'volume_h24' => 1_000_000.0,
            'price_change_h24' => 1.2,
            'txns_h24' => 100,
            'buys_h24' => 60,
            'sells_h24' => 40,
            'primary_pair_address' => 'pair-abc',
            'primary_dex_id' => 'raydium',
            'earliest_pair_created_at' => $now->subDays(10),
        ], $snapshot));

        return $model->refresh();
    }
}
