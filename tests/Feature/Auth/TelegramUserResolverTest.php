<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TelegramUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramUserResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_user_when_telegram_id_is_unknown(): void
    {
        $user = app(TelegramUserResolver::class)->resolve([
            'id' => 12345,
            'username' => '@Alice',
            'first_name' => 'Alice',
            'last_name' => 'A.',
            'language_code' => 'en',
        ]);

        $this->assertSame('12345', $user->telegram_user_id);
        $this->assertSame('alice', $user->telegram_username);
        $this->assertSame('Alice', $user->telegram_first_name);
        $this->assertSame('A.', $user->telegram_last_name);
        $this->assertSame('en', $user->telegram_language_code);
        $this->assertNotNull($user->last_seen_at);
        $this->assertSame('UTC', $user->timezone);
        $this->assertSame([], $user->settings);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_updates_an_existing_user_in_place(): void
    {
        User::factory()->telegram('999')->create(['telegram_username' => 'old_name']);

        $user = app(TelegramUserResolver::class)->resolve([
            'id' => '999',
            'username' => 'new_name',
            'first_name' => 'Updated',
        ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('new_name', $user->telegram_username);
        $this->assertSame('Updated', $user->telegram_first_name);
    }

    public function test_handles_missing_optional_fields(): void
    {
        $user = app(TelegramUserResolver::class)->resolve(['id' => 42]);

        $this->assertSame('42', $user->telegram_user_id);
        $this->assertNull($user->telegram_username);
        $this->assertNull($user->telegram_first_name);
    }

    public function test_normalizes_username(): void
    {
        $user = app(TelegramUserResolver::class)->resolve([
            'id' => 1,
            'username' => '@MixedCase',
        ]);

        $this->assertSame('mixedcase', $user->telegram_username);
    }

    public function test_treats_empty_username_as_null(): void
    {
        $user = app(TelegramUserResolver::class)->resolve([
            'id' => 1,
            'username' => '   ',
        ]);

        $this->assertNull($user->telegram_username);
    }

    public function test_refreshes_last_seen_at_on_each_resolve(): void
    {
        $first = app(TelegramUserResolver::class)->resolve(['id' => 7]);
        $initialSeen = $first->last_seen_at;

        sleep(1);

        $second = app(TelegramUserResolver::class)->resolve(['id' => 7]);
        $this->assertTrue($second->last_seen_at->greaterThan($initialSeen));
    }
}
