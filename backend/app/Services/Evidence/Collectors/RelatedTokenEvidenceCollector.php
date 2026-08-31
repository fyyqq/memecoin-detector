<?php

declare(strict_types=1);

namespace App\Services\Evidence\Collectors;

use App\Models\Evidence;
use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use App\Models\Token;
use App\Services\Evidence\EvidenceCandidate;
use App\Services\Evidence\EvidenceCollector;
use App\Services\Evidence\EvidenceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * RELATED_TOKEN evidence — OTHER tracked tokens that moved strongly in the
 * window BEFORE this event started.
 *
 * PostgreSQL only, **no external HTTP, no internet search.** This is NOT the
 * future TokenRelation graph. It records a neutral temporal fact:
 *
 *   "Tracked token ANSEM (ANSEM, solana) rose 84% during the 40 minutes before
 *    this event's start."
 *
 * It NEVER states that the other token caused this pump. Confidence reflects the
 * strength of the temporal signal only and is never HIGH.
 */
class RelatedTokenEvidenceCollector implements EvidenceCollector
{
    private int $leadWindowMinutes;

    private float $minimumMovePct;

    private int $maxRelated;

    private bool $crossChain;

    public function __construct()
    {
        $this->leadWindowMinutes = (int) config('evidence.related.lead_window_minutes', 60);
        $this->minimumMovePct = (float) config('evidence.related.minimum_move_pct', 40);
        $this->maxRelated = max(1, (int) config('evidence.related.max_related', 5));
        $this->crossChain = (bool) config('evidence.related.cross_chain', false);
    }

    public function name(): string
    {
        return 'related_token';
    }

    public function isExternal(): bool
    {
        return false;
    }

    /**
     * @return list<EvidenceCandidate>
     */
    public function collect(PumpEvent $event, Token $token, EvidenceWindow $window): array
    {
        $leadStart = $window->eventStart->subMinutes($this->leadWindowMinutes);
        $leadEnd = $window->eventStart;

        $peerIds = Token::query()
            ->where('id', '!=', $token->id)
            ->when(! $this->crossChain, fn ($q) => $q->where('chain_id', $token->chain_id))
            ->pluck('id')
            ->all();

        if ($peerIds === []) {
            return [];
        }

        /** @var Collection<int, Collection<int, MarketSnapshot>> $byToken */
        $byToken = MarketSnapshot::query()
            ->select(['id', 'token_id', 'observed_at', 'market_cap', 'price_usd'])
            ->whereIn('token_id', $peerIds)
            ->whereBetween('observed_at', [$leadStart, $leadEnd])
            ->orderBy('token_id')
            ->orderBy('observed_at')
            ->limit(4000)
            ->get()
            ->groupBy('token_id');

        /** @var list<array{token_id:int,move_pct:float,from:float,to:float,from_at:CarbonImmutable,to_at:CarbonImmutable}> $moves */
        $moves = [];
        foreach ($byToken as $tokenId => $rows) {
            $move = $this->strongestRise($rows->all());
            if ($move !== null && $move['move_pct'] >= $this->minimumMovePct) {
                $moves[] = ['token_id' => (int) $tokenId] + $move;
            }
        }

        if ($moves === []) {
            return [];
        }

        usort($moves, fn (array $a, array $b): int => $b['move_pct'] <=> $a['move_pct']);
        $moves = array_slice($moves, 0, $this->maxRelated);

        /** @var Collection<int, Token> $peers */
        $peers = Token::query()->whereIn('id', array_column($moves, 'token_id'))->get()->keyBy('id');

        $out = [];
        foreach ($moves as $m) {
            /** @var Token|null $peer */
            $peer = $peers->get($m['token_id']);
            if ($peer === null) {
                continue;
            }

            $gapMinutes = (int) round(abs($m['to_at']->diffInMinutes($window->eventStart, false)));
            $strongMove = $m['move_pct'] >= $this->minimumMovePct * 2;
            $confidence = ($strongMove && $gapMinutes <= 20)
                ? Evidence::CONFIDENCE_MEDIUM
                : Evidence::CONFIDENCE_LOW;

            $namesRelated = $this->namesLookRelated($token, $peer);

            $relevance = (int) round(min(100,
                40
                + min(35.0, $m['move_pct'] / 5.0)
                + max(0.0, 25.0 - $gapMinutes * 25.0 / max(1, $this->leadWindowMinutes))
                + ($namesRelated ? 8 : 0),
            ));

            $summary = sprintf(
                'Tracked token %s (%s, %s) rose %.0f%% during the %d minutes before this event started — from $%s at %s to $%s at %s.',
                $peer->name ?? 'unnamed',
                $peer->symbol ?? '—',
                $peer->chain_id,
                $m['move_pct'],
                $this->leadWindowMinutes,
                $this->compact($m['from']),
                $m['from_at']->toIso8601String(),
                $this->compact($m['to']),
                $m['to_at']->toIso8601String(),
            );
            if ($namesRelated) {
                $summary .= " The two tokens' names or symbols appear related. This is a temporal observation only — it does not indicate causation.";
            } else {
                $summary .= ' This is a temporal observation only — it does not indicate causation.';
            }

            $out[] = new EvidenceCandidate(
                category: Evidence::CATEGORY_RELATED_TOKEN,
                source: 'internal',
                sourceUrl: null,
                title: sprintf('%s moved before this event', $peer->symbol ?? ($peer->name ?? 'related token')),
                observedAt: $m['to_at'],
                publishedAt: null,
                relevanceScore: $relevance,
                confidence: $confidence,
                summary: $summary,
                rawReference: 'token:'.$peer->id,
            );
        }

        return $out;
    }

