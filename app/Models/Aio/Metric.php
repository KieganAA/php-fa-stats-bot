<?php

namespace App\Models\Aio;

use Illuminate\Database\Eloquent\Model;

class Metric extends Model
{
    protected $table = 'aio_metrics';

    protected $guarded = ['id'];

    protected $casts = [
        'raw' => 'array',
        'synced_at' => 'datetime',
    ];
}
