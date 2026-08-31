<?php

namespace App\Providers;

use App\Services\AI\Providers\AnthropicPumpExplanationProvider;
use App\Services\AI\Providers\NullPumpExplanationProvider;
use App\Services\AI\PumpExplanationProvider;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
