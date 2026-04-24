<?php

namespace Tests\Feature\Aio;

use App\Services\Aio\Exceptions\RateLimitExceededException;
use App\Services\Aio\Support\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $conn = Redis::connection();
        foreach (['aio:test:rpm', 'aio:test:concurrent', 'aio:test:heavy'] as $key) {
            $conn->del($key);
        }
    }

    public function test_releases_concurrency_slot(): void
    {
        $limiter = $this->makeLimiter(concurrency: 1);

        $limiter->acquireConcurrency();
        $limiter->releaseConcurrency();
        $limiter->acquireConcurrency(); // must not throw
        $limiter->releaseConcurrency();

        $this->assertTrue(true);
    }

    public function test_throws_when_concurrency_exhausted(): void
    {
        $limiter = $this->makeLimiter(concurrency: 1, maxWaitMs: 200, retryIntervalMs: 50);

        $limiter->acquireConcurrency();

        try {
            $this->expectException(RateLimitExceededException::class);
            $limiter->acquireConcurrency();
        } finally {
            $limiter->releaseConcurrency();
        }
    }

    public function test_heavy_budget_exhaustion_blocks(): void
    {
        $limiter = $this->makeLimiter(heavyBudgetSeconds: 1);

        $limiter->recordHeavyDuration(1500);

        $this->expectException(RateLimitExceededException::class);
        $limiter->assertHeavyBudget();
    }

    public function test_heavy_budget_allows_under_cap(): void
    {
        $limiter = $this->makeLimiter(heavyBudgetSeconds: 5);

        $limiter->recordHeavyDuration(2000);
        $limiter->assertHeavyBudget();

        $this->assertTrue(true);
    }

    private function makeLimiter(
        int $perMinute = 60,
        int $concurrency = 3,
        int $heavyBudgetSeconds = 20,
        int $maxWaitMs = 500,
        int $retryIntervalMs = 50,
    ): RateLimiter {
        return new RateLimiter(
            prefix: 'aio:test:',
            perMinute: $perMinute,
            concurrency: $concurrency,
            heavyBudgetSeconds: $heavyBudgetSeconds,
            heavyBudgetWindowSeconds: 60,
            maxWaitMs: $maxWaitMs,
            retryIntervalMs: $retryIntervalMs,
        );
    }
}
