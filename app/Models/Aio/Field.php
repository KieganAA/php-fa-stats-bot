<?php

namespace App\Models\Aio;

use Illuminate\Database\Eloquent\Model;

class Field extends Model
{
    protected $table = 'aio_fields';

    protected $guarded = ['id'];

    protected $casts = [
        'raw' => 'array',
        'synced_at' => 'datetime',
    ];
}
