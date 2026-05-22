<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AppContext;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\RankingReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /api/v1/rankings?kind=geo&period=today&top_n=15
 *
 * kind ∈ { geo, buyers, lp1, lp2 } — mirrors the bot's /geo /buyers /lps1 /lps2.
 * Uses the first three of the user's chosen metrics; if they haven't picked
 * any, falls back to MetricDisplay::topNames() (clicks / leads / CR%).
 */
class RankingsController
{
    public function show(
        Request $request,
        AppContext $ctx,
        PeriodParser $periods,
        RankingReporter $reporter,
    ): JsonResponse {
        $data = $request->validate([
            'kind' => 'required|string|in:geo,buyers,lp1,lp2',
            'period' => 'sometimes|nullable|string|max:64',
            'top_n' => 'sometimes|integer|min:1|max:50',
        ]);

        try {
            $user = $ctx->userOrFail();
            $window = $periods->parse($data['period'] ?? null, $user->timezone);
            // Top screens stay narrow (3 columns) — take the first 3 of the
            // user's prefs so they get to choose the priority metrics here too.
            $names = $user->hasCustomMetricPreferences()
                ? array_slice($user->metricPreferences(), 0, 3)
                : null;
            $html = $reporter->report($data['kind'], $window, $data['top_n'] ?? 15, metricNames: $names);

            return response()->json([
                'kind' => $data['kind'],
                'window' => [
                    'from' => $window['from']->format(DATE_ATOM),
                    'to' => $window['to']->format(DATE_ATOM),
                    'label' => $window['label'],
                    'timezone' => $window['timezone'],
                ],
                'html' => $html,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
