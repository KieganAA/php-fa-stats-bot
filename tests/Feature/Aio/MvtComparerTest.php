<?php

namespace Tests\Feature\Aio;

use App\Models\MvtSlice;
use App\Models\TrackedLanding;
use App\Services\Aio\Pivot\MvtComparer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvtComparerTest extends TestCase
{
    use RefreshDatabase;

    public function test_compares_against_prior_three_hour_slice_and_latest_since_start(): void
    {
        $tracked = TrackedLanding::create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::parse('2026-04-25 09:00:00'),
        ]);

        $prior = $this->makeSlice($tracked, MvtSlice::KIND_3H, '2026-04-25 09:00:00', '2026-04-25 12:00:00', [
            ['dimensions' => ['x' => 'A'], 'metrics' => ['Q Visits' => 80, 'Leads' => 4]],
        ]);
        $sinceStart = $this->makeSlice($tracked, MvtSlice::KIND_SINCE_START, '2026-04-25 09:00:00', '2026-04-25 12:00:00', [
            ['dimensions' => ['x' => 'A'], 'metrics' => ['Q Visits' => 80, 'Leads' => 4]],
        ]);
        $current = $this->makeSlice($tracked, MvtSlice::KIND_3H, '2026-04-25 12:00:00', '2026-04-25 15:00:00', [
            ['dimensions' => ['x' => 'A'], 'metrics' => ['Q Visits' => 100, 'Leads' => 5]],
            ['dimensions' => ['x' => 'B'], 'metrics' => ['Q Visits' => 50, 'Leads' => 2]],
        ]);

        $result = $this->app->make(MvtComparer::class)->compare($current);

        $this->assertSame($current->id, $result['current']->id);
        $this->assertSame($prior->id, $result['prior']->id);
        $this->assertSame($sinceStart->id, $result['since_start']->id);

        $this->assertCount(2, $result['rows']);
        $rowA = $result['rows'][0];
        $this->assertSame(['x' => 'A'], $rowA['dimensions']);
        $this->assertSame(['Q Visits' => 100, 'Leads' => 5], $rowA['current']);
        $this->assertSame(['Q Visits' => 80, 'Leads' => 4], $rowA['prior']);
        $this->assertSame(20, $rowA['delta_prior']['Q Visits']['abs']);
        $this->assertEqualsWithDelta(0.25, $rowA['delta_prior']['Q Visits']['pct'], 1e-9);

        $rowB = $result['rows'][1];
        $this->assertNull($rowB['prior']);
        $this->assertNull($rowB['delta_prior']);
    }

    public function test_returns_null_for_prior_when_no_earlier_slice_exists(): void
    {
        $tracked = TrackedLanding::create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::parse('2026-04-25 09:00:00'),
        ]);

        $current = $this->makeSlice($tracked, MvtSlice::KIND_3H, '2026-04-25 12:00:00', '2026-04-25 15:00:00', [
            ['dimensions' => ['x' => 'A'], 'metrics' => ['Q Visits' => 100]],
        ]);

        $result = $this->app->make(MvtComparer::class)->compare($current);

        $this->assertNull($result['prior']);
        $this->assertNull($result['since_start']);
        $this->assertNull($result['rows'][0]['delta_prior']);
    }

    public function test_handles_zero_baseline_with_null_pct_but_keeps_abs(): void
    {
        $tracked = TrackedLanding::create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::parse('2026-04-25 09:00:00'),
        ]);

        $this->makeSlice($tracked, MvtSlice::KIND_3H, '2026-04-25 09:00:00', '2026-04-25 12:00:00', [
            ['dimensions' => ['x' => 'A'], 'metrics' => ['Q Visits' => 0]],
        ]);
        $current = $this->makeSlice($tracked, MvtSlice::KIND_3H, '2026-04-25 12:00:00', '2026-04-25 15:00:00', [
            ['dimensions' => ['x' => 'A'], 'metrics' => ['Q Visits' => 5]],
        ]);

        $result = $this->app->make(MvtComparer::class)->compare($current);

        $this->assertSame(5, $result['rows'][0]['delta_prior']['Q Visits']['abs']);
        $this->assertNull($result['rows'][0]['delta_prior']['Q Visits']['pct']);
    }

    public function test_dimension_key_is_order_independent(): void
    {
        $tracked = TrackedLanding::create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::parse('2026-04-25 09:00:00'),
        ]);

        $this->makeSlice($tracked, MvtSlice::KIND_3H, '2026-04-25 09:00:00', '2026-04-25 12:00:00', [
            ['dimensions' => ['a' => 'x', 'b' => 'y'], 'metrics' => ['Q Visits' => 10]],
        ]);
        $current = $this->makeSlice($tracked, MvtSlice::KIND_3H, '2026-04-25 12:00:00', '2026-04-25 15:00:00', [
            ['dimensions' => ['b' => 'y', 'a' => 'x'], 'metrics' => ['Q Visits' => 20]],
        ]);

        $result = $this->app->make(MvtComparer::class)->compare($current);

        $this->assertSame(10, $result['rows'][0]['delta_prior']['Q Visits']['abs']);
    }

    private function makeSlice(TrackedLanding $tracked, string $kind, string $start, string $end, array $rows): MvtSlice
    {
        return MvtSlice::create([
            'tracked_landing_id' => $tracked->id,
            'kind' => $kind,
            'window_start' => CarbonImmutable::parse($start),
            'window_end' => CarbonImmutable::parse($end),
            'rows' => $rows,
            'captured_at' => CarbonImmutable::parse($end),
        ]);
    }
}
