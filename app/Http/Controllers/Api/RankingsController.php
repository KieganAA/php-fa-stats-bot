<?php

namespace App\Http\Controllers\Api;

use App\Services\Stats\PeriodParser;
use App\Services\Stats\RankingReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /api/v1/rankings?kind=geo&period=today&top_n=15
 *
 * kind ∈ { geo, buyers, lp1, lp2 } — mirrors the bot's /geo /buyers /lps1 /lps2
 * commands.
 */
class RankingsController
{
    public function show(
        Request $request,
        PeriodParser $periods,
        RankingReporter $reporter,
    ): JsonResponse {
        $data = $request->validate([
            'kind' => 'required|string|in:geo,buyers,lp1,lp2',
            'period' => 'sometimes|nullable|string|max:64',
            'top_n' => 'sometimes|integer|min:1|max:50',
        ]);

        try {
            $window = $periods->parse($data['period'] ?? null);
            $html = $reporter->report($data['kind'], $window, $data['top_n'] ?? 15);

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
