<?php

namespace App\Http\Controllers\Api;

use App\Models\Aio\Landing;
use App\Services\Stats\MvtFormatter;
use App\Services\Stats\MvtReporter;
use App\Services\Stats\PeriodParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /api/v1/mvt?primitive=33169&period=today
 *
 * Variant breakdown for one landing. Accepts human_id or uuid.
 */
class MvtController
{
    public function show(
        Request $request,
        \App\Services\Auth\AppContext $ctx,
        PeriodParser $periods,
        MvtReporter $reporter,
        MvtFormatter $formatter,
    ): JsonResponse {
        $data = $request->validate([
            'primitive' => 'required|string|max:64',
            'period' => 'sometimes|nullable|string|max:64',
        ]);

        $token = trim($data['primitive']);
        $landing = null;
        if (ctype_digit($token)) {
            $landing = Landing::query()->where('human_id', (int) $token)->first();
        } elseif (preg_match('/^[0-9a-f-]{36}$/i', $token)) {
            $landing = Landing::query()->where('uuid', $token)->first();
        }
        if ($landing === null) {
            return response()->json(['error' => "Лендинг «{$token}» не найден."], 422);
        }

        try {
            $window = $periods->parse($data['period'] ?? null, $ctx->userOrFail()->timezone);
            $report = $reporter->report($landing, $window);
            $html = $formatter->format($report);

            return response()->json([
                'landing' => [
                    'human_id' => $landing->human_id,
                    'uuid' => $landing->uuid,
                    'name' => $landing->name,
                    'type' => $landing->landing_type_name,
                    'country' => $landing->countries[0] ?? null,
                ],
                'window' => [
                    'from' => $window['from']->format(DATE_ATOM),
                    'to' => $window['to']->format(DATE_ATOM),
                    'label' => $window['label'],
                    'timezone' => $window['timezone'],
                ],
                'active_slugs' => $report['active_slugs'],
                'rows' => array_map(fn ($r) => [
                    'variants' => $r['variants'],
                    'metrics' => $r['metrics'],
                ], $report['rows']),
                'html' => $html,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
