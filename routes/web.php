<?php

use Illuminate\Support\Facades\Route;
use SergiX44\Nutgram\Nutgram;

Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'status' => 'ok',
]));

Route::get('/health', fn () => response()->json(['ok' => true]));

Route::post('/telegram/webhook', function (Nutgram $bot) {
    $bot->run();

    return response()->noContent();
})->name('telegram.webhook');
