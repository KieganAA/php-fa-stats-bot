<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLandingBinding extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'notify_3h' => 'boolean',
        'notify_since_start' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trackedLanding(): BelongsTo
    {
        return $this->belongsTo(TrackedLanding::class);
    }
}
