<?php

namespace App\Models\Aio;

use Illuminate\Database\Eloquent\Model;

class Landing extends Model
{
    protected $table = 'aio_landings';

    protected $guarded = ['id'];

    protected $casts = [
        'countries' => 'array',
        'is_archived' => 'boolean',
        'mvt_settings' => 'array',
        'raw' => 'array',
        'aio_created_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
