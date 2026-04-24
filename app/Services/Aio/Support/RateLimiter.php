<?php

namespace App\Services\Aio\Support;

use App\Services\Aio\Exceptions\RateLimitExceededException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

class RateLimiter
{
    private const RPM_SCRIPT = <<<'LUA'
        local key = KEYS[1]
        local now = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local max = tonumber(ARGV[3])
        local token = ARGV[4]
        redis.call('ZREMRANGEBYSCORE', key, 0, now - window)
        local count = redis.call('ZCARD', key)
        if count >= max then
            local oldest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
            local wait_until = tonumber(oldest[2]) + window
            return {0, wait_until}
        end
        redis.call('ZADD', key, now, token)
        redis.call('PEXPIRE', key, window + 1000)
        return {1, 0}
    LUA;

    private const CONCURRENCY_ACQUIRE = <<<'LUA'
        local key = KEYS[1]
        local max = tonumber(ARGV[1])
        local value = tonumber(redis.call('GET', key) or '0')
        if value >= max then
            return 0
        end
        redis.call('INCR', key)
        redis.call('EXPIRE', key, 120)
        return 1
    LUA;

    private const HEAVY_BUDGET_CHECK = <<<'LUA'
        local key = KEYS[1]
        local now = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local budget_ms = tonumber(ARGV[3])
        redis.call('ZREMRANGEBYSCORE', key, 0, now - window)
        local entries = redis.call('ZRANGE', key, 0, -1)
        local sum = 0
        for _, v in ipairs(entries) do
            local _, dur = string.match(v, '([^:]+):([^:]+)')
            sum = sum + (tonumber(dur) or 0)
        end
        if sum >= budget_ms then
            return {0, sum}
        end
        return {1, sum}
    LUA;

    public function __construct(
        private readonly string $prefix,
        private readonly int $perMinute,
        private readonly int $concurrency,
        private readonly int $heavyBudgetSeconds,
        private readonly int $heavyBudgetWindowSeconds,
        private readonly int $maxWaitMs,
        private readonly int $retryIntervalMs,
        private readonly string $redisConnection = 'default',
    ) {}

    public function acquireRpm(): void
    {
        $this->waitFor(
            fn () => $this->tryRpm(),
            'per_minute',
        );
    }

    public function acquireConcurrency(): void
    {
        $this->waitFor(
            fn () => $this->tryConcurrency(),
            'concurrency',
        );
    }

    public function releaseConcurrency(): void
    {
        $this->connection()->decr($this->key('concurrent'));
    }

    public function assertHeavyBudget(): void
    {
        [$ok, $used] = $this->connection()->eval(
            self::HEAVY_BUDGET_CHECK,
            1,
            $this->key('heavy'),
            $this->nowMs(),
            $this->heavyBudgetWindowSeconds * 1000,
            $this->heavyBudgetSeconds * 1000,
        );

        if ((int) $ok === 0) {
            throw new RateLimitExceededException(
                'heavy_budget',
                "AIO heavy budget exhausted: used {$used}ms / {$this->heavyBudgetSeconds}s in rolling {$this->heavyBudgetWindowSeconds}s window"
            );
        }
    }

    public function recordHeavyDuration(int $durationMs): void
    {
        $now = $this->nowMs();
        $this->connection()->zadd(
            $this->key('heavy'),
            [$now.':'.$durationMs => $now],
        );
        $this->connection()->expire($this->key('heavy'), $this->heavyBudgetWindowSeconds + 10);
    }

    private function tryRpm(): bool
    {
        [$ok] = $this->connection()->eval(
            self::RPM_SCRIPT,
            1,
            $this->key('rpm'),
            $this->nowMs(),
            60_000,
            $this->perMinute,
            uniqid('rpm_', true),
        );

        return (int) $ok === 1;
    }

    private function tryConcurrency(): bool
    {
        $ok = $this->connection()->eval(
            self::CONCURRENCY_ACQUIRE,
            1,
            $this->key('concurrent'),
            $this->concurrency,
        );

        return (int) $ok === 1;
    }

    private function waitFor(callable $probe, string $limitName): void
    {
        $deadline = $this->nowMs() + $this->maxWaitMs;

        while (true) {
            if ($probe()) {
                return;
            }

            if ($this->nowMs() >= $deadline) {
                throw new RateLimitExceededException(
                    $limitName,
                    "AIO rate limit '{$limitName}' not acquired within {$this->maxWaitMs}ms"
                );
            }

            usleep($this->retryIntervalMs * 1000);
        }
    }

    private function key(string $suffix): string
    {
        return $this->prefix.$suffix;
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private function connection(): Connection
    {
        return Redis::connection($this->redisConnection);
    }
}
