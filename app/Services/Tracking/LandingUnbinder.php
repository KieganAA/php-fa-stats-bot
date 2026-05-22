<?php

namespace App\Services\Tracking;

use App\Models\TrackedLanding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Removes a user's binding to a tracked landing. If that user was the last
 * subscriber, we pause the tracked landing so the scheduler stops doing
 * AIO calls for a landing nobody's watching. We don't delete the
 * tracked_landing because its history (snapshots, slices) still has value
 * — if someone binds again later, history is intact.
 */
final class LandingUnbinder
{
    /** @return bool True if a binding actually existed and was removed. */
    public function unbind(User $user, TrackedLanding $tracked): bool
    {
        return DB::transaction(function () use ($user, $tracked): bool {
            $deleted = $tracked->bindings()
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted === 0) {
                return false;
            }

            $remaining = $tracked->bindings()->count();
            if ($remaining === 0 && $tracked->paused_at === null) {
                $tracked->paused_at = CarbonImmutable::now();
                $tracked->save();
            }

            return true;
        });
    }
}
