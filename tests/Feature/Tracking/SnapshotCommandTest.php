<?php

namespace Tests\Feature\Tracking;

use App\Jobs\NotifyLandingSnapshotJob;
use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Models\User;
use App\Models\UserLandingBinding;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Pivot\LandingReports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_captures_snapshots_and_fans_out_jobs_per_subscriber(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();

        $alice = User::factory()->telegram('1')->create();
        $bob = User::factory()->telegram('2')->create();
        $tracked = TrackedLanding::query()->create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
        ]);

        UserLandingBinding::create(['user_id' => $alice->id, 'tracked_landing_id' => $tracked->id, 'notify_3h' => true]);
        UserLandingBinding::create(['user_id' => $bob->id, 'tracked_landing_id' => $tracked->id, 'notify_3h' => true, 'notify_since_start' => true]);

        $this->artisan('tracking:snapshot', ['--kind' => 'both'])->assertSuccessful();

        // 2 snapshots captured (3h + since_start).
        $this->assertSame(2, LandingSnapshot::query()->count());

        // 3 notify jobs: 2 subscribers × 3h, plus 1 since_start (only Bob).
        Queue::assertPushed(NotifyLandingSnapshotJob::class, 3);
    }

    public function test_skips_paused_tracked_landings(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();

        TrackedLanding::query()->create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
            'paused_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->artisan('tracking:snapshot')->assertSuccessful();

        $this->assertSame(0, LandingSnapshot::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_no_notify_flag_skips_dispatch(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();
        $user = User::factory()->telegram('1')->create();
        $tracked = TrackedLanding::query()->create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
        ]);
        UserLandingBinding::create(['user_id' => $user->id, 'tracked_landing_id' => $tracked->id, 'notify_3h' => true]);

        $this->artisan('tracking:snapshot', ['--no-notify' => true])->assertSuccessful();

        $this->assertSame(2, LandingSnapshot::query()->count());
        Queue::assertNothingPushed();
    }

    private function stubLandingReports(): void
    {
        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('landingStats')->andReturn(new PivotResponse(
            rows: [['dimensions' => ['group_0' => 'lp-1'], 'metrics' => ['clicks-uuid' => 50]]],
            raw: [],
        ));
        $this->app->instance(LandingReports::class, $reports);
    }

    private function seedTargetMetrics(): void
    {
        foreach ([
            'clicks-uuid' => 'LP1 Clicks',
            'lp-ctr-uuid' => 'Q LP1 CTR',
            'leads-uuid' => 'Leads',
            'ftds-real-uuid' => 'FTDs',
            'real-cr-uuid' => 'LP1  CR%',
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
