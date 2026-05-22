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
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(LandingAlias::class, 'created_by_id');
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
