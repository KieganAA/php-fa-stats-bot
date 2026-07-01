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
 * @property string|null $report_period
 * @property \Illuminate\Support\Carbon|null $paused_at
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 */
class CampaignSubscription extends Model
{
    public const DEFAULT_INTERVAL_MINUTES = 180;

    /** Window each scheduled digest reports; null falls back to this. */
    public const DEFAULT_REPORT_PERIOD = 'today';

    /**
     * Digest-window tokens offered in the UI. Each is a value PeriodParser
     * already understands; keep this in sync with PeriodPicker/Subs.vue.
     */
    public const REPORT_PERIODS = ['today', 'yesterday', '7d', 'week', 'last week', 'month'];

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

    /** Configured digest window token, defaulting to "today". */
    public function reportPeriod(): string
    {
        return $this->report_period ?: self::DEFAULT_REPORT_PERIOD;
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
