<?php

namespace App\Services\Aio\Http;

use App\Services\Aio\Exceptions\UpstreamException;
use App\Services\Aio\Support\RateLimiter;
use App\Services\Aio\Support\ResponseCache;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AioHttpClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly ?string $tenantId,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly RateLimiter $limiter,
        private readonly ResponseCache $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = [], ?int $cacheTtl = null, bool $heavy = false): array
    {
        return $this->send('GET', $path, $query, [], $cacheTtl, $heavy);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function post(string $path, array $body = [], ?int $cacheTtl = null, bool $heavy = false): array
    {
        return $this->send('POST', $path, [], $body, $cacheTtl, $heavy);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    private function send(string $method, string $path, array $query, array $body, ?int $cacheTtl, bool $heavy): array
    {
        $fingerprint = $this->cache->fingerprint($method, $path, $query, $body);

        if ($cacheTtl !== 0) {
            $cached = $this->cache->get($fingerprint);
            if ($cached !== null) {
                return $cached;
            }
        }

        if ($heavy) {
            $this->limiter->assertHeavyBudget();
        }
        $this->limiter->acquireRpm();
        $this->limiter->acquireConcurrency();

        $startedMs = (int) (microtime(true) * 1000);

        try {
            $response = $this->request($method, $path, $query, $body);
            $durationMs = (int) (microtime(true) * 1000) - $startedMs;

            if ($heavy) {
                $this->limiter->recordHeavyDuration($durationMs);
            }

            if ($response->failed()) {
                throw new UpstreamException($response->status(), (string) $response->body());
            }

            $data = $response->json() ?? [];

            if ($cacheTtl !== 0 && $cacheTtl !== null) {
                $this->cache->put($fingerprint, $data, $cacheTtl);
            }

            return $data;
        } finally {
            $this->limiter->releaseConcurrency();
        }
    }

    private function request(string $method, string $path, array $query, array $body): Response
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $authedQuery = array_merge($query, ['token' => $this->token]);

        Log::debug('aio.request', [
            'method' => $method,
            'path' => $path,
            'query_keys' => array_keys($query),
            'has_body' => $body !== [],
        ]);

        $pending = $this->pending();

        return match ($method) {
            'GET' => $pending->get($url, $authedQuery),
            'POST' => $pending->post($url.'?'.http_build_query($authedQuery), $body),
            default => throw new InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    private function pending(): PendingRequest
    {
        $headers = ['Accept' => 'application/json'];

        if ($this->tenantId !== null && $this->tenantId !== '') {
            $headers['X-Tenant-Id'] = $this->tenantId;
        }

        return Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->acceptJson();
    }
}
