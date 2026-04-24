<?php

namespace App\Models\Aio;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class Field extends Model
{
    protected $table = 'aio_fields';

    protected $guarded = ['id'];

    protected $casts = [
        'raw' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Pivot-Report `definitions[].key` / `conditions[].key` for this field.
     *
     * Static tracker fields (FB, launcher, …) carry a literal `ch_column` like
     * `field_creative` — return that. Custom visit fields are stored in typed
     * buckets keyed by uuid, e.g. `string_fields[<uuid>]` for `pre_processor: String`.
     */
    public function clickhouseKey(): string
    {
        if (! empty($this->ch_column)) {
            return (string) $this->ch_column;
        }

        $bucket = match (data_get($this->raw, 'field.pre_processor')) {
            'String' => 'string_fields',
            default => throw new RuntimeException(
                "Unsupported pre_processor for field {$this->slug}: "
                .var_export(data_get($this->raw, 'field.pre_processor'), true)
            ),
        };

        return "{$bucket}[{$this->uuid}]";
    }
}
