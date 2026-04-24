<?php

namespace App\Models;

use App\Models\Aio\Field;
use App\Models\Aio\Landing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackedLanding extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'tracking_started_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function landing(): BelongsTo
    {
        return $this->belongsTo(Landing::class, 'landing_uuid', 'uuid');
    }

    public function mvtFields(): BelongsToMany
    {
        return $this->belongsToMany(
            Field::class,
            'tracked_landing_fields',
            'tracked_landing_id',
            'field_id',
        )->withTimestamps();
    }

    public function slices(): HasMany
    {
        return $this->hasMany(MvtSlice::class);
    }

    public function isActive(): bool
    {
        return $this->paused_at === null;
    }
}
