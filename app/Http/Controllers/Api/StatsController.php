<?php

namespace App\Http\Controllers\Api;

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\PrimitiveResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Mini App stats endpoint. Mirror of /stats in the bot — takes a primitive
 * (country code in Phase K, more dimensions later) + period and returns the
 * projected target-metric totals for that slice.
 */
class StatsController
{
    public function show(
        Request $request,
        PrimitiveResolver $primitives,
        PeriodParser $periods,
        LandingReports $reports,
        TargetMetricSet $targets,
    ): JsonResponse {
        $data = $request->validate([
            'primitive' => 'required|string|max:64',
            'period' => 'sometimes|nullable|string|max:64',
        ]);

        try {
            $resolved = $primitives->resolve($data['primitive']);
            $window = $periods->parse($data['period'] ?? null);

            $pivot = $reports->statsByPrimitive(
                filterKey: $resolved['filter_key'],
                filterValue: $resolved['filter_value'],
                from: $window['from'],
                to: $window['to'],
                timezone: $window['timezone'],
            );

            $raw = $pivot->rows[0]['metrics'] ?? [];
            $metrics = $targets->project($raw);

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
                'metrics' => $metrics,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
