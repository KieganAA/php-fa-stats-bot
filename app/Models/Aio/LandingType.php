<?php

namespace App\Models\Aio;

use Illuminate\Database\Eloquent\Model;

class LandingType extends Model
{
    protected $table = 'aio_landing_types';

    protected $guarded = ['id'];

    protected $casts = [
        'raw' => 'array',
        'aio_created_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