    /**
     * The strongest low→high rise within the lead-window snapshots (min value
     * at-or-before the max value).
     *
     * @param  list<MarketSnapshot>  $rows  ordered by observed_at ASC
     * @return array{move_pct:float,from:float,to:float,from_at:CarbonImmutable,to_at:CarbonImmutable}|null
     */
    private function strongestRise(array $rows): ?array
    {
        $values = [];
        foreach ($rows as $r) {
            $v = $r->market_cap ?? $r->price_usd;
            if ($v !== null && $v > 0 && $r->observed_at instanceof CarbonImmutable) {
                $values[] = ['v' => $v, 'at' => $r->observed_at];
            }
        }
        if (count($values) < 2) {
            return null;
        }

        $best = null;
        $runningMin = $values[0];
        foreach ($values as $point) {
            if ($point['v'] < $runningMin['v']) {
                $runningMin = $point;
            }
            if ($runningMin['v'] > 0) {
                $pct = ($point['v'] - $runningMin['v']) / $runningMin['v'] * 100;
                if ($best === null || $pct > $best['move_pct']) {
                    $best = [
                        'move_pct' => $pct,
                        'from' => $runningMin['v'],
                        'to' => $point['v'],
                        'from_at' => $runningMin['at'],
                        'to_at' => $point['at'],
                    ];
                }
            }
        }

        return $best;
    }

    private function namesLookRelated(Token $a, Token $b): bool
    {
        $tokens = static fn (Token $t): array => array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower(($t->name ?? '').' '.($t->symbol ?? ''))) ?: [],
            fn (string $w): bool => mb_strlen($w) >= 5
                && ! in_array($w, ['token', 'coin', 'meme', 'inu', 'pepe', 'crypto', 'solana', 'official'], true),
        );

        return array_intersect($tokens($a), $tokens($b)) !== [];
    }

    private function compact(float $value): string
    {
        $abs = abs($value);

        return match (true) {
            $abs >= 1_000_000 => number_format($value / 1_000_000, 2).'M',
            $abs >= 1_000 => number_format($value / 1_000, 1).'K',
            $abs >= 1 => number_format($value, 2),
            default => rtrim(rtrim(sprintf('%.8f', $value), '0'), '.'),
        };
    }
}
