<?php

return [

    'base_url' => env('AIO_BASE_URL', 'https://app.aio.tech'),
    'token' => env('AIO_TOKEN'),
    'tenant_id' => env('AIO_TENANT_ID'),

    'timeout' => (int) env('AIO_TIMEOUT_SECONDS', 30),
    'connect_timeout' => (int) env('AIO_CONNECT_TIMEOUT_SECONDS', 5),

    'rate_limits' => [
        'per_minute' => (int) env('AIO_RATE_LIMIT_PER_MINUTE', 40),
        'concurrency' => (int) env('AIO_CONCURRENCY_LIMIT', 3),
        'heavy_budget_seconds' => (int) env('AIO_HEAVY_BUDGET_SECONDS', 20),
        'heavy_budget_window_seconds' => 60,
    ],

    'limiter' => [
        'prefix' => 'aio:limit:',
        'max_wait_ms' => (int) env('AIO_MAX_WAIT_MS', 30000),
        'retry_interval_ms' => (int) env('AIO_RETRY_INTERVAL_MS', 100),
    ],

    'cache' => [
        'enabled' => filter_var(env('AIO_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),
        // 3 minutes — same /lps2 / /geo run twice within the window serves
        // from Redis instead of dragging AIO again. Tunable via AIO_CACHE_TTL.
        'default_ttl' => (int) env('AIO_CACHE_TTL', 180),
        'prefix' => 'aio:cache:',
    ],

    'redis_connection' => env('AIO_REDIS_CONNECTION', 'default'),

    /*
    | Default metric names to project from a pivot response. These are exact
    | matches against aio_metrics.name (case-insensitive). The list is what
    | gets rendered when the *user* has no custom preference set — users can
    | override via settings.metrics (per-user pick of any aio_metrics).
    |
    | Display labels + value formatting are in MetricDisplay; this list only
    | controls which metrics show up by default.
    |
    | Order here is the display order in reports.
    */
    'default_metrics' => [
        'Q Visits',          // "clicks" in buyer parlance
        'Q LP1 CTR',
        'Leads',
        'Total FTDs',
        'Real Approve',      // payout-confirmed CR%
        'LP1 Interest Rate',
        'Q LP1 Scroll Avg',
    ],

];
