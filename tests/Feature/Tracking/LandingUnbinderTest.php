<?php

namespace Tests\Feature\Tracking;

use App\Models\TrackedLanding;
use App\Models\User;
use App\Models\UserLandingBinding;
use App\Services\Tracking\LandingBinder;
use App\Services\Tracking\LandingUnbinder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingUnbinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_a_binding(): void
    {
        $user = User::factory()->telegram('1')->create();
        $binding = app(LandingBinder::class)->bind($user, 'lp-1', 1);
        $tracked = $binding->trackedLanding;

        $ok = app(LandingUnbinder::class)->unbind($user, $tracked);

        $this->assertTrue($ok);
        $this->assertSame(0, UserLandingBinding::query()->count());
    }

    public function test_pauses_tracked_landing_when_last_subscriber_leaves(): void
    {
        $user = User::factory()->telegram('1')->create();
        $binding = app(LandingBinder::class)->bind($user, 'lp-1', 1);

        app(LandingUnbinder::class)->unbind($user, $binding->trackedLanding);

        $this->assertNotNull(TrackedLanding::query()->first()->paused_at);
    }

    public function test_keeps_tracked_landing_active_when_others_still_subscribe(): void
    {
        $alice = User::factory()->telegram('1')->create();
        $bob = User::factory()->telegram('2')->create();
        $aliceBinding = app(LandingBinder::class)->bind($alice, 'lp-1', 1);
        app(LandingBinder::class)->bind($bob, 'lp-1', 1);

        app(LandingUnbinder::class)->unbind($alice, $aliceBinding->trackedLanding);

        $this->assertNull(TrackedLanding::query()->first()->paused_at);
        $this->assertSame(1, UserLandingBinding::query()->count());
    }

    public function test_returns_false_when_no_binding_existed(): void
    {
        $user = User::factory()->telegram('1')->create();
        $tracked = TrackedLanding::query()->create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::now(),
        ]);

        $ok = app(LandingUnbinder::class)->unbind($user, $tracked);

        $this->assertFalse($ok);
        $this->assertNull($tracked->fresh()->paused_at, 'do not pause a tracked landing the user never had');
    }
}
