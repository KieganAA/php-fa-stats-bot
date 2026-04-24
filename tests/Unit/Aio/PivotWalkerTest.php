<?php

namespace Tests\Unit\Aio;

use App\Services\Aio\Support\PivotWalker;
use PHPUnit\Framework\TestCase;

class PivotWalkerTest extends TestCase
{
    public function test_flattens_single_level(): void
    {
        $pivot = [
            '{"group_1":"DK"}' => [
                'placeholders' => ['metric_uuid_a' => 42, 'metric_uuid_b' => 1.5],
            ],
            '{"group_1":"US"}' => [
                'placeholders' => ['metric_uuid_a' => 7],
            ],
        ];

        $rows = PivotWalker::flatten($pivot);

        $this->assertCount(2, $rows);
        $this->assertSame(['group_1' => 'DK'], $rows[0]['dimensions']);
        $this->assertSame(['metric_uuid_a' => 42, 'metric_uuid_b' => 1.5], $rows[0]['metrics']);
        $this->assertSame(['group_1' => 'US'], $rows[1]['dimensions']);
    }

    public function test_flattens_nested_levels(): void
    {
        $pivot = [
            '{"group_1":"DK"}' => [
                '{"group_2":"camp-uuid-1"}' => [
                    'placeholders' => ['m' => 10],
                ],
                '{"group_2":"camp-uuid-2"}' => [
                    'placeholders' => ['m' => 20],
                ],
            ],
        ];

        $rows = PivotWalker::flatten($pivot);

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['group_1' => 'DK', 'group_2' => 'camp-uuid-1'],
            $rows[0]['dimensions'],
        );
        $this->assertSame(['m' => 10], $rows[0]['metrics']);
    }

    public function test_ignores_non_group_keys(): void
    {
        $pivot = [
            'filters' => ['unused'],
            'meta' => ['anything'],
            '{"g":"x"}' => ['placeholders' => ['m' => 1]],
        ];

        $rows = PivotWalker::flatten($pivot);

        $this->assertCount(1, $rows);
        $this->assertSame(['g' => 'x'], $rows[0]['dimensions']);
    }
}
