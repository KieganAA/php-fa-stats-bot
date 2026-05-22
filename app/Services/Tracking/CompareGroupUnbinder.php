<?php

namespace App\Services\Tracking;

use App\Models\TrackedLanding;
use App\Models\UserCompareGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Drops a user's compare group. Side-effect: any tracked_landing that loses
 * its last subscribing membership gets paused, so the scheduler stops doing
 * AIO calls for landings nobody watches.
 */
final class CompareGroupUnbinder
{
    public function unbind(UserCompareGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $trackedIds = $group->members()->pluck('tracked_landing_id')->all();
            $group->delete();   // cascades members

            // Pause tracked landings nobody references anymore.
            foreach (TrackedLanding::query()->whereIn('id', $trackedIds)->get() as $tracked) {
                $still = $tracked->compareMemberships()->exists();
                if (! $still && $tracked->paused_at === null) {
                    $tracked->paused_at = CarbonImmutable::now();
                    $tracked->save();
                }
            }
        });
    }
}
