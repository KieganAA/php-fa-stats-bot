<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MiniAppController;
use App\Http\Middleware\VerifyTelegramInitData;
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

// Mini App shell — public HTML. The Telegram WebApp client injects initData;
// without it the API rejects every request, so the page is useless outside
// of Telegram (or a configured dev sandbox with TELEGRAM_TOKEN empty).
Route::get('/app', MiniAppController::class)->name('miniapp');

// Mini App JSON API. Every request must carry verified initData.
Route::middleware(VerifyTelegramInitData::class)
    ->prefix('api/v1')
    ->group(function () {
        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);

        Route::get('stats', [StatsController::class, 'show']);
    });
