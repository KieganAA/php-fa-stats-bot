<?php

namespace App\Http\Controllers\Api;

use App\Models\Aio\Metric;
use App\Services\Stats\MetricDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/metrics
 *
 * Lists every metric the user can pick from — the union of aio_metrics
 * (synced hourly) and a derived display kind (count / ratio / percent /
 * money) from MetricDisplay. The Mini App's Settings tab uses this to
 * render the metric-picker.
 *
 * Optional `q` query: case-insensitive substring filter on name.
 */
class MetricsController
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $query = Metric::query()->orderBy('name');
        if ($q !== '') {
            $query->where('name', 'ilike', '%'.$q.'%');
        }

        $metrics = $query->limit(200)->get(['uuid', 'name'])->map(fn ($m) => [
            'uuid' => $m->uuid,
            'name' => $m->name,
            'label' => MetricDisplay::label($m->name),
            'kind' => MetricDisplay::kind($m->name),
        ])->values()->all();

        return response()->json([
            'metrics' => $metrics,
            'defaults' => MetricDisplay::defaultNames(),
        ]);
    }
}
