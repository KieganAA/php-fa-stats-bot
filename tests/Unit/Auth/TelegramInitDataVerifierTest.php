<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\TelegramInitDataVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TelegramInitDataVerifierTest extends TestCase
{
    private const BOT_TOKEN = '7654321:test-bot-token';

    public function test_verifies_a_well_signed_payload(): void
    {
        $initData = $this->makeInitData([
            'auth_date' => (string) time(),
            'query_id' => 'AAAA',
            'user' => json_encode(['id' => 42, 'username' => 'alice']),
        ]);

        $payload = (new TelegramInitDataVerifier(self::BOT_TOKEN))->verify($initData);

        $this->assertSame(42, $payload['user']['id']);
        $this->assertSame('alice', $payload['user']['username']);
    }

    public function test_rejects_tampered_user(): void
    {
        $initData = $this->makeInitData([
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 42]),
        ]);
        // Swap the user payload after signing — hash should no longer match.
        $bad = preg_replace('/user=[^&]+/', 'user='.urlencode('{"id":999}'), $initData);

        $this->expectException(RuntimeException::class);
        (new TelegramInitDataVerifier(self::BOT_TOKEN))->verify($bad);
    }

    public function test_rejects_when_no_bot_token(): void
    {
        $this->expectException(RuntimeException::class);
        (new TelegramInitDataVerifier(''))->verify('user=%7B%7D&hash=abc');
    }

    public function test_rejects_when_missing_hash(): void
    {
        $this->expectException(RuntimeException::class);
        (new TelegramInitDataVerifier(self::BOT_TOKEN))->verify('user=%7B%22id%22%3A1%7D');
    }

    public function test_rejects_stale_auth_date(): void
    {
        $initData = $this->makeInitData([
            'auth_date' => (string) (time() - 10_000),
            'user' => json_encode(['id' => 1]),
        ]);

        $this->expectExceptionMessage('too old');
        (new TelegramInitDataVerifier(self::BOT_TOKEN, maxAgeSeconds: 60))->verify($initData);
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
