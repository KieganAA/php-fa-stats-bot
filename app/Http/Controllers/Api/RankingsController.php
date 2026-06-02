<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AppContext;
use App\Services\Stats\MetricColumnResolver;
use App\Services\Stats\MetricDisplay;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\RankingReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /api/v1/rankings?kind=geo&period=today&top_n=15
 *
 * kind ∈ { geo, buyers, lp1, lp2 } — mirrors the bot's /geo /buyers /lps1 /lps2.
 *
 * Response carries the report in two parallel shapes:
 *
 *   - `html`    — pre-formatted Telegram HTML (same string the bot sends to
 *                 chat). Kept for callers that want to render verbatim.
 *   - `columns` + `rows` — structured data the Mini App table view sorts /
 *                 re-formats client-side. Every cell carries both `raw`
 *                 (numeric, used for sorting) and `formatted` (the same string
 *                 the bot would render, via MetricDisplay::format).
 *
 * The Mini App used to cap at 4 metric columns to keep the Telegram-HTML row
 * narrow on a phone. The structured table view handles wider sets via
 * horizontal scroll, so the cap is gone — the controller honours the full
 * preset the user picked under Settings → "geo/buyers/lp1/lp2".
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

            $context = match ($data['kind']) {
                'lp1' => MetricColumnResolver::LP1,
                'lp2' => MetricColumnResolver::LP2,
                'buyers' => MetricColumnResolver::BUYERS,
                default => MetricColumnResolver::GEO,
            };
            $names = $user->metricNamesFor($context);
            $labels = $user->metricLabelOverrides();
            $topN = $data['top_n'] ?? 15;

            // One pivot query — both the html and the structured rows are
            // built from the same in-memory result.
            $collected = $reporter->collectData(
                $data['kind'],
                $window,
                $topN,
                metricNames: $names,
            );
            $html = $reporter->formatCollected($collected, $window, $names, $labels);

            $rows = [];
            foreach ($collected['entries'] as $entry) {
                $cells = [];
                foreach ($names as $name) {
                    $raw = $entry['metrics'][$name] ?? null;
                    $cells[$name] = [
                        'raw' => $raw,
                        'formatted' => MetricDisplay::format($name, $raw),
                    ];
                }
                $rows[] = [
                    'label' => $entry['label'],
                    'metrics' => $cells,
                ];
            }

            return response()->json([
                'kind' => $data['kind'],
                'window' => [
                    'from' => $window['from']->format(DATE_ATOM),
                    'to' => $window['to']->format(DATE_ATOM),
                    'label' => $window['label'],
                    'timezone' => $window['timezone'],
                ],
                'title' => $collected['dim']['title'],
                'header' => $collected['dim']['header'],
                'html' => $html,
                'columns' => MetricColumnResolver::columnsFromNames($names, $labels),
                'rows' => $rows,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
