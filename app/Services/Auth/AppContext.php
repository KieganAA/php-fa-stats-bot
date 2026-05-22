<?php

namespace App\Services\Auth;

use App\Models\User;
use RuntimeException;

/**
 * Per-request slot for the resolved User. Bound as a singleton, but cleared
 * between requests by Octane's RequestReceived listener (or the test
 * harness). Two surfaces fill it:
 *
 *   - Telegram bot middleware: upserts by telegram_user_id on every update
 *   - Mini App middleware:    verifies WebApp initData, looks up by tg id
 *
 * Code that needs the current user should depend on AppContext rather than
 * reaching into either pipeline — keeps services agnostic of which surface
 * triggered them.
 */
final class AppContext
{
    private ?User $user = null;

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function clear(): void
    {
        $this->user = null;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function userOrFail(): User
    {
        return $this->user ?? throw new RuntimeException('No user in AppContext.');
    }
}
