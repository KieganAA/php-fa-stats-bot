<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AppContext;
use App\Services\Stats\ComparisonReporter;
use App\Services\Stats\MetricColumnResolver;
use App\Services\Stats\PeriodParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /api/v1/compare?primitives=33169,205215&period=today
 *
 * Same Telegram-HTML the bot's /compare emits, projected onto the user's
 * metric prefs (falls back to defaults if they haven't picked any).
 */
class CompareController
{
    public function show(
        Request $request,
        AppContext $ctx,
        PeriodParser $periods,
        ComparisonReporter $reporter,
    ): JsonResponse {
        $data = $request->validate([
            'primitives' => 'required|string|max:255',
            'period' => 'sometimes|nullable|string|max:64',
            'from' => 'nullable|required_with:to|date_format:Y-m-d',
            'to' => 'nullable|required_with:from|date_format:Y-m-d',
        ]);

        $tokens = array_values(array_filter(
            array_map('trim', explode(',', $data['primitives'])),
            fn ($t) => $t !== '',
        ));
        if (count($tokens) < 2) {
            return response()->json(['error' => 'Нужно минимум 2 примитива.'], 422);
        }

        try {
            $user = $ctx->userOrFail();
            $window = $periods->resolve($data['period'] ?? null, $data['from'] ?? null, $data['to'] ?? null, $user->timezone);
            $names = $user->metricNamesFor(MetricColumnResolver::COMPARE);
            $labels = $user->metricLabelOverrides();
            $html = $reporter->report($tokens, $window, $names, $labels);

            return response()->json([
                'window' => [
                    'from' => $window['from']->format(DATE_ATOM),
                    'to' => $window['to']->format(DATE_ATOM),
                    'label' => $window['label'],
                    'timezone' => $window['timezone'],
                ],
                'html' => $html,
                'tokens' => $tokens,
                'metric_columns' => $user->metricColumnsFor(MetricColumnResolver::COMPARE),
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
