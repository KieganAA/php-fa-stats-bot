<?php

namespace App\Providers;

use App\Services\Ai\AiRateLimiter;
use App\Services\Ai\ClaudeClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClaudeClient::class, fn (Application $app) => new ClaudeClient(
            apiKey: (string) $app['config']->get('services.anthropic.api_key', ''),
            model: (string) $app['config']->get('services.anthropic.model', 'claude-haiku-4-5-20251001'),
            maxTokens: (int) $app['config']->get('services.anthropic.max_tokens', 1024),
            timeout: (int) $app['config']->get('services.anthropic.timeout', 60),
        ));

        $this->app->singleton(AiRateLimiter::class, fn (Application $app) => new AiRateLimiter(
            limit: (int) $app['config']->get('services.anthropic.rate_limit', 30),
            windowSeconds: (int) $app['config']->get('services.anthropic.rate_window_seconds', 3600),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
