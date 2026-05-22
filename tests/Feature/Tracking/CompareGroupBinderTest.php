<?php

namespace Tests\Feature\Tracking;

use App\Models\Aio\Landing;
use App\Models\TrackedLanding;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Tracking\CompareGroupBinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompareGroupBinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_group_with_auto_name_and_tracked_landings(): void
    {
        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(33169);
        $b = $this->seedLanding(205215);

        $group = app(CompareGroupBinder::class)->bind($user, [$a, $b]);

        $this->assertSame('g1', $group->name);
        $this->assertSame($user->id, $group->user_id);
        $this->assertSame(2, $group->members()->count());
        $this->assertSame(2, TrackedLanding::query()->count());
    }

    public function test_auto_names_skip_used_indices(): void
    {
        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);

        UserCompareGroup::create(['user_id' => $user->id, 'name' => 'g1']);
        UserCompareGroup::create(['user_id' => $user->id, 'name' => 'g3']);

        $group = app(CompareGroupBinder::class)->bind($user, [$a, $b]);

        $this->assertSame('g2', $group->name);
    }

    public function test_named_group_replaces_members_on_rebind(): void
    {
        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);
        $c = $this->seedLanding(3);

        app(CompareGroupBinder::class)->bind($user, [$a, $b], name: 'test');
        $group = app(CompareGroupBinder::class)->bind($user, [$a, $c], name: 'test');

        $this->assertSame('test', $group->name);
        $this->assertSame(1, UserCompareGroup::query()->count());
        $this->assertSame(2, $group->members()->count());
        $memberLandingIds = $group->members->pluck('tracked_landing_id')->all();
        $this->assertCount(2, $memberLandingIds);
    }

    public function test_shares_tracked_landings_across_users(): void
    {
        $alice = User::factory()->telegram('1')->create();
        $bob = User::factory()->telegram('2')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);

        app(CompareGroupBinder::class)->bind($alice, [$a, $b]);
        app(CompareGroupBinder::class)->bind($bob, [$a, $b]);

        $this->assertSame(2, TrackedLanding::query()->count(), 'tracked_landings should be deduped');
        $this->assertSame(2, UserCompareGroup::query()->count());
    }

    public function test_rebind_resumes_paused_tracked_landing(): void
    {
        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);

        $tracked = TrackedLanding::create([
            'landing_uuid' => $a->uuid,
            'position' => 1,
            'tracking_started_at' => now()->subDay(),
            'paused_at' => now(),
        ]);

        app(CompareGroupBinder::class)->bind($user, [$a]);

        $this->assertNull($tracked->fresh()->paused_at);
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
}
