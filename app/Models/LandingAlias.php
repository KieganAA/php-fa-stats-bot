<?php

namespace App\Models;

use App\Models\Aio\Landing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingAlias extends Model
{
    protected $guarded = ['id'];

    public function landing(): BelongsTo
    {
        return $this->belongsTo(Landing::class, 'landing_uuid', 'uuid');
    }
}
