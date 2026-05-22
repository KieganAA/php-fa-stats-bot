<?php

namespace Tests\Feature\Aio;

use App\Models\Aio\Landing;
use App\Models\MvtSlice;
use App\Models\TrackedLanding;
use App\Services\Aio\Pivot\MvtReportFormatter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MvtReportFormatterTest extends TestCase
{
    public function test_renders_title_with_landing_name_and_position(): void
    {
        $formatter = new MvtReportFormatter;
        $landing = $this->makeTracked('Cool Lander <bro>', 2);

        $current = $this->makeSlice('2026-04-25 12:00:00', '2026-04-25 15:00:00', []);
        $comparison = [
            'current' => $current,
            'prior' => null,
            'since_start' => null,
            'rows' => [],
        ];

        $html = $formatter->format($landing, $comparison);

        $this->assertStringContainsString('Cool Lander &lt;bro&gt;', $html);
        $this->assertStringContainsString('[pos 2]', $html);
        $this->assertStringContainsString('25.04 12:00', $html);
        $this->assertStringContainsString('—', $html); // prior: —
    }

    public function test_renders_metric_row_with_deltas(): void
    {
        $formatter = new MvtReportFormatter;
        $landing = $this->makeTracked('LP', 1);

        $current = $this->makeSlice('2026-04-25 12:00:00', '2026-04-25 15:00:00', []);
        $row = [
            'dimensions' => ['lp_landing_header' => 'Variant A'],
            'current' => ['Q Visits' => 100, 'Q LP1 CTR' => 0.45, 'Leads' => 5, 'Total FTDs' => 1, 'Real Approve' => 1.0, 'LP1 Interest Rate' => 0.5, 'Q LP1 Scroll Avg' => 0.6],
            'prior' => ['Q Visits' => 80],
            'since_start' => ['Q Visits' => 1000],
            'delta_prior' => ['Q Visits' => ['abs' => 20, 'pct' => 0.25]],
            'delta_since_start' => ['Q Visits' => ['abs' => -900, 'pct' => -0.9]],
        ];

        $html = $formatter->format($landing, [
            'current' => $current,
            'prior' => null,
            'since_start' => null,
            'rows' => [$row],
        ]);

        $this->assertStringContainsString('lp_landing_header', $html);
        $this->assertStringContainsString('Variant A', $html);
        $this->assertStringContainsString('clicks', $html);
        $this->assertStringContainsString('+25.0%', $html);
        $this->assertStringContainsString('-90.0%', $html);
    }

    public function test_skips_all_zero_rows(): void
    {
        $formatter = new MvtReportFormatter;
        $landing = $this->makeTracked('LP', 1);
        $current = $this->makeSlice('2026-04-25 12:00:00', '2026-04-25 15:00:00', []);

        $html = $formatter->format($landing, [
            'current' => $current,
            'prior' => null,
            'since_start' => null,
            'rows' => [
                [
                    'dimensions' => ['x' => 'A'],
                    'current' => ['Q Visits' => 0, 'Q LP1 CTR' => null, 'Leads' => 0],
                    'prior' => null,
                    'since_start' => null,
                    'delta_prior' => null,
                    'delta_since_start' => null,
                ],
            ],
        ]);

        $this->assertStringNotContainsString('Variant A', $html);
        $this->assertStringContainsString('нет данных', $html);
    }

    public function test_renders_empty_dimension_value_as_aggregate_marker(): void
    {
        $formatter = new MvtReportFormatter;
        $landing = $this->makeTracked('LP', 1);
        $current = $this->makeSlice('2026-04-25 12:00:00', '2026-04-25 15:00:00', []);

        $html = $formatter->format($landing, [
            'current' => $current,
            'prior' => null,
            'since_start' => null,
            'rows' => [[
                'dimensions' => ['lp_header' => '', 'lp_game' => 'Roulette'],
                'current' => ['Q Visits' => 50],
                'prior' => null,
                'since_start' => null,
                'delta_prior' => null,
                'delta_since_start' => null,
            ]],
        ]);

        $this->assertStringContainsString('∑ all', $html);
        $this->assertStringContainsString('Roulette', $html);
    }

    private function makeTracked(string $name, int $position): TrackedLanding
    {
        $landingModel = (new Landing)->forceFill(['name' => $name, 'uuid' => 'lp-1']);
        $tracked = (new TrackedLanding)->forceFill(['landing_uuid' => 'lp-1', 'position' => $position]);
        $tracked->setRelation('landing', $landingModel);

        return $tracked;
    }

    private function makeSlice(string $start, string $end, array $rows): MvtSlice
    {
        return (new MvtSlice)->forceFill([
            'window_start' => CarbonImmutable::parse($start),
            'window_end' => CarbonImmutable::parse($end),
            'rows' => $rows,
            'kind' => MvtSlice::KIND_3H,
        ]);
    }
}
