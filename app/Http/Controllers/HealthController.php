<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness/readiness probe for orchestrators.
 *
 * `ok=true` only if both Postgres and Redis respond. Every check is wrapped
 * so one slow dependency doesn't take down the endpoint — failures surface
 * in the per-component status string.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $components = [
            'database' => $this->probe(fn () => DB::connection()->getPdo()),
            'redis' => $this->probe(fn () => Redis::connection()->ping()),
        ];

        $ok = ! in_array(false, array_map(fn ($c) => $c['ok'], $components), true);

        return response()->json([
            'ok' => $ok,
            'components' => $components,
        ], $ok ? 200 : 503);
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function probe(callable $check): array
    {
        try {
            $check();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
