<?php

namespace Tests\Feature\Tracking;

use App\Jobs\NotifyCompareGroupJob;
use App\Models\Aio\Landing;
use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Models\User;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Tracking\CompareGroupBinder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_captures_snapshots_for_active_landings_and_fans_out_groups(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();

        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);

        app(CompareGroupBinder::class)->bind($user, [$a, $b], name: 'pair');

        $this->artisan('tracking:snapshot')->assertSuccessful();

        $this->assertSame(2, LandingSnapshot::query()->count(), 'one snapshot per tracked landing');
        Queue::assertPushed(NotifyCompareGroupJob::class, 1);
    }

    public function test_skips_paused_tracked_landings_and_groups(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();

        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);
        $group = app(CompareGroupBinder::class)->bind($user, [$a, $b]);

        // Pause the group + tracked_landings
        $group->paused_at = CarbonImmutable::now();
        $group->save();
        TrackedLanding::query()->update(['paused_at' => CarbonImmutable::now()]);

        $this->artisan('tracking:snapshot')->assertSuccessful();

        $this->assertSame(0, LandingSnapshot::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_dispatches_solo_groups_in_mvt_mode(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();

        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        // A solo group — auto-tagged as mvt mode by the binder.
        app(CompareGroupBinder::class)->bind($user, [$a], name: 'solo');

        $this->artisan('tracking:snapshot')->assertSuccessful();

        $this->assertSame(1, LandingSnapshot::query()->count());
        Queue::assertPushed(NotifyCompareGroupJob::class, 1);
    }

    public function test_fan_out_respects_per_group_interval(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();

        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);

        // 1h interval, last pushed 30 min ago → NOT due.
        $group = app(CompareGroupBinder::class)->bind($user, [$a, $b], name: 'g1', notifyIntervalMinutes: 60);
        $group->last_notified_at = CarbonImmutable::now()->subMinutes(30);
        $group->save();

        $this->artisan('tracking:snapshot', ['--no-capture' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_fan_out_fires_when_interval_elapsed(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();

        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);

        // 1h interval, last pushed 90 min ago → due.
        $group = app(CompareGroupBinder::class)->bind($user, [$a, $b], name: 'g1', notifyIntervalMinutes: 60);
        $group->last_notified_at = CarbonImmutable::now()->subMinutes(90);
        $group->save();

        $this->artisan('tracking:snapshot', ['--no-capture' => true])->assertSuccessful();

        Queue::assertPushed(NotifyCompareGroupJob::class, 1);
    }

    public function test_no_notify_flag_skips_dispatch(): void
    {
        Queue::fake();
        $this->seedTargetMetrics();
        $this->stubLandingReports();

        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);
        app(CompareGroupBinder::class)->bind($user, [$a, $b]);

        $this->artisan('tracking:snapshot', ['--no-notify' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    private function stubLandingReports(): void
    {
        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('landingStats')->andReturn(new PivotResponse(
            rows: [['dimensions' => ['group_0' => 'lp-1'], 'metrics' => ['clicks-uuid' => 10]]],
            raw: [],
        ));
        $this->app->instance(LandingReports::class, $reports);
    }

    private function seedLanding(int $humanId): Landing
    {
        return Landing::query()->create([
            'uuid' => "uuid-{$humanId}",
            'human_id' => $humanId,
            'name' => "L{$humanId}",
            'landing_type_uuid' => 'lt',
            'landing_type_name' => 'White 2.0',
            'owner_uuid' => 'o',
            'owner_name' => 'owner',
            'countries' => ['IT'],
            'is_archived' => false,
            'raw' => [],
            'synced_at' => now(),
        ]);
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
