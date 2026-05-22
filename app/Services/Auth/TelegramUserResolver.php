<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Upsert-or-find a User by their Telegram identity. Used by both the bot
 * middleware (where the source is Nutgram's User object) and the Mini App
 * middleware (where the source is verified WebApp initData).
 *
 * We touch `last_seen_at` and refresh display fields on every call — names
 * and usernames change on Telegram's side and we want them reasonably fresh
 * for the Mini App UI.
 */
final class TelegramUserResolver
{
    /**
     * @param  array{
     *     id: int|string,
     *     username?: ?string,
     *     first_name?: ?string,
     *     last_name?: ?string,
     *     language_code?: ?string,
     * }  $tgUser
     */
    public function resolve(array $tgUser): User
    {
        $telegramUserId = (string) $tgUser['id'];

        $user = User::query()->firstOrNew(['telegram_user_id' => $telegramUserId]);

        $user->telegram_username = $this->normalizeUsername($tgUser['username'] ?? null);
        $user->telegram_first_name = $this->trim($tgUser['first_name'] ?? null);
        $user->telegram_last_name = $this->trim($tgUser['last_name'] ?? null);
        $user->telegram_language_code = $this->trim($tgUser['language_code'] ?? null);
        $user->last_seen_at = CarbonImmutable::now();

        if (! $user->exists) {
            // Sensible default — Laravel infers locale from APP_TIMEZONE, but
            // we want each user to be able to override later from the Mini App.
            $user->timezone ??= (string) config('app.timezone', 'UTC');
            $user->settings ??= [];
        }

        $user->save();

        return $user;
    }

    private function normalizeUsername(?string $username): ?string
    {
        if ($username === null) {
            return null;
        }
        $clean = ltrim(trim($username), '@');

        return $clean === '' ? null : strtolower($clean);
    }

    private function trim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }
}
