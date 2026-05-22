<?php

namespace Tests\Unit\Stats;

use App\Services\Stats\StatsFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class StatsFormatterTest extends TestCase
{
    public function test_header_includes_label_timezone_and_window(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'lp1', 'metrics' => ['Q Visits' => 10]],
        ]);

        $this->assertStringContainsString('📊 stats', $html);
        $this->assertStringContainsString('today', $html);
        $this->assertStringContainsString('UTC', $html);
        $this->assertStringContainsString('25.04 00:00..25.04 14:30', $html);
    }

    public function test_compare_header_for_multiple_entries(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('7d');

        $html = $formatter->format($period, [
            ['label' => 'lp1', 'metrics' => ['Q Visits' => 10]],
            ['label' => 'lp2', 'metrics' => ['Q Visits' => 20]],
        ]);

        $this->assertStringContainsString('📊 compare', $html);
    }

    public function test_empty_entries_renders_no_data_notice(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, []);

        $this->assertStringContainsString('Нет данных', $html);
    }

    public function test_single_entry_uses_block_layout(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'lp1', 'metrics' => [
                'Q Visits' => 100,
                'Q LP1 CTR' => 0.4567,
                'Leads' => 5,
                'Total FTDs' => 1,
                'Real Approve' => 0.20,
                'LP1 Interest Rate' => 0.50,
                'Q LP1 Scroll Avg' => 0.60,
            ]],
        ]);

        $this->assertStringContainsString('<b>lp1</b>', $html);
        // Display uses MetricDisplay labels — "clicks" for "Q Visits" metric.
        $this->assertStringContainsString('clicks', $html);
        $this->assertStringContainsString('100', $html);
        $this->assertStringContainsString('LP CTR', $html);
        // 0.4567 ratio → "45.67%"
        $this->assertStringContainsString('45.67%', $html);
        $this->assertStringContainsString('FTDs', $html);
    }

    public function test_multi_entry_uses_table_layout_with_header_row(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'alpha', 'metrics' => ['Q Visits' => 100, 'Leads' => 5]],
            ['label' => 'beta', 'metrics' => ['Q Visits' => 200, 'Leads' => 11]],
        ]);

        $this->assertStringContainsString('alpha', $html);
        $this->assertStringContainsString('beta', $html);
        $this->assertStringContainsString('clicks', $html);  // "Q Visits" → "clicks" label
        $this->assertStringContainsString('100', $html);
        $this->assertStringContainsString('200', $html);
    }

    public function test_null_metrics_render_as_em_dash(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'lp1', 'metrics' => ['Q Visits' => 100, 'Q LP1 CTR' => null]],
        ]);

        $this->assertStringContainsString('—', $html);
    }

    public function test_missing_metrics_render_as_em_dash_in_table(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'a', 'metrics' => ['Q Visits' => 100]],
            ['label' => 'b', 'metrics' => ['Leads' => 4]],
        ]);

        $this->assertStringContainsString('—', $html);
    }

    public function test_rate_metrics_rendered_as_percent(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'lp1', 'metrics' => ['Q LP1 CTR' => 0.123456]],
        ]);

        // Ratio metric: 0.123456 → 12.35%
        $this->assertStringContainsString('12.35%', $html);
    }

    public function test_html_special_characters_in_label_are_escaped(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'evil <script>', 'metrics' => ['Q Visits' => 1]],
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, timezone: string, label: string} */
    private function period(string $label): array
    {
        return [
            'from' => CarbonImmutable::parse('2026-04-25 00:00:00', 'UTC'),
            'to' => CarbonImmutable::parse('2026-04-25 14:30:00', 'UTC'),
            'timezone' => 'UTC',
            'label' => $label,
        ];
    }
}
