<?php

namespace App\Providers;

use App\Services\Ai\AiRateLimiter;
use App\Services\Ai\ClaudeClient;
use App\Services\Auth\AppContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;

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

        // Per-request slot for the resolved User. Singleton in non-Octane
        // contexts (one request per process); the listener below resets it
        // between Octane requests so a long-running worker doesn't leak the
        // previous request's user into the next one.
        $this->app->singleton(AppContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Reset the per-request user slot between Octane requests. Outside of
        // Octane this event never fires and the singleton just lives for the
        // duration of a single request anyway.
        $this->app['events']->listen(RequestReceived::class, function () {
            $this->app->make(AppContext::class)->clear();
        });
    }
}
