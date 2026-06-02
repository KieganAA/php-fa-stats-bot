<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'telegram_user_id',
    'telegram_username',
    'telegram_first_name',
    'telegram_last_name',
    'telegram_language_code',
    'timezone',
    'default_period',
    'default_position',
    'settings',
    'last_seen_at',
    'anthropic_api_key',
    'anthropic_model',
    'extension_token_hash',
    'extension_token_created_at',
    'extension_token_used_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
            'last_seen_at' => 'datetime',
            'default_position' => 'integer',
            // Laravel's `encrypted` cast transparently encrypts on save and
            // decrypts on read using APP_KEY. The DB stores opaque ciphertext.
            'anthropic_api_key' => 'encrypted',
            'extension_token_created_at' => 'datetime',
            'extension_token_used_at' => 'datetime',
        ];
    }

    /** Last 4 chars of the user's API key, or null if unset. For display only. */
    public function anthropicKeyHint(): ?string
    {
        $k = $this->anthropic_api_key;
        if (! is_string($k) || $k === '') {
            return null;
        }

        return '…'.substr($k, -4);
    }

    public function compareGroups(): HasMany
    {
        return $this->hasMany(UserCompareGroup::class);
    }

    /**
     * The AIO metric names this user wants in the "stats" context (single
     * primitive view, /stats, /compare, tracking push). Kept around for
     * callers that haven't been threaded with an explicit context yet —
     * everything else should go through MetricColumnResolver directly.
     *
     * @return list<string>
     */
    public function metricPreferences(): array
    {
        return app(\App\Services\Stats\MetricColumnResolver::class)
            ->namesFor($this, \App\Services\Stats\MetricColumnResolver::STATS);
    }

    public function hasCustomMetricPreferences(): bool
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        // Either legacy single-key OR explicit stats preset counts as customized.
        if (isset($settings['metrics']) && is_array($settings['metrics'])) {
            return true;
        }
        $presets = $settings['metric_presets'] ?? null;
        if (is_array($presets) && isset($presets['stats']) && is_array($presets['stats'])) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function metricNamesFor(string $context): array
    {
        return app(\App\Services\Stats\MetricColumnResolver::class)->namesFor($this, $context);
    }

    /**
     * @return list<array{name: string, label: string, kind: string}>
     */
    public function metricColumnsFor(string $context): array
    {
        return app(\App\Services\Stats\MetricColumnResolver::class)->columnsFor($this, $context);
    }

    /**
     * Per-name label overrides — same across every context the metric appears
     * in (a metric is the same thing regardless of report).
     *
     * @return array<string, string>
     */
    public function metricLabelOverrides(): array
    {
        return app(\App\Services\Stats\MetricColumnResolver::class)->labelsFor($this);
    }

    /**
     * Landing-row display options stored at `settings.landing_display`.
     * Defaults are minimal — id + country only. Users opt-in to richer
     * labels via the Mini App.
     *
     * @return array{show_type: bool, show_name: bool}
     */
    public function landingDisplayOpts(): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $d = (array) ($settings['landing_display'] ?? []);

        return [
            'show_type' => (bool) ($d['show_type'] ?? false),
            'show_name' => (bool) ($d['show_name'] ?? false),
        ];
    }

    public function displayName(): string
    {
        if ($this->telegram_username) {
            return '@'.$this->telegram_username;
        }
        $combined = trim(($this->telegram_first_name ?? '').' '.($this->telegram_last_name ?? ''));

        return $combined !== '' ? $combined : 'user#'.$this->id;
    }
}
