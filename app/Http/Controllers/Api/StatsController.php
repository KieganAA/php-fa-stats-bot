<?php

namespace App\Http\Controllers\Api;

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Auth\AppContext;
use App\Services\Stats\LandingFormatter;
use App\Services\Stats\MetricDisplay;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\PrimitiveResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Mini App stats endpoint. Returns a primitive's totals projected onto the
 * user's metric preferences (or defaults if they haven't picked any).
 */
class StatsController
{
    public function show(
        Request $request,
        AppContext $ctx,
        PrimitiveResolver $primitives,
        PeriodParser $periods,
        LandingReports $reports,
        TargetMetricSet $targets,
        LandingFormatter $landingFmt,
    ): JsonResponse {
        $data = $request->validate([
            'primitive' => 'required|string|max:64',
            'period' => 'sometimes|nullable|string|max:64',
        ]);

        try {
            $user = $ctx->userOrFail();
            $resolved = $primitives->resolve($data['primitive']);
            $resolved = $landingFmt->enrichLabel($resolved, $user->landingDisplayOpts());
            $window = $periods->parse($data['period'] ?? null, $user->timezone);

            $pivot = $reports->statsByPrimitive(
                filterKey: $resolved['filter_key'],
                filterValue: $resolved['filter_value'],
                from: $window['from'],
                to: $window['to'],
                timezone: $window['timezone'],
            );

            $names = $ctx->userOrFail()->metricPreferences();
            $raw = $pivot->rows[0]['metrics'] ?? [];
            $metrics = $targets->project($raw, $names);

            return response()->json([
                'primitive' => [
                    'kind' => $resolved['kind'],
                    'value' => $resolved['filter_value'],
                    'label' => $resolved['label'],
                ],
                'window' => [
                    'from' => $window['from']->format(DATE_ATOM),
                    'to' => $window['to']->format(DATE_ATOM),
                    'label' => $window['label'],
                    'timezone' => $window['timezone'],
                ],
                'metric_columns' => MetricDisplay::describe($names),
                'metrics' => $metrics,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
