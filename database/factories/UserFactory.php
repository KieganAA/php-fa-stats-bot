<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'timezone' => 'UTC',
            'default_period' => 'today',
            'default_position' => 1,
            'settings' => [],
        ];
    }

    /** A Telegram-identified user (no email/password). */
    public function telegram(?string $tgId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'telegram_user_id' => $tgId ?? (string) fake()->unique()->numberBetween(10_000_000, 999_999_999),
            'telegram_username' => strtolower(fake()->userName()),
            'telegram_first_name' => fake()->firstName(),
            'name' => null,
            'email' => null,
            'password' => null,
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
