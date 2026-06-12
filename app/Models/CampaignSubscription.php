<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's subscription to an AIO campaign. Owns N child compare groups
 * (one per split / MVT landing) created by the resync logic.
 *
 * @property int $id
 * @property int $user_id
 * @property string $campaign_uuid
 * @property int|null $campaign_human_id
 * @property string $campaign_name
 * @property array|null $countries
 * @property int $notify_interval_minutes
 * @property \Illuminate\Support\Carbon|null $paused_at
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
class CampaignSubscription extends Model
{
    public const DEFAULT_INTERVAL_MINUTES = 180;

    protected $guarded = ['id'];

    protected $casts = [
        'countries' => 'array',
        'notify_interval_minutes' => 'integer',
        'campaign_human_id' => 'integer',
        'paused_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** All child compare groups (splits + MVTs) derived from this campaign. */
    public function children(): HasMany
    {
        return $this->hasMany(UserCompareGroup::class)->orderBy('step_position');
    }

    public function isActive(): bool
    {
        return $this->paused_at === null;
    }

    /** Short identity for labels: "#116400 CA". */
    public function shortLabel(): string
    {
        $id = $this->campaign_human_id !== null ? "#{$this->campaign_human_id}" : '#?';
        $geo = is_array($this->countries) && $this->countries !== []
            ? ' '.implode('/', array_slice($this->countries, 0, 3))
            : '';

        return $id.$geo;
    }
}
