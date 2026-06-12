<?php

namespace App\Http\Controllers\Api;

use App\Jobs\NotifyCompareGroupJob;
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
        ]);
    }

    /** Change cadence / pause. Interval propagates to the children. */
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
        ]);

        if (array_key_exists('paused', $data)) {
            $campaign->paused_at = $data['paused'] ? CarbonImmutable::now() : null;
        }
        if (array_key_exists('notify_interval_minutes', $data)) {
            $interval = (int) $data['notify_interval_minutes'];
            $campaign->notify_interval_minutes = $interval;
            // Cadence lives on the children (that's what the push tick reads),
            // so push it down to every child of this campaign.
            $campaign->children()->update(['notify_interval_minutes' => $interval]);
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
        ]);
    }

    /**
     * Debug helper: fire the campaign's notifications right now, skipping the
     * interval schedule. Dispatches the same NotifyCompareGroupJob the cron
     * tick uses, one per active (non-orphaned) child — so what arrives in the
     * chat is exactly what a scheduled push would send. Updates
     * last_notified_at as a side effect, which simply restarts the interval.
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

        foreach ($children as $child) {
            NotifyCompareGroupJob::dispatch((int) $campaign->user_id, (int) $child->id);
        }

        return response()->json([
            'ok' => true,
            'dispatched' => $children->count(),
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
        $nextPush = null;
        if ($sub->paused_at === null) {
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
            'landings' => $child->members->map(function ($m) {
                $landing = $m->trackedLanding?->landing;

                return [
                    'human_id' => $landing?->human_id,
                    'uuid' => $landing?->uuid ?? $m->trackedLanding?->landing_uuid,
                    'name' => $landing?->name,
                    'country' => $landing?->countries[0] ?? null,
                ];
            })->values()->all(),
        ];
    }
}
