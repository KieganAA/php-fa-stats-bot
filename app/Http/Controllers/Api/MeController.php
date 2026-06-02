<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AppContext;
use App\Services\Stats\MetricColumnResolver;
use App\Services\Stats\MetricDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController
{
    public function show(AppContext $ctx, MetricColumnResolver $resolver): JsonResponse
    {
        $user = $ctx->user();
        if ($user === null) {
            return response()->json(['error' => 'no user'], 401);
        }

        // Per-context presets — what the user currently has effective in each
        // context, plus the "did they customize this one?" flag. The Mini App
        // uses these to render the per-tab picker.
        $presets = [];
        foreach (MetricColumnResolver::ALL as $context) {
            $presets[$context] = [
                'names' => $resolver->namesFor($user, $context),
                'columns' => $resolver->columnsFor($user, $context),
                'customized' => $resolver->hasCustomPreset($user, $context),
                'defaults' => MetricColumnResolver::defaultNames($context),
            ];
        }

        // Stats context is the "main" one — keep these top-level for backwards
        // compatibility with the existing Mini App Settings view until the new
        // per-context UI ships.
        $statsNames = $presets[MetricColumnResolver::STATS]['names'];

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
            // Legacy top-level keys (stats context).
            'metrics' => $presets[MetricColumnResolver::STATS]['columns'],
            'metrics_customized' => $presets[MetricColumnResolver::STATS]['customized'],
            // Phase S — per-context picks.
            'metric_presets' => $presets,
            'metric_labels' => $user->metricLabelOverrides(),
            'contexts' => MetricColumnResolver::ALL,
        ]);
    }

    public function update(Request $request, AppContext $ctx, MetricColumnResolver $resolver): JsonResponse
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

        return $this->show($ctx, $resolver);
    }

    /**
     * PUT /api/v1/me/metrics  {metrics: ["Q Visits", "Real Approve", …]}
     *
     * Legacy single-key endpoint. Sets the STATS-context preset (and only
     * stats); other contexts continue to use defaults/their own settings.
     * Empty list / null wipes the stats preset (back to defaults).
     */
    public function setMetrics(Request $request, AppContext $ctx, MetricColumnResolver $resolver): JsonResponse
    {
        $user = $ctx->userOrFail();

        $data = $request->validate([
            'metrics' => 'present|nullable|array|max:30',
            'metrics.*' => 'string|max:128',
        ]);

        $settings = is_array($user->settings) ? $user->settings : [];
        $presets = (array) ($settings['metric_presets'] ?? []);

        if ($data['metrics'] === null || $data['metrics'] === []) {
            unset($presets[MetricColumnResolver::STATS]);
            // Also clear legacy single-key so /api/v1/me reports back "not customized".
            unset($settings['metrics']);
        } else {
            $presets[MetricColumnResolver::STATS] = array_values(array_unique(array_map('trim', $data['metrics'])));
            unset($settings['metrics']); // legacy key superseded
        }

        if ($presets === []) {
            unset($settings['metric_presets']);
        } else {
            $settings['metric_presets'] = $presets;
        }
        $user->settings = $settings;
        $user->save();

        return $this->show($ctx, $resolver);
    }

    /**
     * PUT /api/v1/me/metrics/{context}  {metrics: ["Q Visits", …]}
     *
     * Replace the picked metric list for a single context. Null / empty list
     * means "use defaults for this context" — that's what the per-tab reset
     * button calls.
     */
    public function setContextMetrics(Request $request, string $context, AppContext $ctx, MetricColumnResolver $resolver): JsonResponse
    {
        if (! in_array($context, MetricColumnResolver::ALL, true)) {
            return response()->json(['error' => "Unknown context: {$context}"], 422);
        }

        $user = $ctx->userOrFail();

        $data = $request->validate([
            'metrics' => 'present|nullable|array|max:30',
            'metrics.*' => 'string|max:128',
        ]);

        $settings = is_array($user->settings) ? $user->settings : [];
        $presets = (array) ($settings['metric_presets'] ?? []);

        if ($data['metrics'] === null || $data['metrics'] === []) {
            unset($presets[$context]);
        } else {
            $presets[$context] = array_values(array_unique(array_map('trim', $data['metrics'])));
        }

        if ($presets === []) {
            unset($settings['metric_presets']);
        } else {
            $settings['metric_presets'] = $presets;
        }
        // If we just touched stats, clear the legacy key to avoid drift.
        if ($context === MetricColumnResolver::STATS) {
            unset($settings['metrics']);
        }
        $user->settings = $settings;
        $user->save();

        return $this->show($ctx, $resolver);
    }

    /**
     * PUT /api/v1/me/metric-labels  {labels: {"Q Visits": "Quals", "Real Approve": "CR"}}
     *
     * Replace the per-name label overrides wholesale. Sending an empty object
     * (or null) clears them — display falls back to MetricDisplay::label().
     */
    public function setMetricLabels(Request $request, AppContext $ctx, MetricColumnResolver $resolver): JsonResponse
    {
        $user = $ctx->userOrFail();

        $data = $request->validate([
            'labels' => 'present|nullable|array',
            'labels.*' => 'nullable|string|max:32',
        ]);

        $settings = is_array($user->settings) ? $user->settings : [];

        $clean = [];
        foreach ((array) ($data['labels'] ?? []) as $name => $label) {
            if (! is_string($name) || ! is_string($label)) {
                continue;
            }
            $name = trim($name);
            $label = trim($label);
            if ($name === '' || $label === '') {
                continue;
            }
            // Don't store a redundant override that matches the built-in.
            if ($label === MetricDisplay::label($name)) {
                continue;
            }
            $clean[$name] = $label;
        }

        if ($clean === []) {
            unset($settings['metric_labels']);
        } else {
            $settings['metric_labels'] = $clean;
        }
        $user->settings = $settings;
        $user->save();

        return $this->show($ctx, $resolver);
    }
}
