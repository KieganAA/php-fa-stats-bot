<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserCompareGroup extends Model
{
    public const MODE_COMPARE = 'compare';

    public const MODE_MVT = 'mvt';

    public const DEFAULT_INTERVAL_MINUTES = 180;

    /** Preset intervals offered in the picker (minutes). */
    public const INTERVAL_OPTIONS = [60, 180, 360, 720, 1440];

    public const INTERVAL_MIN = 15;

    public const INTERVAL_MAX = 10080; // 7 days

    protected $guarded = ['id'];

    protected $casts = [
        'paused_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'notify_interval_minutes' => 'integer',
    ];

    /**
     * True when the cron tick should fire a push for this group: either we've
     * never pushed, or the configured interval has elapsed. Kept on the model
     * so SnapshotCommand and tests share the exact same condition.
     */
    public function isDueForPush(\DateTimeInterface $now): bool
    {
        if (! $this->isActive()) {
            return false;
        }
        if ($this->last_notified_at === null) {
            return true;
        }

        $interval = max(1, (int) ($this->notify_interval_minutes ?? self::DEFAULT_INTERVAL_MINUTES));
        $next = $this->last_notified_at->copy()->addMinutes($interval);

        return $next <= $now;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(UserCompareGroupLanding::class)->orderBy('sort_order');
    }

    public function trackedLandings(): BelongsToMany
    {
        return $this->belongsToMany(
            TrackedLanding::class,
            'user_compare_group_landings',
            'user_compare_group_id',
            'tracked_landing_id',
        )->withPivot('sort_order')->orderBy('sort_order');
    }

    public function isActive(): bool
    {
        return $this->paused_at === null;
    }
}
