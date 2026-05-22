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

    public function bindings(): HasMany
    {
        return $this->hasMany(UserLandingBinding::class);
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
