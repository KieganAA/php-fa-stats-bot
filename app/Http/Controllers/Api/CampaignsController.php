<?php

namespace App\Http\Controllers\Api;

use App\Jobs\NotifyCampaignJob;
use App\Models\CampaignSubscription;
use App\Models\UserCompareGroup;
use App\Services\Auth\AppContext;
use App\Services\Campaign\CampaignSubscriptionService;
use App\Services\Campaign\CampaignTokenResolver;
use App\Services\Campaign\Dto\ResyncResult;
use App\Services\Campaign\Exceptions\EmptyCampaignException;
use App\Services\Tracking\CompareGroupUnbinder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Campaign-subscription management — the heart of both the Mini App and the
 * extension popup. A subscription owns N child compare/MVT groups; this
 * controller lists them, (re)subscribes, tweaks cadence/pause, resyncs the
 * structure and deletes.
 *
 * Shared wire format (served under /api/v1 for the Mini App and proxied under
 * /api/ext for the extension). Ownership is re-checked on every mutating call —
 * route-model binding doesn't scope to the current user.
 */
class CampaignsController
{
    public function index(AppContext $ctx): JsonResponse
    {
        $user = $ctx->userOrFail();
        $subs = CampaignSubscription::query()
            ->where('user_id', $user->id)
            ->with('children.members.trackedLanding.landing')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'campaigns' => $subs->map(fn (CampaignSubscription $s) => $this->serialize($s))->all(),
        ]);
    }

    /** Subscribe (or idempotently resync) by human_id / uuid. */
    public function store(
        Request $request,
        AppContext $ctx,
        CampaignTokenResolver $resolver,
        CampaignSubscriptionService $service,
    ): JsonResponse {
        $user = $ctx->userOrFail();
        $data = $request->validate(['campaign' => 'required|string|max:64']);

        try {
            $uuid = $resolver->resolve($data['campaign']);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        try {
            $result = $service->create($user, $uuid);
        } catch (EmptyCampaignException $e) {
            // Nothing to subscribe to — a clear 422, not an upstream error.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'Не смог разобрать кампанию в AIO: '.$e->getMessage(),
            ], 502);
        }

        $sub = CampaignSubscription::query()
            ->where('user_id', $user->id)
            ->where('campaign_uuid', $uuid)
            ->with('children.members.trackedLanding.landing')
            ->firstOrFail();

        return response()->json([
            'ok' => true,
            'campaign' => $this->serialize($sub),
            'changed' => $this->changed($result),
            // Per-step analyzer breakdown ("шаг 1: 3 ленда → сплит; шаг 2: 1
            // ленд (+2 дубл., 3 выкл.)") — surfaces WHY the split/MVT counts
            // are what they are, e.g. disabled toggles or weight duplicates.
            'steps' => $result->structure?->describeSteps() ?? [],
        ]);
    }

    /** Change schedule (interval / daily-at) or pause. Propagates to children. */
    public function update(Request $request, AppContext $ctx, CampaignSubscription $campaign): JsonResponse
    {
        if (! $this->owns($ctx, $campaign)) {
            return response()->json(['error' => 'not yours'], 403);
        }

        $data = $request->validate([
            'paused' => 'sometimes|boolean',
            'notify_interval_minutes' => sprintf(
                'sometimes|integer|min:%d|max:%d',
                UserCompareGroup::INTERVAL_MIN,
                UserCompareGroup::INTERVAL_MAX,
            ),
            'schedule_type' => 'sometimes|in:interval,daily',
            'daily_at' => ['sometimes', 'nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
        ]);

        if (array_key_exists('paused', $data)) {
            $campaign->paused_at = $data['paused'] ? CarbonImmutable::now() : null;
        }

        // The schedule lives on the children (that's what the push tick reads),
        // so every schedule field change is copied down to them.
        $childPatch = [];
        if (array_key_exists('notify_interval_minutes', $data)) {
            $campaign->notify_interval_minutes = (int) $data['notify_interval_minutes'];
            $childPatch['notify_interval_minutes'] = $campaign->notify_interval_minutes;
        }
        if (array_key_exists('schedule_type', $data)) {
            $campaign->schedule_type = $data['schedule_type'];
            $childPatch['schedule_type'] = $data['schedule_type'];
        }
        if (array_key_exists('daily_at', $data)) {
            // Normalise "9:30" → "09:30" so string comparisons stay sane.
            $dailyAt = $data['daily_at'] !== null
                ? sprintf('%02d:%02d', ...array_map('intval', explode(':', $data['daily_at'])))
                : null;
            $campaign->daily_at = $dailyAt;
            $childPatch['daily_at'] = $dailyAt;
        }
        if ($childPatch !== []) {
            $campaign->children()->update($childPatch);
        }
        $campaign->save();

        return response()->json([
            'campaign' => $this->serialize($campaign->fresh(['children.members.trackedLanding.landing'])),
        ]);
    }

    /** Re-derive the campaign's structure (new/vanished splits & MVTs). */
    public function resync(AppContext $ctx, CampaignSubscriptionService $service, CampaignSubscription $campaign): JsonResponse
    {
        if (! $this->owns($ctx, $campaign)) {
            return response()->json(['error' => 'not yours'], 403);
        }

        try {
            $result = $service->resync($campaign);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'AIO: '.$e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'campaign' => $this->serialize($campaign->fresh(['children.members.trackedLanding.landing'])),
            'changed' => $this->changed($result),
            'steps' => $result->structure?->describeSteps() ?? [],
        ]);
    }

    /**
     * Debug helper: fire the campaign's digest right now, skipping the
     * schedule. Dispatches the same NotifyCampaignJob the cron tick uses —
     * one message with a section per active split/MVT — so what arrives in
     * the chat is exactly what a scheduled push would send. Updates
     * last_notified_at as a side effect, which simply restarts the schedule.
     */
    public function push(AppContext $ctx, CampaignSubscription $campaign): JsonResponse
    {
        if (! $this->owns($ctx, $campaign)) {
            return response()->json(['error' => 'not yours'], 403);
        }
        if ($campaign->paused_at !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'Кампания на паузе — пуши заблокированы. Сними паузу (▶️) и попробуй снова.',
            ], 422);
        }

        $children = $campaign->children()->whereNull('orphaned_at')->get();
        if ($children->isEmpty()) {
            return response()->json([
                'ok' => false,
                'error' => 'Нет активных сплитов/MVT — нечего пушить.',
            ], 422);
        }

        NotifyCampaignJob::dispatch((int) $campaign->user_id, (int) $campaign->id);

        return response()->json([
            'ok' => true,
            'dispatched' => 1,
            'sections' => $children->count(),
            'children' => $children->pluck('name')->values()->all(),
        ]);
    }

    /** Delete the subscription and unbind every child (pausing orphan landings). */
    public function destroy(AppContext $ctx, CompareGroupUnbinder $unbinder, CampaignSubscription $campaign): JsonResponse
    {
        if (! $this->owns($ctx, $campaign)) {
            return response()->json(['error' => 'not yours'], 403);
        }

        foreach ($campaign->children()->get() as $child) {
            $unbinder->unbind($child);
        }
        $campaign->delete();

        return response()->json(['ok' => true]);
    }

    private function owns(AppContext $ctx, CampaignSubscription $campaign): bool
    {
        return $campaign->user_id === $ctx->userOrFail()->id;
    }

    /** @return array{created:int, reactivated:int, updated:int, orphaned:int} */
    private function changed(ResyncResult $result): array
    {
        return [
            'created' => count($result->created),
            'reactivated' => count($result->reactivated),
            'updated' => count($result->updated),
            'orphaned' => count($result->orphaned),
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(CampaignSubscription $sub): array
    {
        $children = $sub->children; // eager-loaded
        $active = $children->whereNull('orphaned_at');
        $orphans = $children->whereNotNull('orphaned_at');
        $interval = (int) ($sub->notify_interval_minutes ?? CampaignSubscription::DEFAULT_INTERVAL_MINUTES);

        // Earliest upcoming push across active, non-paused children.
        $isDaily = ($sub->schedule_type ?? UserCompareGroup::SCHEDULE_INTERVAL) === UserCompareGroup::SCHEDULE_DAILY
            && $sub->daily_at !== null;
        $nextPush = null;
        if ($sub->paused_at === null && $isDaily) {
            // Next daily slot in the user's timezone: today if it hasn't fired
            // yet, otherwise tomorrow.
            $tz = $sub->user?->timezone ?: config('app.timezone', 'UTC');
            [$h, $m] = array_map('intval', explode(':', $sub->daily_at) + [0, 0]);
            $local = CarbonImmutable::now($tz);
            $slot = $local->startOfDay()->setTime($h, $m);
            $fired = $active->contains(fn ($c) => $c->last_notified_at !== null
                && $c->last_notified_at->copy()->setTimezone($tz) >= $slot);
            // fired today → tomorrow; slot still ahead → today; slot passed
            // but not fired yet → the next cron tick (≈now).
            $nextPush = $fired ? $slot->addDay() : ($local < $slot ? $slot : $local);
        } elseif ($sub->paused_at === null) {
            foreach ($active as $child) {
                $last = $child->last_notified_at;
                $candidate = $last === null ? CarbonImmutable::now() : $last->copy()->addMinutes($interval);
                if ($nextPush === null || $candidate < $nextPush) {
                    $nextPush = $candidate;
                }
            }
        }

        return [
            'id' => $sub->id,
            'campaign_uuid' => $sub->campaign_uuid,
            'human_id' => $sub->campaign_human_id,
            'name' => $sub->campaign_name,
            'label' => $sub->shortLabel(),
            'countries' => is_array($sub->countries) ? $sub->countries : [],
            'paused' => $sub->paused_at !== null,
            'notify_interval_minutes' => $interval,
            'schedule_type' => $sub->schedule_type ?? UserCompareGroup::SCHEDULE_INTERVAL,
            'daily_at' => $sub->daily_at,
            'last_synced_at' => $sub->last_synced_at?->toIso8601String(),
            'next_push_at' => $nextPush?->toIso8601String(),
            'splits' => $active->where('mode', UserCompareGroup::MODE_COMPARE)->count(),
            'mvts' => $active->where('mode', UserCompareGroup::MODE_MVT)->count(),
            'orphans' => $orphans->count(),
            'children' => $children->map(fn (UserCompareGroup $c) => $this->serializeChild($c))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeChild(UserCompareGroup $child): array
    {
        return [
            'id' => $child->id,
            'name' => $child->name,
            'mode' => $child->mode,
            'step' => $child->step_position,
            'orphaned' => $child->orphaned_at !== null,
            'landings' => $child->members->map(function ($m) use ($child) {
                $landing = $m->trackedLanding?->landing;

                return [
                    'human_id' => $landing?->human_id,
                    'uuid' => $landing?->uuid ?? $m->trackedLanding?->landing_uuid,
                    'name' => $landing?->name,
                    'country' => $landing?->countries[0] ?? null,
                    // For MVT children: which fields are being tested and their
                    // variants (stored on the catalog row at subscribe/resync
                    // time). Only fields with 2+ variants — single-variant
                    // slots are just defaults, not a test.
                    'mvt_fields' => $child->mode === UserCompareGroup::MODE_MVT
                        ? collect($landing?->mvt_settings ?? [])
                            ->filter(fn ($f) => is_array($f['variants'] ?? null) && count($f['variants']) >= 2)
                            ->map(fn ($f) => ['key' => $f['key'] ?? '?', 'variants' => array_values($f['variants'])])
                            ->values()->all()
                        : [],
                ];
            })->values()->all(),
        ];
    }
}
