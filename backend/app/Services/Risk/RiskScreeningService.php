<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\MarketSnapshot;
use App\Models\PumpEvent;
use App\Models\RiskAssessment;
use App\Models\Token;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates deterministic risk screening (Step 24).
 *
 * Runs AFTER discovery + historical qualification. For each market-cap-qualified
 * token due for a (re)scan it fetches the provider data (GoPlus primary,
 * GeckoTerminal `/info` secondary, DexScreener pairs for liquidity structure),
 * runs {@see RiskSignalEvaluator} + {@see RiskScoreCalculator}, and persists one
 * {@see RiskAssessment} via {@see RiskSnapshotRecorder}.
 *
 * It NEVER changes market-cap qualification, `observed_peak_market_cap`, pump
 * events or evidence. Read APIs never call this service.
 */
class RiskScreeningService
{
    private const SNAPSHOT_WINDOW = 400;

    private const PUMP_EVENT_WINDOW = 20;

    public function __construct(
        private readonly GoPlusSecurityClient $goplus,
        private readonly GeckoTerminalInfoClient $geckoterminal,
        private readonly DexScreenerLiquidityProbe $liquidityProbe,
        private readonly ChartShapeAnalyzer $chartShapeAnalyzer,
        private readonly HolderConcentrationAnalyzer $holderAnalyzer,
        private readonly RiskSignalEvaluator $evaluator,
        private readonly RiskScoreCalculator $calculator,
        private readonly RiskSnapshotRecorder $recorder,
    ) {}

    /**
     * @param  string|null  $onlyToken  "chain:address" — screen just this token, ignoring the cooldown
     */
    public function screen(bool $force = false, ?string $onlyToken = null, ?CarbonImmutable $now = null): RiskScreeningRunResult
    {
        $now ??= CarbonImmutable::now();
        $startedAt = microtime(true);

        $this->goplus->resetBudget();
        $this->geckoterminal->resetBudget();
        $this->liquidityProbe->resetBudget();

        $result = new RiskScreeningRunResult;

        $tokens = $this->selectTokens($force, $onlyToken, $now, $result);

        foreach ($tokens as $token) {
            try {
                $this->screenToken($token, $now, $result);
            } catch (Throwable $e) {
                $result->providerFailures++;
                Log::warning('Risk screening failed for token', ['token_id' => $token->id, 'error' => $e->getMessage()]);
            }
        }

        $result->durationSeconds = round(microtime(true) - $startedAt, 2);
        Log::info('Risk screening completed', $result->toArray());

        return $result;
    }

    /**
     * @return Collection<int, Token>
     */
    private function selectTokens(bool $force, ?string $onlyToken, CarbonImmutable $now, RiskScreeningRunResult $result): Collection
    {
        $maxPerRun = (int) config('risk.run.max_tokens_per_run', 15);
        $cooldownHours = (int) config('risk.run.scan_cooldown_hours', 6);
        $cooldownCutoff = $now->subHours($cooldownHours);

        $query = Token::query()->marketCapQualified($now)->with('riskAssessment');

        if ($onlyToken !== null) {
            [$chain, $address] = array_pad(explode(':', $onlyToken, 2), 2, '');

            return $query
                ->where('chain_id', mb_strtolower($chain))
                ->where(fn ($q) => $q->where('token_address', $address)->orWhereRaw('lower(token_address) = ?', [mb_strtolower($address)]))
                ->get();
        }

        /** @var Collection<int, Token> $candidates */
        $candidates = $query->limit(500)->get();

        // never-screened first, then oldest screened_at.
        $due = $candidates
            ->sortBy(fn (Token $t): int => $t->riskAssessment?->screened_at?->getTimestamp() ?? -1)
            ->filter(function (Token $t) use ($force, $cooldownCutoff, $result): bool {
                if ($force) {
                    return true;
                }
                $screenedAt = $t->riskAssessment?->screened_at;
                if ($screenedAt !== null && $screenedAt->greaterThan($cooldownCutoff)) {
                    $result->skippedCooldown++;

                    return false;
                }

                return true;
            })
            ->take($maxPerRun)
            ->values();

        return $due;
    }

