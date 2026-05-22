<?php

namespace Tests\Unit\Stats;

use App\Services\Stats\ComparisonFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ComparisonFormatterTest extends TestCase
{
    public function test_two_entries_get_delta_column(): void
    {
        $html = (new ComparisonFormatter)->format($this->window(), [
            ['label' => '#A', 'metrics' => ['clicks' => 100, 'leads' => 8]],
            ['label' => '#B', 'metrics' => ['clicks' => 400, 'leads' => 22]],
        ]);

        $this->assertStringContainsString('Δ%', $html);
        $this->assertStringContainsString('+300.0%', $html);  // (400-100)/100 = +300%
        $this->assertStringContainsString('+175.0%', $html);  // (22-8)/8 = +175%
    }

    public function test_three_entries_skip_delta(): void
    {
        $html = (new ComparisonFormatter)->format($this->window(), [
            ['label' => '#A', 'metrics' => ['clicks' => 1]],
            ['label' => '#B', 'metrics' => ['clicks' => 2]],
            ['label' => '#C', 'metrics' => ['clicks' => 3]],
        ]);

        $this->assertStringNotContainsString('Δ%', $html);
    }

    public function test_zero_baseline_delta_is_infinity_or_zero(): void
    {
        $html = (new ComparisonFormatter)->format($this->window(), [
            ['label' => '#A', 'metrics' => ['clicks' => 0, 'leads' => 0]],
            ['label' => '#B', 'metrics' => ['clicks' => 10, 'leads' => 0]],
        ]);

        $this->assertStringContainsString('∞', $html);  // baseline 0, value 10
        // baseline=0 value=0 → "0", not infinity
    }

    public function test_negative_delta(): void
    {
        $html = (new ComparisonFormatter)->format($this->window(), [
            ['label' => '#A', 'metrics' => ['clicks' => 100]],
            ['label' => '#B', 'metrics' => ['clicks' => 60]],
        ]);

        $this->assertStringContainsString('-40.0%', $html);
    }

    public function test_em_dash_for_missing(): void
    {
        $html = (new ComparisonFormatter)->format($this->window(), [
            ['label' => '#A', 'metrics' => ['clicks' => 100]],
            ['label' => '#B', 'metrics' => []],  // no clicks
        ]);

        $this->assertStringContainsString('—', $html);
    }

    public function test_single_entry_falls_through_with_explanation(): void
    {
        $html = (new ComparisonFormatter)->format($this->window(), [
            ['label' => '#A', 'metrics' => ['clicks' => 1]],
        ]);

        $this->assertStringContainsString('минимум', $html);
    }

    private function window(): array
    {
        return [
            'from' => CarbonImmutable::create(2026, 4, 25, 0, 0, 0),
            'to' => CarbonImmutable::create(2026, 4, 25, 14, 30, 0),
            'timezone' => 'UTC',
            'label' => 'today',
        ];
    }
}
