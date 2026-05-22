<?php

namespace Tests\Feature\Tracking;

use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Tracking\LandingSnapshotter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LandingSnapshotterTest extends TestCase
{
    use RefreshDatabase;

    public function test_captures_3h_snapshot_with_projected_metrics(): void
    {
        $tracked = TrackedLanding::query()->create([
            'landing_uuid' => 'lp-uuid-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
        ]);

        // Stub AIO response with the raw uuid-keyed metrics that TargetMetricSet projects.
        $this->mockLandingReportsReturning([
            ['dimensions' => ['group_0' => 'lp-uuid-1'], 'metrics' => $this->makeRawMetrics()],
        ]);

        $now = CarbonImmutable::create(2026, 4, 25, 15, 0, 0);
        $snapshot = app(LandingSnapshotter::class)->capture($tracked, LandingSnapshot::KIND_3H, $now);

        $this->assertSame(LandingSnapshot::KIND_3H, $snapshot->kind);
        $this->assertSame(100, $snapshot->metrics['clicks']);
        $this->assertSame($tracked->id, $snapshot->tracked_landing_id);
        $this->assertTrue($snapshot->window_end->equalTo($now));
        $this->assertTrue($snapshot->window_start->equalTo($now->subHours(3)));
    }

    public function test_captures_since_start_snapshot(): void
    {
        $start = CarbonImmutable::create(2026, 4, 20, 10, 0, 0);
        $tracked = TrackedLanding::query()->create([
            'landing_uuid' => 'lp-uuid-1',
            'position' => 1,
            'tracking_started_at' => $start,
        ]);
        $this->mockLandingReportsReturning([
            ['dimensions' => ['group_0' => 'lp-uuid-1'], 'metrics' => $this->makeRawMetrics()],
        ]);

        $now = CarbonImmutable::create(2026, 4, 25, 15, 0, 0);
        $snapshot = app(LandingSnapshotter::class)->capture($tracked, LandingSnapshot::KIND_SINCE_START, $now);

        $this->assertSame(LandingSnapshot::KIND_SINCE_START, $snapshot->kind);
        $this->assertTrue($snapshot->window_start->equalTo($start));
    }

    public function test_capture_both_emits_two_snapshots(): void
    {
        $tracked = TrackedLanding::query()->create([
            'landing_uuid' => 'lp-uuid-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
        ]);
        $this->mockLandingReportsReturning([
            ['dimensions' => ['group_0' => 'lp-uuid-1'], 'metrics' => $this->makeRawMetrics()],
        ]);

        [$a, $b] = app(LandingSnapshotter::class)->captureBoth($tracked);

        $this->assertSame(LandingSnapshot::KIND_3H, $a->kind);
        $this->assertSame(LandingSnapshot::KIND_SINCE_START, $b->kind);
        $this->assertSame(2, LandingSnapshot::query()->count());
    }

    /** @param  list<array{dimensions: array<string,string>, metrics: array<string,mixed>}>  $rows */
    private function mockLandingReportsReturning(array $rows): void
    {
        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('landingStats')->andReturn(new PivotResponse(rows: $rows, raw: []));
        $this->app->instance(LandingReports::class, $reports);
    }

    /** Raw metric uuids matching the rows seeded by AioMetricsSeeder/TestSetup. */
    private function makeRawMetrics(): array
    {
        $this->seedTargetMetrics();

        return [
            'clicks-uuid' => 100,
            'lp-ctr-uuid' => 0.45,
            'leads-uuid' => 8,
            'ftds-real-uuid' => 2,
            'real-cr-uuid' => 0.25,
            'interest-rate-uuid' => 0.5,
            'scrolling-uuid' => 0.75,
        ];
    }

    private function seedTargetMetrics(): void
    {
        foreach ([
            'clicks-uuid' => 'Q Visits',
            'lp-ctr-uuid' => 'Q LP1 CTR',
            'leads-uuid' => 'Leads',
            'ftds-real-uuid' => 'Total FTDs',
            'real-cr-uuid' => 'Real Approve',
            'interest-rate-uuid' => 'LP1 Interest Rate',
            'scrolling-uuid' => 'Q LP1 Scroll Avg',
        ] as $uuid => $name) {
            \App\Models\Aio\Metric::query()->updateOrCreate(
                ['uuid' => $uuid],
                ['name' => $name, 'raw' => [], 'synced_at' => now()],
            );
        }
    }
}