    private function screenToken(Token $token, CarbonImmutable $now, RiskScreeningRunResult $result): void
    {
        /** @var Collection<int, MarketSnapshot> $snapshots */
        $snapshots = $token->marketSnapshots()
            ->orderByDesc('observed_at')
            ->limit(self::SNAPSHOT_WINDOW)
            ->get();

        $latest = $snapshots->first();

        /** @var Collection<int, PumpEvent> $pumpEvents */
        $pumpEvents = $token->pumpEvents()
            ->orderByDesc('started_at')
            ->limit(self::PUMP_EVENT_WINDOW)
            ->get();

        $goplus = $this->safeGoplus($token, $result);
        $gt = $this->safeGeckoterminal($token);
        $liquidity = $this->liquidityProbe->structure($token->chain_id, $token->token_address);

        $chartShape = $this->chartShapeAnalyzer->analyze($snapshots, $pumpEvents, $now);
        $holders = $this->holderAnalyzer->analyze($goplus, $gt, $liquidity->poolAddresses(), $latest?->market_cap);

        $ctx = new TokenRiskContext(
            token: $token,
            latestSnapshot: $latest,
            snapshots: $snapshots,
            pumpEvents: $pumpEvents,
            goplus: $goplus,
            geckoterminal: $gt,
            liquidity: $liquidity,
            chartShape: $chartShape,
            holders: $holders,
            now: $now,
        );

        $signals = $this->evaluator->evaluate($ctx);
        $screeningStatus = $this->screeningStatus($goplus, $gt, $liquidity);
        $assessment = $this->calculator->calculate($signals, $screeningStatus);

        $this->recorder->record($token, $assessment, $now);

        $result->tokensAnalyzed++;
        match ($assessment->level) {
            RiskAssessment::LEVEL_LOWER => $result->lower++,
            RiskAssessment::LEVEL_MEDIUM => $result->medium++,
            RiskAssessment::LEVEL_HIGH => $result->high++,
            RiskAssessment::LEVEL_CRITICAL => $result->critical++,
            default => $result->unknown++,
        };

        // Live maturity gate — matches MainListDecision.
        $minAgeHours = (int) config('risk.main_list.min_age_hours', 72);
        $ageHours = $token->earliest_pair_created_at !== null
            ? ($now->getTimestamp() - $token->earliest_pair_created_at->getTimestamp()) / 3600.0
            : 0.0;
        $mainEligible = $assessment->mainListEligible && $ageHours >= $minAgeHours;

        $mainEligible ? $result->mainListEligible++ : $result->notMainListEligible++;
    }

    private function safeGoplus(Token $token, RiskScreeningRunResult $result): GoPlusSecurityLookup
    {
        try {
            $lookup = $this->goplus->security($token->chain_id, $token->token_address);
        } catch (Throwable $e) {
            $result->providerFailures++;

            return GoPlusSecurityLookup::error($e->getMessage());
        }

        if ($lookup->outcome === GoPlusSecurityLookup::OUTCOME_ERROR) {
            $result->providerFailures++;
        }

        return $lookup;
    }

    private function safeGeckoterminal(Token $token): GeckoTerminalInfoLookup
    {
        try {
            return $this->geckoterminal->info($token->chain_id, $token->token_address);
        } catch (Throwable $e) {
            return GeckoTerminalInfoLookup::error($e->getMessage());
        }
    }

    private function screeningStatus(GoPlusSecurityLookup $goplus, GeckoTerminalInfoLookup $gt, LiquidityStructure $liquidity): string
    {
        if ($goplus->hasData()) {
            return RiskAssessment::STATUS_COMPLETED;
        }
        if ($gt->hasData() || $liquidity->available) {
            return RiskAssessment::STATUS_PARTIAL;
        }

        return RiskAssessment::STATUS_FAILED;
    }
}
