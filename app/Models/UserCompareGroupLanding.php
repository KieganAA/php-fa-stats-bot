<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCompareGroupLanding extends Model
{
    protected $table = 'user_compare_group_landings';

    protected $guarded = ['id'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(UserCompareGroup::class, 'user_compare_group_id');
    }

    public function trackedLanding(): BelongsTo
    {
        return $this->belongsTo(TrackedLanding::class);
    }
}
