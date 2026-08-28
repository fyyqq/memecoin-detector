<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemecoinDetailResource;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class MemecoinDetailController extends Controller
{
    /**
     * How many recent observations to return with the detail payload. Newest
     * first. A hard cap — we never load a token's full snapshot history.
     */
    private const RECENT_SNAPSHOT_LIMIT = 50;

    /**
     * GET /api/memecoins/{chainId}/{tokenAddress}
     *
     * Read-only token detail, straight from PostgreSQL. Never calls DexScreener,
     * never writes, never runs discovery.
     *
     * Identity is (chain_id, token_address) — never the symbol. Dashboard
     * qualification (age <= 30d AND observed peak >= $5M) is NOT applied here:
     * any Token we have ever persisted is viewable, even if it later fell below
     * the threshold or aged out.
     */
    public function __invoke(string $chainId, string $tokenAddress): Responsable|JsonResponse
    {
        $token = Token::query()
            ->where('chain_id', Str::lower($chainId))
            ->where(function ($query) use ($tokenAddress): void {
                // Exact match first (Solana base58 is case-sensitive); fall back
                // to a case-insensitive match so checksum-cased EVM addresses in
                // a hand-typed URL still resolve.
                $query->where('token_address', $tokenAddress)
                    ->orWhereRaw('lower(token_address) = ?', [Str::lower($tokenAddress)]);
            })
            ->first();

        if ($token === null) {
            return response()->json(['error' => 'Memecoin not found.'], 404);
        }

        // 1 query for the bounded recent window; its first row is the latest
        // observation, so no separate latestSnapshot query is needed.
        $snapshots = $token->marketSnapshots()
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_SNAPSHOT_LIMIT)
            ->get();

        $token->setRelation('recentSnapshots', $snapshots);
        $token->setRelation('latestSnapshot', $snapshots->first());

        return MemecoinDetailResource::make($token)->additional([
            'meta' => [
                'retrieved_at' => CarbonImmutable::now()->toIso8601String(),
                'recent_snapshot_limit' => self::RECENT_SNAPSHOT_LIMIT,
                'observed_peak_note' => 'observed_peak_market_cap is the highest market cap captured by this detector since first_observed_at — not a guaranteed lifetime / all-time high.',
            ],
        ]);
    }
}
