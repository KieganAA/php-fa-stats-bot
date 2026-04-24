<?php

namespace Tests\Unit\Aio;

use App\Services\Aio\Http\Paginator;
use PHPUnit\Framework\TestCase;

class PaginatorTest extends TestCase
{
    public function test_stops_when_next_is_false(): void
    {
        $calls = [];
        $fetcher = function (int $page, int $limit) use (&$calls): array {
            $calls[] = [$page, $limit];

            return match ($page) {
                1 => ['rows' => [['id' => 1], ['id' => 2]], 'next' => true],
                2 => ['rows' => [['id' => 3]], 'next' => false],
                default => ['rows' => [], 'next' => false],
            };
        };

        $rows = iterator_to_array(Paginator::iterate($fetcher, limit: 2), false);

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $rows);
        $this->assertSame([[1, 2], [2, 2]], $calls);
    }

    public function test_stops_on_empty_page_even_if_next_true(): void
    {
        $fetcher = fn (int $page) => ['rows' => [], 'next' => true];

        $rows = iterator_to_array(Paginator::iterate($fetcher), false);

        $this->assertSame([], $rows);
    }

    public function test_handles_missing_next_key_as_last_page(): void
    {
        $fetcher = fn () => ['rows' => [['a' => 1]]];

        $rows = iterator_to_array(Paginator::iterate($fetcher), false);

        $this->assertSame([['a' => 1]], $rows);
    }
}
