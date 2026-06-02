<?php

namespace App\Services\Tracking;

use App\Models\Aio\Landing;
use App\Models\TrackedLanding;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Models\UserCompareGroupLanding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists a "compare group" — a user's chosen set of landings that get a
 * side-by-side report every 3 hours.
 *
 * Shape: each member landing has a corresponding tracked_landings row
 * (shared with other users' groups), so the AIO snapshot cost stays
 * deduplicated. The group itself is per-user.
 */
final class CompareGroupBinder
{
    /**
     * @param  list<Landing>  $landings  resolved by the caller (already verified)
     * @param  int|null  $notifyIntervalMinutes  null = leave existing / use default
     */
    public function bind(
        User $user,
        array $landings,
        ?string $name = null,
        int $position = 1,
        ?int $notifyIntervalMinutes = null,
    ): UserCompareGroup {
        if (count($landings) < 1) {
            throw new RuntimeException('Нужен хотя бы один лендинг.');
        }

        if ($notifyIntervalMinutes !== null) {
            $notifyIntervalMinutes = max(
                UserCompareGroup::INTERVAL_MIN,
                min(UserCompareGroup::INTERVAL_MAX, $notifyIntervalMinutes),
            );
        }

        // Mode policy: 1 landing → MVT (variant breakdown is the only thing
        // worth periodic-pulsing for a solo lander). 2+ → compare (side-by-side
        // is the only thing that makes sense for two creatives).
        $mode = count($landings) === 1
            ? UserCompareGroup::MODE_MVT
            : UserCompareGroup::MODE_COMPARE;

        return DB::transaction(function () use ($user, $landings, $name, $position, $mode, $notifyIntervalMinutes): UserCompareGroup {
            $name = $name !== null && $name !== '' ? $name : $this->autoName($user);

            // If a group with this name already exists for this user — replace
            // its members. Keeps `/bind` idempotent for the same name.
            $defaults = ['paused_at' => null, 'mode' => $mode];
            if ($notifyIntervalMinutes !== null) {
                $defaults['notify_interval_minutes'] = $notifyIntervalMinutes;
            }
            $group = UserCompareGroup::query()->updateOrCreate(
                ['user_id' => $user->id, 'name' => $name],
                $defaults,
            );
            $group->members()->delete();

            foreach (array_values($landings) as $i => $landing) {
                $tracked = TrackedLanding::query()->firstOrCreate(
                    ['landing_uuid' => $landing->uuid, 'position' => $position],
                    ['tracking_started_at' => CarbonImmutable::now(), 'paused_at' => null],
                );
                if ($tracked->paused_at !== null) {
                    $tracked->paused_at = null;
                    $tracked->save();
                }

                UserCompareGroupLanding::create([
                    'user_compare_group_id' => $group->id,
                    'tracked_landing_id' => $tracked->id,
                    'sort_order' => $i,
                ]);
            }

            return $group->fresh('members.trackedLanding.landing');
        });
    }

    private function autoName(User $user): string
    {
        $base = 'g';
        // Find the lowest free "gN" for this user.
        $existing = $user->compareGroups()->where('name', 'like', "{$base}%")->pluck('name')->all();
        $taken = [];
        foreach ($existing as $n) {
            if (preg_match('/^'.preg_quote($base, '/').'(\d+)$/', $n, $m)) {
                $taken[(int) $m[1]] = true;
            }
        }
        $i = 1;
        while (isset($taken[$i])) {
            $i++;
        }

        return $base.$i;
    }
}
