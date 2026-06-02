<?php

namespace App\Http\Middleware;

use App\Services\Auth\AppContext;
use App\Services\Auth\ExtensionTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from the Chrome extension via personal Bearer tokens.
 *
 *   Authorization: Bearer bsx_<48 chars>
 *
 * Mirrors VerifyTelegramInitData for the Mini App — successful auth parks
 * the User into AppContext so downstream controllers can reuse the same
 * `$ctx->userOrFail()` they call from /api/v1/* routes.
 */
class VerifyExtensionToken
{
    public function __construct(
        private readonly ExtensionTokenService $tokens,
        private readonly AppContext $ctx,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if ($header === '' || ! str_starts_with($header, 'Bearer ')) {
            return $this->reject('Missing Bearer token');
        }

        $plain = substr($header, 7);
        $user = $this->tokens->resolve($plain);
        if ($user === null) {
            return $this->reject('Invalid token');
        }

        $this->ctx->setUser($user);

        $response = $next($request);

        // CORS — extension origin is `chrome-extension://<id>` which the
        // browser sends as a custom header. We allow any origin since the
        // token itself is the auth, not the origin. Echoing the request
        // origin keeps CORS preflights happy on Chrome.
        $origin = (string) $request->header('Origin', '');
        if ($origin !== '') {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }

    private function reject(string $message): Response
    {
        return response()
            ->json(['error' => $message], 401)
            ->header('WWW-Authenticate', 'Bearer realm="bot-stats extension"');
    }
}
