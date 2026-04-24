<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Redis;

/**
 * Sliding-window per-user rate limit for AI calls.
 *
 * Uses a Redis sorted set keyed by user id; entries are timestamps. On each
 * check we trim entries older than the window, count what's left, and either
 * accept (recording the new timestamp) or reject.
 */
class AiRateLimiter
{
    public function __construct(
        private readonly int $limit,
        private readonly int $windowSeconds,
        private readonly string $prefix = 'ai:rate',
    ) {}

    /**
     * Try to consume one slot for the given subject (e.g. Telegram user id).
     * Returns true if allowed, false if over the limit.
     */
    public function attempt(string $subject): bool
    {
        if ($this->limit <= 0) {
            return true;
        }

        $key = $this->prefix.':'.$subject;
        $now = (int) (microtime(true) * 1000);
        $cutoff = $now - $this->windowSeconds * 1000;

        $conn = Redis::connection();
        $conn->zremrangebyscore($key, '-inf', (string) $cutoff);
        $count = (int) $conn->zcard($key);

        if ($count >= $this->limit) {
            return false;
        }

        $conn->zadd($key, $now, $now.':'.bin2hex(random_bytes(4)));
        $conn->expire($key, $this->windowSeconds + 1);

        return true;
    }
}
