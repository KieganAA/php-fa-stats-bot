<?php

namespace App\Providers;

use App\Services\Aio\AioClient;
use App\Services\Aio\Http\AioHttpClient;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\MetricResolver;
use App\Services\Aio\Pivot\MvtSlicer;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Aio\Support\RateLimiter;
use App\Services\Aio\Support\ResponseCache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RateLimiter::class, function (Application $app): RateLimiter {
            $cfg = $app['config']['aio'];

            return new RateLimiter(
                prefix: $cfg['limiter']['prefix'],
                perMinute: (int) $cfg['rate_limits']['per_minute'],
                concurrency: (int) $cfg['rate_limits']['concurrency'],
                heavyBudgetSeconds: (int) $cfg['rate_limits']['heavy_budget_seconds'],
                heavyBudgetWindowSeconds: (int) $cfg['rate_limits']['heavy_budget_window_seconds'],
                maxWaitMs: (int) $cfg['limiter']['max_wait_ms'],
                retryIntervalMs: (int) $cfg['limiter']['retry_interval_ms'],
                redisConnection: (string) $cfg['redis_connection'],
            );
        });

        $this->app->singleton(ResponseCache::class, function (Application $app): ResponseCache {
            $cfg = $app['config']['aio'];

            return new ResponseCache(
                prefix: $cfg['cache']['prefix'],
                defaultTtl: (int) $cfg['cache']['default_ttl'],
                enabled: (bool) $cfg['cache']['enabled'],
                redisConnection: (string) $cfg['redis_connection'],
            );
        });

        $this->app->singleton(AioHttpClient::class, function (Application $app): AioHttpClient {
            $cfg = $app['config']['aio'];

            return new AioHttpClient(
                baseUrl: (string) $cfg['base_url'],
                token: (string) ($cfg['token'] ?? ''),
                tenantId: $cfg['tenant_id'] ?? null,
                timeout: (int) $cfg['timeout'],
                connectTimeout: (int) $cfg['connect_timeout'],
                limiter: $app->make(RateLimiter::class),
                cache: $app->make(ResponseCache::class),
            );
        });

        $this->app->singleton(AioClient::class, fn (Application $app) => new AioClient(
            $app->make(AioHttpClient::class),
        ));

        $this->app->singleton(MetricResolver::class);

        $this->app->singleton(LandingReports::class, fn (Application $app) => new LandingReports(
            $app->make(AioClient::class),
        ));

        $this->app->singleton(TargetMetricSet::class, fn (Application $app) => new TargetMetricSet(
            $app->make(MetricResolver::class),
            (array) $app['config']->get('aio.target_metrics', []),
        ));

        $this->app->singleton(MvtSlicer::class, fn (Application $app) => new MvtSlicer(
            $app->make(LandingReports::class),
            $app->make(TargetMetricSet::class),
        ));
    }
}
