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
            ['label' => 'lp1', 'metrics' => ['clicks' => 10]],
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
            ['label' => 'lp1', 'metrics' => ['clicks' => 10]],
            ['label' => 'lp2', 'metrics' => ['clicks' => 20]],
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
                'clicks' => 100,
                'lp_ctr' => 0.4567,
                'leads' => 5,
                'ftds_real' => 1,
                'real_cr' => 0.20,
                'interest_rate' => 0.50,
                'scrolling' => 0.60,
            ]],
        ]);

        $this->assertStringContainsString('<b>lp1</b>', $html);
        $this->assertStringContainsString('clicks', $html);
        $this->assertStringContainsString('100', $html);
        $this->assertStringContainsString('LP CTR', $html);
        $this->assertStringContainsString('0.46', $html);
        $this->assertStringContainsString('FTDs', $html);
    }

    public function test_multi_entry_uses_table_layout_with_header_row(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'alpha', 'metrics' => ['clicks' => 100, 'leads' => 5]],
            ['label' => 'beta', 'metrics' => ['clicks' => 200, 'leads' => 11]],
        ]);

        $this->assertStringContainsString('alpha', $html);
        $this->assertStringContainsString('beta', $html);
        $this->assertStringContainsString('clicks', $html);
        $this->assertStringContainsString('100', $html);
        $this->assertStringContainsString('200', $html);
    }

    public function test_null_metrics_render_as_em_dash(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'lp1', 'metrics' => ['clicks' => 100, 'lp_ctr' => null]],
        ]);

        $this->assertStringContainsString('—', $html);
    }

    public function test_missing_metrics_render_as_em_dash_in_table(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'a', 'metrics' => ['clicks' => 100]],
            ['label' => 'b', 'metrics' => ['leads' => 4]],
        ]);

        $this->assertStringContainsString('—', $html);
    }

    public function test_rate_metrics_formatted_with_two_decimals(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'lp1', 'metrics' => ['lp_ctr' => 0.123456]],
        ]);

        $this->assertStringContainsString('0.12', $html);
    }

    public function test_html_special_characters_in_label_are_escaped(): void
    {
        $formatter = new StatsFormatter;
        $period = $this->period('today');

        $html = $formatter->format($period, [
            ['label' => 'evil <script>', 'metrics' => ['clicks' => 1]],
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
