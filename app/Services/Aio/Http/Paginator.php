<?php

namespace App\Services\Aio\Http;

use Generator;

/**
 * Walks AIO's `{rows, next, previous}` paginated endpoints.
 *
 * AIO paginates with `page` and `limit` query params; `next: true` means another
 * page exists. This iterator yields rows one page at a time until `next` is false.
 */
class Paginator
{
    /**
     * @param  callable(int $page, int $limit): array{rows: array<int, array<string, mixed>>, next?: bool}  $fetcher
     * @return Generator<int, array<string, mixed>>
     */
    public static function iterate(callable $fetcher, int $limit = 100, int $startPage = 1): Generator
    {
        $page = $startPage;

        while (true) {
            $response = $fetcher($page, $limit);
            $rows = $response['rows'] ?? [];

            foreach ($rows as $row) {
                yield $row;
            }

            if (empty($rows) || ! ($response['next'] ?? false)) {
                return;
            }

            $page++;
        }
    }

    /**
     * @param  callable(int $page, int $limit): array{rows: array<int, array<string, mixed>>, next?: bool}  $fetcher
     * @return array<int, array<string, mixed>>
     */
    public static function collect(callable $fetcher, int $limit = 100, ?int $maxPages = null): array
    {
        $all = [];
        $pages = 0;

        foreach (self::iterate($fetcher, $limit) as $row) {
            $all[] = $row;

            if ($maxPages !== null && (int) floor(count($all) / $limit) >= $maxPages) {
                break;
            }
            $pages++;
        }

        return $all;
    }
}
