<?php

namespace App\Http\Middleware;

use App\Services\Auth\AppContext;
use App\Services\Auth\TelegramInitDataVerifier;
use App\Services\Auth\TelegramUserResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gates Mini App API routes. The frontend pulls window.Telegram.WebApp.initData
 * and posts it back via either:
 *
 *   - `Authorization: tma <initData>`           (preferred — standard header)
 *   - `X-Telegram-Init-Data: <initData>`        (fallback for cases where
 *                                                Authorization is unavailable)
 *
 * On success: upserts a User from the verified TG identity, parks them in
 * AppContext, attaches the User to the Request for downstream controllers.
 *
 * If TELEGRAM_TOKEN is empty (local dev without setup) we don't verify —
 * makes onboarding less painful. Production env always has a token.
 */
class VerifyTelegramInitData
{
    public function __construct(
        private readonly TelegramInitDataVerifier $verifier,
        private readonly TelegramUserResolver $userResolver,
        private readonly AppContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $botToken = (string) config('services.telegram.token', '');
        if ($botToken === '') {
            // Dev fallback: skip verification — no token means we can't verify
            // anyway. Don't leak a "you are user 0" to the app; just pass.
            return $next($request);
        }

        $initData = $this->extractInitData($request);
        if ($initData === null) {
            return response()->json(['error' => 'missing initData'], 401);
        }

        try {
            $payload = $this->verifier->verify($initData);
        } catch (Throwable $e) {
            return response()->json(['error' => 'invalid initData', 'reason' => $e->getMessage()], 401);
        }

        $user = $this->userResolver->resolve($payload['user']);
        $this->context->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    private function extractInitData(Request $request): ?string
    {
        $auth = (string) $request->headers->get('authorization', '');
        if (preg_match('/^tma\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }

        $explicit = $request->headers->get('x-telegram-init-data');

        return $explicit ? trim($explicit) : null;
    }
}
