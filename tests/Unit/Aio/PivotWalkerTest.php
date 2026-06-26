<?php

namespace Tests\Unit\Aio;

use App\Services\Aio\Support\PivotWalker;
use PHPUnit\Framework\TestCase;

class PivotWalkerTest extends TestCase
{
    public function test_flattens_single_level_with_plain_keys(): void
    {
        $pivot = [
            'DK' => [
                'group_0' => 'DK',
                'uuid-a' => 42,
                'uuid-b' => 1.5,
            ],
            'US' => [
                'group_0' => 'US',
                'uuid-a' => 7,
            ],
        ];

        $rows = PivotWalker::flatten($pivot);

        $this->assertCount(2, $rows);
        $this->assertSame(['group_0' => 'DK'], $rows[0]['dimensions']);
        $this->assertSame(['uuid-a' => 42, 'uuid-b' => 1.5], $rows[0]['metrics']);
        $this->assertSame(['group_0' => 'US'], $rows[1]['dimensions']);
        $this->assertSame(['uuid-a' => 7], $rows[1]['metrics']);
    }

    public function test_flattens_two_levels_where_parent_echoes_empty_deeper_marker(): void
    {
        $pivot = [
            'lp-1' => [
                'group_0' => 'lp-1',
                'group_1' => '',
                'uuid-a' => 100,
                'KH' => [
                    'group_0' => 'lp-1',
                    'group_1' => 'KH',
                    'uuid-a' => 40,
                ],
                'SX' => [
                    'group_0' => 'lp-1',
                    'group_1' => 'SX',
                    'uuid-a' => 60,
                ],
            ],
        ];

        $rows = PivotWalker::flatten($pivot);

        $this->assertCount(3, $rows);
        $this->assertSame(['group_0' => 'lp-1', 'group_1' => ''], $rows[0]['dimensions']);
        $this->assertSame(['uuid-a' => 100], $rows[0]['metrics']);
        $this->assertSame(['group_0' => 'lp-1', 'group_1' => 'KH'], $rows[1]['dimensions']);
        $this->assertSame(['uuid-a' => 40], $rows[1]['metrics']);
        $this->assertSame(['group_0' => 'lp-1', 'group_1' => 'SX'], $rows[2]['dimensions']);
    }

    public function test_skips_nodes_without_metrics(): void
    {
        $pivot = [
            'wrapper' => [
                'DK' => [
                    'group_0' => 'DK',
                    'uuid-a' => 1,
                ],
            ],
        ];

        $rows = PivotWalker::flatten($pivot);

        $this->assertCount(1, $rows);
        $this->assertSame(['group_0' => 'DK'], $rows[0]['dimensions']);
    }

    public function test_recovers_dimension_from_map_key_when_group_marker_absent(): void
    {
        // Real AIO shape for a single-level campaign-scoped grouping: keyed by
        // the landing uuid, metrics inside, NO echoed group_0. The uuid must be
        // recovered as group_0 or ComparisonReporter can't map rows → landings.
        $pivot = [
            '400bc191-uuid' => ['status' => 'fine', 'metric-a' => 152, 'metric-b' => 8.9],
            'a88856a9-uuid' => ['status' => 'fine', 'metric-a' => 70],
        ];

        $rows = PivotWalker::flatten($pivot);

        $this->assertCount(2, $rows);
        $this->assertSame('400bc191-uuid', $rows[0]['dimensions']['group_0']);
        $this->assertSame(152, $rows[0]['metrics']['metric-a']);
        $this->assertSame('a88856a9-uuid', $rows[1]['dimensions']['group_0']);
    }

    public function test_empty_string_dimension_value_is_preserved(): void
    {
        $pivot = [
            '' => [
                'group_0' => '',
                'uuid-a' => 99,
            ],
        ];

        $rows = PivotWalker::flatten($pivot);

        $this->assertCount(1, $rows);
        $this->assertSame(['group_0' => ''], $rows[0]['dimensions']);
        $this->assertSame(['uuid-a' => 99], $rows[0]['metrics']);
    }
}
