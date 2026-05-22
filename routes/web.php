<?php

use App\Http\Controllers\Api\CompareController;
use App\Http\Controllers\Api\GroupsController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\MvtController;
use App\Http\Controllers\Api\RankingsController;
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
        // Profile / settings
        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);
        Route::put('me/metrics', [MeController::class, 'setMetrics']);

        // Metric catalog for the Settings picker
        Route::get('metrics', [MetricsController::class, 'index']);

        // Numbers
        Route::get('stats', [StatsController::class, 'show']);
        Route::get('compare', [CompareController::class, 'show']);
        Route::get('rankings', [RankingsController::class, 'show']);
        Route::get('mvt', [MvtController::class, 'show']);

        // Persistent tracking groups
        Route::get('groups', [GroupsController::class, 'index']);
        Route::post('groups', [GroupsController::class, 'store']);
        Route::patch('groups/{group}', [GroupsController::class, 'update']);
        Route::delete('groups/{group}', [GroupsController::class, 'destroy']);
    });
