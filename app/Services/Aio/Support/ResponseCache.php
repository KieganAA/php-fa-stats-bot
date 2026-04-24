<?php

namespace App\Services\Aio\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

class ResponseCache
{
    public function __construct(
        private readonly string $prefix,
        private readonly int $defaultTtl,
        private readonly bool $enabled,
        private readonly string $redisConnection = 'default',
    ) {}

    public function get(string $key): mixed
    {
        if (! $this->enabled) {
            return null;
        }

        $raw = $this->connection()->get($this->key($key));

        return $raw !== null ? json_decode($raw, true) : null;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->connection()->setex(
            $this->key($key),
            $ttl ?? $this->defaultTtl,
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    public function forget(string $key): void
    {
        $this->connection()->del($this->key($key));
    }

    public function fingerprint(string $method, string $path, array $query = [], array $body = []): string
    {
        ksort($query);
        ksort($body);

        return hash('xxh128', json_encode([
            'method' => strtoupper($method),
            'path' => $path,
            'query' => $query,
            'body' => $body,
        ]));
    }

    private function key(string $suffix): string
    {
        return $this->prefix.$suffix;
    }

    private function connection(): Connection
    {
        return Redis::connection($this->redisConnection);
    }
}
