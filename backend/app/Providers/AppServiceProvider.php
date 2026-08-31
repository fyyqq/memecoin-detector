<?php

namespace App\Providers;

use App\Services\AI\Providers\AnthropicPumpExplanationProvider;
use App\Services\AI\Providers\NullPumpExplanationProvider;
use App\Services\AI\PumpExplanationProvider;
use App\Services\Narrative\NarrativeExplanationProvider;
use App\Services\Narrative\Providers\AnthropicNarrativeExplanationProvider;
use App\Services\Narrative\Providers\NullNarrativeExplanationProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The AI vendor is chosen by config('ai.provider'). Nothing else in the
        // app names a vendor. An unknown/unset provider resolves to the Null
        // provider, which fails loudly rather than fabricating an explanation.
        $this->app->singleton(PumpExplanationProvider::class, function (): PumpExplanationProvider {
            return match ((string) config('ai.provider')) {
                'anthropic' => new AnthropicPumpExplanationProvider,
                default => new NullPumpExplanationProvider,
            };
        });

        // Step 21 — the narrative AI provider is a SEPARATE binding (not coupled
        // to the pump-explanation vendor). Chosen by config('narrative.ai.provider');
        // unknown/unset => Null (fails loudly, never fabricates a synthesis).
        $this->app->singleton(NarrativeExplanationProvider::class, function (): NarrativeExplanationProvider {
            return match ((string) config('narrative.ai.provider')) {
                'anthropic' => new AnthropicNarrativeExplanationProvider,
                default => new NullNarrativeExplanationProvider,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
