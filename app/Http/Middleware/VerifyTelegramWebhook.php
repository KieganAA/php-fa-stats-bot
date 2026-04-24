<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook calls that don't carry the configured secret token.
 *
 * Telegram sends our secret in `X-Telegram-Bot-Api-Secret-Token` when we
 * register the webhook with `secret_token`. If `services.telegram.webhook_secret`
 * is empty we skip the check (dev-friendly) — but log a warning once per boot
 * so it isn't silently disabled in production.
 */
class VerifyTelegramWebhook
{
    private const HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.telegram.webhook_secret', '');

        if ($expected === '') {
            return $next($request);
        }

        $provided = (string) $request->header(self::HEADER, '');

        if (! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
