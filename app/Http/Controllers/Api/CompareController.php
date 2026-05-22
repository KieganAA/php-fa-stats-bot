<?php

namespace App\Http\Controllers\Api;

use App\Services\Stats\ComparisonReporter;
use App\Services\Stats\PeriodParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /api/v1/compare?primitives=33169,205215&period=today
 *
 * Returns the same Telegram-HTML report the bot's /compare command renders —
 * we keep the HTML in the API payload so the Mini App can show it identically
 * to the chat experience (right down to Δ% delta highlighting). Cuts down on
 * duplicate render logic.
 */
class CompareController
{
    public function show(
        Request $request,
        PeriodParser $periods,
        ComparisonReporter $reporter,
    ): JsonResponse {
        $data = $request->validate([
            'primitives' => 'required|string|max:255',
            'period' => 'sometimes|nullable|string|max:64',
        ]);

        $tokens = array_values(array_filter(
            array_map('trim', explode(',', $data['primitives'])),
            fn ($t) => $t !== '',
        ));
        if (count($tokens) < 2) {
            return response()->json(['error' => 'Нужно минимум 2 примитива.'], 422);
        }

        try {
            $window = $periods->parse($data['period'] ?? null);
            $html = $reporter->report($tokens, $window);

            return response()->json([
                'window' => [
                    'from' => $window['from']->format(DATE_ATOM),
                    'to' => $window['to']->format(DATE_ATOM),
                    'label' => $window['label'],
                    'timezone' => $window['timezone'],
                ],
                'html' => $html,
                'tokens' => $tokens,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
