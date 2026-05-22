<?php

namespace Tests\Feature\Tracking;

use App\Models\Aio\Landing;
use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Services\Tracking\LandingSnapshotFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingSnapshotFormatterTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_3h_snapshot_with_landing_name_and_window(): void
    {
        $tracked = $this->makeTracked('lp-1', 1, 'Blue LP');
        $snap = $this->snapshot($tracked, ['clicks' => 100, 'lp_ctr' => 0.45], '12:00', '15:00');

        $html = app(LandingSnapshotFormatter::class)->format($tracked, [
            'current' => $snap,
            'prior' => null,
            'delta' => [],
        ]);

        $this->assertStringContainsString('Blue LP', $html);
        $this->assertStringContainsString('[LP1]', $html);
        $this->assertStringContainsString('3h:', $html);
        $this->assertStringContainsString('clicks', $html);
        $this->assertStringContainsString('100', $html);
        $this->assertStringContainsString('0.45', $html);
    }

    public function test_renders_delta_pct_with_sign(): void
    {
        $tracked = $this->makeTracked('lp-1', 1, 'Blue LP');
        $current = $this->snapshot($tracked, ['clicks' => 120], '12:00', '15:00');
        $prior = $this->snapshot($tracked, ['clicks' => 100], '09:00', '12:00');

        $html = app(LandingSnapshotFormatter::class)->format($tracked, [
            'current' => $current,
            'prior' => $prior,
            'delta' => ['clicks' => ['abs' => 20, 'pct' => 0.2]],
        ]);

        $this->assertStringContainsString('Δ +20.0%', $html);
        $this->assertStringContainsString('prev 09:00–12:00', $html);
    }

    public function test_renders_em_dash_for_missing_metric(): void
    {
        $tracked = $this->makeTracked('lp-1', 1, 'X');
        $snap = $this->snapshot($tracked, ['clicks' => null], '12:00', '15:00');

        $html = app(LandingSnapshotFormatter::class)->format($tracked, [
            'current' => $snap,
            'prior' => null,
            'delta' => [],
        ]);

        $this->assertStringContainsString('—', $html);
    }

    public function test_falls_back_to_uuid_when_landing_relation_missing(): void
    {
        $tracked = TrackedLanding::query()->create([
            'landing_uuid' => 'orphan-uuid',
            'position' => 2,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
        ]);
        $snap = $this->snapshot($tracked, ['clicks' => 1], '12:00', '15:00');

        $html = app(LandingSnapshotFormatter::class)->format($tracked, [
            'current' => $snap,
            'prior' => null,
            'delta' => [],
        ]);

        $this->assertStringContainsString('orphan-uuid', $html);
        $this->assertStringContainsString('[LP2]', $html);
    }

    private function makeTracked(string $uuid, int $position, string $name): TrackedLanding
    {
        Landing::query()->create([
            'uuid' => $uuid,
            'human_id' => 1,
            'name' => $name,
            'raw' => [],
            'synced_at' => now(),
        ]);

        return TrackedLanding::query()->create([
            'landing_uuid' => $uuid,
            'position' => $position,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    private function snapshot(TrackedLanding $tracked, array $metrics, string $startHm, string $endHm): LandingSnapshot
    {
        return LandingSnapshot::create([
            'tracked_landing_id' => $tracked->id,
            'kind' => LandingSnapshot::KIND_3H,
            'window_start' => CarbonImmutable::createFromFormat('Y-m-d H:i', '2026-04-25 '.$startHm),
            'window_end' => CarbonImmutable::createFromFormat('Y-m-d H:i', '2026-04-25 '.$endHm),
            'metrics' => $metrics,
            'captured_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', '2026-04-25 '.$endHm),
        ]);
    }
}
