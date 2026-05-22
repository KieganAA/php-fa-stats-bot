<?php

namespace Tests\Feature\Tracking;

use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Services\Tracking\LandingSnapshotComparer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingSnapshotComparerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_delta_when_no_prior_snapshot(): void
    {
        $tracked = $this->makeTracked();
        $snapshot = $this->snapshot($tracked, ['clicks' => 100], hoursAgo: 0);

        $result = app(LandingSnapshotComparer::class)->compare($snapshot);

        $this->assertNull($result['prior']);
        $this->assertSame([], $result['delta']);
    }

    public function test_computes_abs_and_pct_against_immediately_prior_3h_window(): void
    {
        $tracked = $this->makeTracked();
        $this->snapshot($tracked, ['clicks' => 80, 'leads' => 5], hoursAgo: 6);
        $current = $this->snapshot($tracked, ['clicks' => 100, 'leads' => 10], hoursAgo: 0);

        $result = app(LandingSnapshotComparer::class)->compare($current);

        $this->assertNotNull($result['prior']);
        $this->assertSame(20, $result['delta']['clicks']['abs']);
        $this->assertEqualsWithDelta(0.25, $result['delta']['clicks']['pct'], 0.001);
        $this->assertSame(5, $result['delta']['leads']['abs']);
        $this->assertEqualsWithDelta(1.0, $result['delta']['leads']['pct'], 0.001);
    }

    public function test_pct_is_null_when_baseline_is_zero(): void
    {
        $tracked = $this->makeTracked();
        $this->snapshot($tracked, ['clicks' => 0], hoursAgo: 6);
        $current = $this->snapshot($tracked, ['clicks' => 50], hoursAgo: 0);

        $result = app(LandingSnapshotComparer::class)->compare($current);

        $this->assertSame(50, $result['delta']['clicks']['abs']);
        $this->assertNull($result['delta']['clicks']['pct']);
    }

    public function test_null_current_or_prior_emits_null_delta(): void
    {
        $tracked = $this->makeTracked();
        $this->snapshot($tracked, ['clicks' => null], hoursAgo: 6);
        $current = $this->snapshot($tracked, ['clicks' => 30], hoursAgo: 0);

        $result = app(LandingSnapshotComparer::class)->compare($current);

        $this->assertNull($result['delta']['clicks']['abs']);
        $this->assertNull($result['delta']['clicks']['pct']);
    }

    private function makeTracked(): TrackedLanding
    {
        return TrackedLanding::query()->create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    private function snapshot(TrackedLanding $tracked, array $metrics, int $hoursAgo): LandingSnapshot
    {
        $now = CarbonImmutable::now()->subHours($hoursAgo);

        return LandingSnapshot::create([
            'tracked_landing_id' => $tracked->id,
            'kind' => LandingSnapshot::KIND_3H,
            'window_start' => $now->subHours(3),
            'window_end' => $now,
            'metrics' => $metrics,
            'captured_at' => $now,
        ]);
    }
}
