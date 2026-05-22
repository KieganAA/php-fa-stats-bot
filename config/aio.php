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
        'default_ttl' => (int) env('AIO_CACHE_TTL', 60),
        'prefix' => 'aio:cache:',
    ],

    'redis_connection' => env('AIO_REDIS_CONNECTION', 'default'),

    /*
    | Target metrics for landing/MVT reports. Map a stable slug to the
    | exact metric `name` in `aio_metrics` (case-insensitive match).
    | Display labels + value formatting live in MetricDisplay — see that
    | class for the user-facing presentation rules.
    |
    | Why these specific AIO names:
    |   clicks      → "Q Visits"    — what AIO UI shows as "Q visits", the
    |                                 qualified-traffic number. "LP1 Clicks"
    |                                 is a narrower technical metric that
    |                                 doesn't match what buyers think of as
    |                                 "clicks".
    |   real_cr     → "Real Approve" — buyers refer to this as "CR" (the
    |                                  payout-confirmed conversion rate),
    |                                  even though AIO calls it Real Approve.
    |   ftds_real   → "Total FTDs"  — matches the AIO UI "Total FTDs" column.
    */
    'target_metrics' => [
        'clicks' => 'Q Visits',
        'lp_ctr' => 'Q LP1 CTR',
        'leads' => 'Leads',
        'ftds_real' => 'Total FTDs',
        'real_cr' => 'Real Approve',
        'interest_rate' => 'LP1 Interest Rate',
        'scrolling' => 'Q LP1 Scroll Avg',
    ],

];
