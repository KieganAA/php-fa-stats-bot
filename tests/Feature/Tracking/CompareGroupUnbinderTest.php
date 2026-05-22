<?php

namespace Tests\Feature\Tracking;

use App\Models\Aio\Landing;
use App\Models\TrackedLanding;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Tracking\CompareGroupBinder;
use App\Services\Tracking\CompareGroupUnbinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompareGroupUnbinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbinds_group_and_pauses_orphaned_tracked_landings(): void
    {
        $user = User::factory()->telegram('1')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);

        $group = app(CompareGroupBinder::class)->bind($user, [$a, $b]);
        app(CompareGroupUnbinder::class)->unbind($group);

        $this->assertSame(0, UserCompareGroup::query()->count());
        $this->assertSame(2, TrackedLanding::query()->whereNotNull('paused_at')->count());
    }

    public function test_keeps_tracked_landing_active_when_other_groups_reference_it(): void
    {
        $alice = User::factory()->telegram('1')->create();
        $bob = User::factory()->telegram('2')->create();
        $a = $this->seedLanding(1);
        $b = $this->seedLanding(2);

        $aliceGroup = app(CompareGroupBinder::class)->bind($alice, [$a, $b]);
        app(CompareGroupBinder::class)->bind($bob, [$a, $b]);

        app(CompareGroupUnbinder::class)->unbind($aliceGroup);

        $this->assertSame(0, TrackedLanding::query()->whereNotNull('paused_at')->count());
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
