<?php

namespace App\Services\Tracking;

use App\Models\TrackedLanding;
use App\Models\User;
use App\Models\UserLandingBinding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Binds a user to a (landing_uuid, position) pair so the scheduler will:
 *   - capture snapshots for it every 3 hours
 *   - deliver them to the user via Telegram (toggle on the binding)
 *
 * The tracked_landing row is shared — if Alice and Bob both bind landing X
 * we still only run one AIO query per cycle.
 */
final class LandingBinder
{
    public function bind(
        User $user,
        string $landingUuid,
        int $position,
        bool $notify3h = true,
        bool $notifySinceStart = false,
        ?string $notes = null,
    ): UserLandingBinding {
        return DB::transaction(function () use ($user, $landingUuid, $position, $notify3h, $notifySinceStart, $notes): UserLandingBinding {
            $tracked = TrackedLanding::query()->firstOrCreate(
                ['landing_uuid' => $landingUuid, 'position' => $position],
                [
                    'tracking_started_at' => CarbonImmutable::now(),
                    'paused_at' => null,
                ],
            );

            // If it had been paused, resume — somebody just expressed interest.
            if ($tracked->paused_at !== null) {
                $tracked->paused_at = null;
                $tracked->save();
            }

            return UserLandingBinding::query()->updateOrCreate(
                ['user_id' => $user->id, 'tracked_landing_id' => $tracked->id],
                [
                    'notify_3h' => $notify3h,
                    'notify_since_start' => $notifySinceStart,
                    'notes' => $notes,
                ],
            );
        });
    }
}
