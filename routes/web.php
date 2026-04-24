<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\VerifyTelegramWebhook;
use Illuminate\Support\Facades\Route;
use SergiX44\Nutgram\Nutgram;

Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'status' => 'ok',
]));

Route::get('/health', HealthController::class);

Route::post('/telegram/webhook', function (Nutgram $bot) {
    $bot->run();

    return response()->noContent();
})->middleware(VerifyTelegramWebhook::class)->name('telegram.webhook');
