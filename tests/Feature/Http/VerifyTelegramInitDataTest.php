<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyTelegramInitDataTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '7654321:test-bot-token';

    public function test_blocks_without_init_data(): void
    {
        config()->set('services.telegram.token', self::BOT_TOKEN);

        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
    }

    public function test_blocks_with_bad_signature(): void
    {
        config()->set('services.telegram.token', self::BOT_TOKEN);

        $response = $this->getJson('/api/v1/me', [
            'Authorization' => 'tma user=%7B%22id%22%3A1%7D&auth_date=1&hash=DEADBEEF',
        ]);

        $response->assertStatus(401);
    }

    public function test_passes_with_valid_init_data_and_creates_user(): void
    {
        config()->set('services.telegram.token', self::BOT_TOKEN);

        $initData = $this->makeInitData([
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 12345, 'username' => 'alice', 'first_name' => 'Alice']),
        ]);

        $response = $this->getJson('/api/v1/me', [
            'Authorization' => 'tma '.$initData,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('telegram_user_id', '12345');
        $response->assertJsonPath('username', 'alice');
        $this->assertSame('alice', User::query()->first()->telegram_username);
    }

    public function test_passes_through_when_no_bot_token_configured(): void
    {
        // Dev-mode fallback: empty token = skip verification.
        config()->set('services.telegram.token', '');

        // Without a user resolved, /me returns 401 — but the middleware itself
        // didn't reject. We confirm by hitting a route that doesn't require a
        // user (none right now), so instead we just check that the status
        // isn't 401 with the "missing initData" body.
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'no user');
    }

    /** @param  array<string, string>  $fields */
    private function makeInitData(array $fields): string
    {
        ksort($fields);
        $check = [];
        foreach ($fields as $k => $v) {
            $check[] = "{$k}={$v}";
        }
        $secret = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $hash = hash_hmac('sha256', implode("\n", $check), $secret);

        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = urlencode($k).'='.urlencode($v);
        }
        $parts[] = 'hash='.$hash;

        return implode('&', $parts);
    }
}
