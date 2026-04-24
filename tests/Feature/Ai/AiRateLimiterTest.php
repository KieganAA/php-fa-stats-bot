<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiRateLimiter;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AiRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
    }

    public function test_allows_under_limit_then_rejects(): void
    {
        $limiter = new AiRateLimiter(limit: 3, windowSeconds: 60, prefix: 'test:rl');

        $this->assertTrue($limiter->attempt('user-1'));
        $this->assertTrue($limiter->attempt('user-1'));
        $this->assertTrue($limiter->attempt('user-1'));
        $this->assertFalse($limiter->attempt('user-1'));
    }

    public function test_subjects_are_independent(): void
    {
        $limiter = new AiRateLimiter(limit: 1, windowSeconds: 60, prefix: 'test:rl');

        $this->assertTrue($limiter->attempt('a'));
        $this->assertFalse($limiter->attempt('a'));
        $this->assertTrue($limiter->attempt('b'));
    }

    public function test_zero_limit_means_unlimited(): void
    {
        $limiter = new AiRateLimiter(limit: 0, windowSeconds: 60, prefix: 'test:rl');

        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($limiter->attempt('user'));
        }
    }

    public function test_old_entries_are_trimmed_outside_window(): void
    {
        $limiter = new AiRateLimiter(limit: 2, windowSeconds: 1, prefix: 'test:rl');

        $this->assertTrue($limiter->attempt('user'));
        $this->assertTrue($limiter->attempt('user'));
        $this->assertFalse($limiter->attempt('user'));

        // Past the window: re-acquire works.
        usleep(1_100_000);
        $this->assertTrue($limiter->attempt('user'));
    }
}
