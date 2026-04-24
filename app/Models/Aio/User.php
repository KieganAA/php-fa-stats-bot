<?php

namespace App\Models\Aio;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'aio_users';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'raw' => 'array',
        'aio_created_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
