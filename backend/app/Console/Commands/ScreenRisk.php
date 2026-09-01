<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Risk\RiskScreeningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deterministic memecoin risk & safety screening (Step 24).
 *
 * All logic lives in {@see RiskScreeningService}. Scheduled a few minutes after
 * discovery + historical qualification and before AI explanations (see
 * routes/console.php). Read APIs never invoke this.
 */
class ScreenRisk extends Command
{
    protected $signature = 'memecoins:screen-risk
        {--force : Ignore the per-token scan cooldown}
        {--token= : Screen only this token ("chain:address"), ignoring the cooldown}';

    protected $description = 'Screen market-cap-qualified memecoins for contract / holder / liquidity / pump-dump risk (deterministic, no AI)';

    public function handle(RiskScreeningService $service): int
    {
        try {
            $result = $service->screen(
                force: (bool) $this->option('force'),
                onlyToken: $this->option('token') !== null ? (string) $this->option('token') : null,
            );
        } catch (Throwable $e) {
            $this->error('Risk screening failed: '.$e->getMessage());
            Log::error('Risk screening failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info('Risk screening completed.');
        $this->newLine();
        $this->line('Tokens analyzed:    '.$result->tokensAnalyzed);
        $this->line('Main-list eligible: '.$result->mainListEligible);
        $this->line('Not main-list:      '.$result->notMainListEligible);
        $this->line('Lower risk:         '.$result->lower);
        $this->line('Medium risk:        '.$result->medium);
        $this->line('High risk:          '.$result->high);
        $this->line('Critical:           '.$result->critical);
        $this->line('Unknown:            '.$result->unknown);
        $this->line('Skipped (cooldown): '.$result->skippedCooldown);
        $this->line('Provider failures:  '.$result->providerFailures);

        return self::SUCCESS;
    }
}
