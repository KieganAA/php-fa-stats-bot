<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AppContext;
use App\Services\Stats\MetricDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController
{
    public function show(AppContext $ctx): JsonResponse
    {
        $user = $ctx->user();
        if ($user === null) {
            return response()->json(['error' => 'no user'], 401);
        }

        $metricNames = $user->metricPreferences();

        return response()->json([
            'id' => $user->id,
            'telegram_user_id' => $user->telegram_user_id,
            'username' => $user->telegram_username,
            'display_name' => $user->displayName(),
            'timezone' => $user->timezone,
            'default_period' => $user->default_period,
            'default_position' => $user->default_position,
            'settings' => $user->settings ?? [],
            // Never expose the plaintext API key over the wire. The hint
            // ("…abcd") proves it's set without giving an exfil surface.
            'anthropic_key_hint' => $user->anthropicKeyHint(),
            'anthropic_model' => $user->anthropic_model,
            'env_anthropic_model' => (string) config('services.anthropic.model', ''),
            // Metric prefs — effective list (after default-fallback) plus a
            // flag so the UI can tell "explicitly empty" from "using defaults".
            'metrics' => MetricDisplay::describe($metricNames),
            'metrics_customized' => $user->hasCustomMetricPreferences(),
        ]);
    }

    public function update(Request $request, AppContext $ctx): JsonResponse
    {
        $user = $ctx->userOrFail();

        $data = $request->validate([
            'timezone' => 'sometimes|string|max:64',
            'default_period' => 'sometimes|string|max:32',
            'default_position' => 'sometimes|integer|min:1|max:9',
            'settings' => 'sometimes|array',
            'anthropic_api_key' => 'sometimes|nullable|string|max:255',
            'anthropic_model' => 'sometimes|nullable|string|max:128',
        ]);

        // Empty key = clear (UI sends "" when user blanks the field).
        if (array_key_exists('anthropic_api_key', $data) && $data['anthropic_api_key'] === '') {
            $data['anthropic_api_key'] = null;
        }
        if (array_key_exists('anthropic_model', $data) && $data['anthropic_model'] === '') {
            $data['anthropic_model'] = null;
        }

        $user->fill($data)->save();

        return $this->show($ctx);
    }

    /**
     * PUT /api/v1/me/metrics  {metrics: ["Q Visits", "Real Approve", …]}
     *
     * Replaces the user's metric pick. Empty list or `null` means "use defaults"
     * — that's how the UI "reset" button works.
     */
    public function setMetrics(Request $request, AppContext $ctx): JsonResponse
    {
        $user = $ctx->userOrFail();

        $data = $request->validate([
            'metrics' => 'present|nullable|array|max:30',
            'metrics.*' => 'string|max:128',
        ]);

        $settings = is_array($user->settings) ? $user->settings : [];
        if ($data['metrics'] === null || $data['metrics'] === []) {
            unset($settings['metrics']);
        } else {
            $settings['metrics'] = array_values(array_unique(array_map('trim', $data['metrics'])));
        }
        $user->settings = $settings;
        $user->save();

        return $this->show($ctx);
    }
}
