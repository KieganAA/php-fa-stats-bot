<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Personal API tokens for the Chrome extension.
 *
 * Threat model: same as a Sanctum PAT, but inlined to keep the dependency
 * surface small.
 *
 *   - Token format: `bsx_` prefix + 48 url-safe chars (≈288 bits of entropy)
 *     The prefix lets us grep tokens out of logs / scan secret leaks.
 *   - DB stores only SHA-256 (extension_token_hash, unique index).
 *   - Plaintext is shown ONCE on `/extension_token` — user copies into the
 *     extension's options page. Lost? Generate a new one (invalidates old).
 *   - Lookup is a constant-time hash compare + a unique-index probe.
 */
final class ExtensionTokenService
{
    public const PREFIX = 'bsx_';

    /**
     * Replace the user's token with a fresh one. Returns the plaintext —
     * never store this, just send it back to the bot user.
     */
    public function rotate(User $user): string
    {
        $plain = self::PREFIX.Str::random(48);
        $user->extension_token_hash = hash('sha256', $plain);
        $user->extension_token_created_at = CarbonImmutable::now();
        $user->extension_token_used_at = null;
        $user->save();

        return $plain;
    }

    /** Wipe the token — extension stops working. */
    public function revoke(User $user): void
    {
        $user->extension_token_hash = null;
        $user->extension_token_created_at = null;
        $user->extension_token_used_at = null;
        $user->save();
    }

    /**
     * Look up the user owning $plain. Returns null on mismatch. Touches
     * `extension_token_used_at` on success so the bot can show last-seen.
     */
    public function resolve(string $plain): ?User
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }
        $hash = hash('sha256', $plain);
        $user = User::query()->where('extension_token_hash', $hash)->first();
        if ($user === null) {
            return null;
        }
        // Update last-used timestamp on every successful resolve. Cheap, gives
        // the user a "когда последний раз дёргали" signal in the bot.
        $user->extension_token_used_at = CarbonImmutable::now();
        $user->saveQuietly();

        return $user;
    }

    /** For display in the bot — masks all but first/last 4 chars. */
    public static function hint(string $plain): string
    {
        if (strlen($plain) < 12) {
            return '****';
        }

        return substr($plain, 0, 8).'…'.substr($plain, -4);
    }
}
