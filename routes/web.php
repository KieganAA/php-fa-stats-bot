<?php

use App\Http\Controllers\Api\CompareController;
use App\Http\Controllers\Api\Ext\ExtensionController;
use App\Http\Controllers\Api\CampaignsController;
use App\Http\Controllers\Api\GroupsController;
use App\Http\Controllers\Api\LandingsController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\MvtController;
use App\Http\Controllers\Api\RankingsController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\ExtensionDownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MiniAppController;
use App\Http\Middleware\VerifyExtensionToken;
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

// Chrome-extension download. Public because the bundle has no secrets —
// users authenticate by pasting a personal token from /extension_token.
Route::get('/extension.zip', ExtensionDownloadController::class)->name('extension.zip');

// Chrome extension API. Bearer-token auth (per-user, generated via /extension_token).
// Pre-flight OPTIONS responds with permissive CORS so the extension popup +
// content script can hit /api/ext/ from any origin (chrome-extension://*, AIO domain).
Route::options('api/ext/{any}', function () {
    return response()->noContent(204)
        ->withHeaders([
            'Access-Control-Allow-Origin' => request()->header('Origin', '*'),
            'Access-Control-Allow-Methods' => 'GET,POST,PATCH,DELETE,OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
            'Access-Control-Max-Age' => '86400',
            'Vary' => 'Origin',
        ]);
})->where('any', '.*');

Route::middleware(VerifyExtensionToken::class)
    ->prefix('api/ext')
    ->group(function () {
        Route::get('me', [ExtensionController::class, 'me']);
        Route::get('groups', [ExtensionController::class, 'groups']);
        Route::post('groups', [ExtensionController::class, 'createGroup']);
        Route::patch('groups/{group}', [ExtensionController::class, 'updateGroup']);
        Route::delete('groups/{group}', [ExtensionController::class, 'destroyGroup']);
        Route::get('landings', [ExtensionController::class, 'landings']);
        Route::post('resolve', [ExtensionController::class, 'resolve']);

        // Campaign subscriptions — the extension's primary surface.
        Route::get('campaigns', [ExtensionController::class, 'campaigns']);
        Route::post('campaign', [ExtensionController::class, 'subscribeCampaign']);
        Route::patch('campaigns/{campaign}', [ExtensionController::class, 'updateCampaign']);
        Route::post('campaigns/{campaign}/resync', [ExtensionController::class, 'resyncCampaign']);
        Route::post('campaigns/{campaign}/push', [ExtensionController::class, 'pushCampaign']);
        Route::delete('campaigns/{campaign}', [ExtensionController::class, 'destroyCampaign']);
    });

// Mini App JSON API. Every request must carry verified initData.
Route::middleware(VerifyTelegramInitData::class)
    ->prefix('api/v1')
    ->group(function () {
        // Profile / settings
        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);
        Route::put('me/metrics', [MeController::class, 'setMetrics']);
        Route::put('me/metrics/{context}', [MeController::class, 'setContextMetrics']);
        Route::put('me/metric-labels', [MeController::class, 'setMetricLabels']);

        // Metric catalog for the Settings picker
        Route::get('metrics', [MetricsController::class, 'index']);

        // Landing search (autocomplete for the subscription picker)
        Route::get('landings', [LandingsController::class, 'index']);

        // Numbers
        Route::get('stats', [StatsController::class, 'show']);
        Route::get('compare', [CompareController::class, 'show']);
        Route::get('rankings', [RankingsController::class, 'show']);
        Route::get('mvt', [MvtController::class, 'show']);

        // Persistent tracking groups (legacy landing subscriptions)
        Route::get('groups', [GroupsController::class, 'index']);
        Route::post('groups', [GroupsController::class, 'store']);
        Route::patch('groups/{group}', [GroupsController::class, 'update']);
        Route::delete('groups/{group}', [GroupsController::class, 'destroy']);

        // Campaign subscriptions — the Mini App's primary surface.
        Route::get('campaigns', [CampaignsController::class, 'index']);
        Route::post('campaigns', [CampaignsController::class, 'store']);
        Route::patch('campaigns/{campaign}', [CampaignsController::class, 'update']);
        Route::post('campaigns/{campaign}/resync', [CampaignsController::class, 'resync']);
        Route::post('campaigns/{campaign}/push', [CampaignsController::class, 'push']);
        Route::delete('campaigns/{campaign}', [CampaignsController::class, 'destroy']);
    });
