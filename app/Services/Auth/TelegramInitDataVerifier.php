<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Verifies a Telegram Mini App initData payload per the spec:
 *
 *   https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
 *
 * Algorithm:
 *   1. Parse the URL-encoded init_data string into key=value pairs.
 *   2. Pull out the `hash` field; remaining pairs are the data-check.
 *   3. Sort the pairs alphabetically by key, join as "k=v\nk=v\n...".
 *   4. Derive the secret: HMAC-SHA256("WebAppData", BOT_TOKEN).
 *   5. Expected hash: HMAC-SHA256(secret, data-check string), hex.
 *   6. Compare to the supplied hash via hash_equals (constant time).
 *
 * Optionally enforces a max age on `auth_date` to reject stale launches.
 */
final class TelegramInitDataVerifier
{
    public function __construct(
        private readonly string $botToken,
        private readonly int $maxAgeSeconds = 86400,
    ) {}

    /**
     * @return array{
     *   user: array<string,mixed>,
     *   auth_date: int,
     *   query_id?: string,
     *   start_param?: string,
     *   raw: array<string,string>,
     * }
     *
     * @throws RuntimeException when the signature is invalid or stale
     */
    public function verify(string $initData): array
    {
        if ($this->botToken === '') {
            throw new RuntimeException('TELEGRAM_TOKEN is not set; cannot verify initData.');
        }
        if (trim($initData) === '') {
            throw new RuntimeException('Empty initData.');
        }

        $pairs = [];
        parse_str($initData, $pairs);
        if (! isset($pairs['hash']) || ! is_string($pairs['hash'])) {
            throw new RuntimeException('initData missing hash.');
        }
        $supplied = $pairs['hash'];
        unset($pairs['hash']);

        // Use the *original* encoded values for the check string, not the decoded
        // ones parse_str produced — Telegram's reference signs raw form values.
        $raw = [];
        foreach (explode('&', $initData) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $chunk, 2), 2, '');
            $raw[urldecode($k)] = urldecode($v);
        }
        unset($raw['hash']);

        ksort($raw);
        $dataCheck = [];
        foreach ($raw as $k => $v) {
            $dataCheck[] = "{$k}={$v}";
        }
        $checkString = implode("\n", $dataCheck);

        $secret = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', $checkString, $secret);

        if (! hash_equals($expected, $supplied)) {
            throw new RuntimeException('initData hash mismatch.');
        }

        $authDate = (int) ($raw['auth_date'] ?? 0);
        if ($this->maxAgeSeconds > 0 && $authDate > 0) {
            $age = time() - $authDate;
            if ($age > $this->maxAgeSeconds) {
                throw new RuntimeException("initData too old ({$age}s).");
            }
        }

        $user = json_decode((string) ($raw['user'] ?? '{}'), true);
        if (! is_array($user) || ! isset($user['id'])) {
            throw new RuntimeException('initData missing user.id.');
        }

        return [
            'user' => $user,
            'auth_date' => $authDate,
            'query_id' => $raw['query_id'] ?? null,
            'start_param' => $raw['start_param'] ?? null,
            'raw' => $raw,
        ];
    }
}
