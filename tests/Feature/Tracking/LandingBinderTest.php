<?php

namespace Tests\Feature\Tracking;

use App\Models\TrackedLanding;
use App\Models\User;
use App\Services\Tracking\LandingBinder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingBinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_tracked_landing_and_binding(): void
    {
        $user = User::factory()->telegram('111')->create();

        $binding = app(LandingBinder::class)->bind($user, 'lp-uuid-1', 1);

        $this->assertDatabaseCount('tracked_landings', 1);
        $this->assertDatabaseHas('user_landing_bindings', [
            'user_id' => $user->id,
            'tracked_landing_id' => $binding->tracked_landing_id,
            'notify_3h' => true,
            'notify_since_start' => false,
        ]);
    }

    public function test_reuses_existing_tracked_landing_for_second_user(): void
    {
        $alice = User::factory()->telegram('1')->create();
        $bob = User::factory()->telegram('2')->create();

        app(LandingBinder::class)->bind($alice, 'shared-lp', 2);
        app(LandingBinder::class)->bind($bob, 'shared-lp', 2);

        $this->assertDatabaseCount('tracked_landings', 1);
        $this->assertDatabaseCount('user_landing_bindings', 2);
    }

    public function test_resumes_paused_tracked_landing_when_someone_rebinds(): void
    {
        TrackedLanding::query()->create([
            'landing_uuid' => 'lp-uuid-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::now()->subDay(),
            'paused_at' => CarbonImmutable::now()->subHour(),
        ]);
        $user = User::factory()->telegram('3')->create();

        app(LandingBinder::class)->bind($user, 'lp-uuid-1', 1);

        $this->assertNull(TrackedLanding::query()->first()->paused_at);
    }

    public function test_updates_existing_binding_in_place(): void
    {
        $user = User::factory()->telegram('4')->create();
        app(LandingBinder::class)->bind($user, 'lp-1', 1, notify3h: false, notifySinceStart: true);

        $binding = app(LandingBinder::class)->bind($user, 'lp-1', 1, notify3h: true, notes: 'updated');

        $this->assertDatabaseCount('user_landing_bindings', 1);
        $this->assertTrue($binding->notify_3h);
        $this->assertSame('updated', $binding->notes);
    }
}
