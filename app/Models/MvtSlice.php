<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MvtSlice extends Model
{
    public const KIND_3H = '3h';
    public const KIND_SINCE_START = 'since_start';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'rows' => 'array',
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'captured_at' => 'datetime',
    ];

    public function trackedLanding(): BelongsTo
    {
        return $this->belongsTo(TrackedLanding::class);
    }
}
