<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserCompareGroup extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'paused_at' => 'datetime',
        'last_notified_at' => 'datetime',
    ];

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
