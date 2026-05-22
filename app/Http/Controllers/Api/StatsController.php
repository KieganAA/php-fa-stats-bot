<?php

namespace App\Http\Controllers\Api;

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Stats\AliasResolver;
use App\Services\Stats\PeriodParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StatsController
{
    /**
     * GET /api/v1/stats?alias=<token>&period=<period>
     *
     * Returns: { window: {from, to, label, timezone}, metrics: {...} }
     */
    public function show(
        Request $request,
        AliasResolver $resolver,
        PeriodParser $periods,
        LandingReports $reports,
        TargetMetricSet $targets,
    ): JsonResponse {
        $data = $request->validate([
            'alias' => 'required|string',
            'period' => 'sometimes|nullable|string',
        ]);

        try {
            $resolved = $resolver->resolve($data['alias']);
            $window = $periods->parse($data['period'] ?? null);

            $position = $resolved['alias']?->position ?? 1;
            $pivot = $reports->landingStats(
                landingUuid: $resolved['landing']->uuid,
                position: $position,
                from: $window['from'],
                to: $window['to'],
                timezone: $window['timezone'],
            );

            $raw = $pivot->rows[0]['metrics'] ?? [];
            $metrics = $targets->project($raw);

            return response()->json([
                'window' => $this->serializeWindow($window),
                'alias' => $resolved['alias']?->alias,
                'landing' => [
                    'uuid' => $resolved['landing']->uuid,
                    'human_id' => $resolved['landing']->human_id,
                    'name' => $resolved['landing']->name,
                ],
                'position' => $position,
                'metrics' => $metrics,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/compare?aliases=<a,b,c>&period=<period>
     */
    public function compare(
        Request $request,
        AliasResolver $resolver,
        PeriodParser $periods,
        LandingReports $reports,
        TargetMetricSet $targets,
    ): JsonResponse {
        $data = $request->validate([
            'aliases' => 'required|string',
            'period' => 'sometimes|nullable|string',
        ]);

        $tokens = array_values(array_filter(array_map('trim', explode(',', $data['aliases']))));
        if (count($tokens) < 2) {
            return response()->json(['error' => 'need at least 2 aliases'], 422);
        }

        try {
            $resolved = $resolver->resolveAll($tokens);
            $positions = array_unique(array_map(fn ($r) => $r['alias']?->position ?? 1, $resolved));
            if (count($positions) > 1) {
                return response()->json(['error' => 'aliases must be on the same LP position'], 422);
            }
            $position = (int) array_values($positions)[0];
            $window = $periods->parse($data['period'] ?? null);

            $uuids = array_map(fn ($r) => $r['landing']->uuid, $resolved);
            $pivot = $reports->compareLandings(
                landingUuids: array_values($uuids),
                position: $position,
                from: $window['from'],
                to: $window['to'],
                timezone: $window['timezone'],
            );

            $byUuid = [];
            foreach ($pivot->rows as $row) {
                $uuid = (string) ($row['dimensions']['group_0'] ?? '');
                $byUuid[$uuid] = $row['metrics'];
            }

            $entries = [];
            foreach ($resolved as $r) {
                $entries[] = [
                    'alias' => $r['alias']?->alias,
                    'token' => $r['token'],
                    'landing' => [
                        'uuid' => $r['landing']->uuid,
                        'name' => $r['landing']->name,
                    ],
                    'metrics' => $targets->project($byUuid[$r['landing']->uuid] ?? []),
                ];
            }

            return response()->json([
                'window' => $this->serializeWindow($window),
                'position' => $position,
                'entries' => $entries,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** @param  array{from: \DateTimeInterface, to: \DateTimeInterface, label: string, timezone: string}  $window */
    private function serializeWindow(array $window): array
    {
        return [
            'from' => $window['from']->format(DATE_ATOM),
            'to' => $window['to']->format(DATE_ATOM),
            'label' => $window['label'],
            'timezone' => $window['timezone'],
        ];
    }
}
