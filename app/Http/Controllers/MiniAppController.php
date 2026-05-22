<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Serves the Mini App shell. Authentication happens entirely on the API
 * routes (initData per request) — the HTML itself is public, but useless
 * without a valid Telegram WebApp context.
 */
class MiniAppController
{
    public function __invoke(): View
    {
        return view('app');
    }
}
