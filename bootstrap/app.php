<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSRF guards cookie-session forms; these three surfaces are stateless
        // APIs with their own auth (webhook secret header, extension Bearer
        // token, Mini App initData HMAC) — cookie CSRF just 419s them.
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
            'api/ext/*',
            'api/v1/*',
        ]);

        // Trust upstream proxies (ngrok in dev, FrankenPHP-front-of-Caddy or
        // nginx in prod). Without this Laravel sees the inbound connection as
        // HTTP and emits asset() URLs with http:// — Telegram's WebApp blocks
        // those as mixed content and the Mini App goes blank.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
