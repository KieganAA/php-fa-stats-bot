<?php

namespace App\Http\Controllers\Api;

use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Models\UserLandingBinding;
use App\Services\Auth\AppContext;
use App\Services\Stats\AliasResolver;
use App\Services\Tracking\LandingBinder;
use App\Services\Tracking\LandingSnapshotComparer;
use App\Services\Tracking\LandingUnbinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BindingController
{
    public function index(AppContext $ctx): JsonResponse
    {
        $user = $ctx->userOrFail();

        $bindings = UserLandingBinding::query()
            ->where('user_id', $user->id)
            ->with('trackedLanding.landing')
            ->get()
            ->map(fn (UserLandingBinding $b) => [
                'id' => $b->id,
                'tracked_landing_id' => $b->tracked_landing_id,
                'landing_uuid' => $b->trackedLanding?->landing_uuid,
                'landing_name' => $b->trackedLanding?->landing?->name,
                'position' => $b->trackedLanding?->position,
                'notify_3h' => $b->notify_3h,
                'notify_since_start' => $b->notify_since_start,
                'notes' => $b->notes,
                'paused' => $b->trackedLanding?->paused_at !== null,
            ])
            ->values();

        return response()->json(['bindings' => $bindings]);
    }

    public function store(
        Request $request,
        AppContext $ctx,
        AliasResolver $resolver,
        LandingBinder $binder,
    ): JsonResponse {
        $data = $request->validate([
            'alias' => 'required|string',
            'notify_3h' => 'sometimes|boolean',
            'notify_since_start' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string',
        ]);

        $resolved = $resolver->resolve($data['alias']);
        $position = $resolved['alias']?->position ?? 1;

        $binding = $binder->bind(
            user: $ctx->userOrFail(),
            landingUuid: $resolved['landing']->uuid,
            position: $position,
            notify3h: (bool) ($data['notify_3h'] ?? true),
            notifySinceStart: (bool) ($data['notify_since_start'] ?? false),
            notes: $data['notes'] ?? null,
        );

        return response()->json(['binding' => $binding->load('trackedLanding.landing')], 201);
    }

    public function update(Request $request, AppContext $ctx, UserLandingBinding $binding): JsonResponse
    {
        $user = $ctx->userOrFail();
        if ($binding->user_id !== $user->id) {
            return response()->json(['error' => 'not yours'], 403);
        }

        $data = $request->validate([
            'notify_3h' => 'sometimes|boolean',
            'notify_since_start' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string',
        ]);
        $binding->fill($data)->save();

        return response()->json(['binding' => $binding->fresh()]);
    }

    public function destroy(AppContext $ctx, LandingUnbinder $unbinder, UserLandingBinding $binding): JsonResponse
    {
        $user = $ctx->userOrFail();
        if ($binding->user_id !== $user->id) {
            return response()->json(['error' => 'not yours'], 403);
        }

        $tracked = $binding->trackedLanding;
        if ($tracked === null) {
            $binding->delete();

            return response()->json(['ok' => true]);
        }
        $unbinder->unbind($user, $tracked);

        return response()->json(['ok' => true]);
    }

    /** GET /api/v1/bindings/{binding}/latest — last 3h snapshot diff. */
    public function latest(
        AppContext $ctx,
        UserLandingBinding $binding,
        LandingSnapshotComparer $comparer,
    ): JsonResponse {
        $user = $ctx->userOrFail();
        if ($binding->user_id !== $user->id) {
            return response()->json(['error' => 'not yours'], 403);
        }

        $tracked = $binding->trackedLanding;
        if ($tracked === null) {
            return response()->json(['error' => 'no tracked landing'], 404);
        }

        $latest = LandingSnapshot::query()
            ->where('tracked_landing_id', $tracked->id)
            ->where('kind', LandingSnapshot::KIND_3H)
            ->orderByDesc('window_end')
            ->first();

        if ($latest === null) {
            return response()->json(['snapshot' => null]);
        }

        $comparison = $comparer->compare($latest);

        return response()->json([
            'snapshot' => [
                'kind' => $latest->kind,
                'window_start' => $latest->window_start->toIso8601String(),
                'window_end' => $latest->window_end->toIso8601String(),
                'captured_at' => $latest->captured_at->toIso8601String(),
                'metrics' => $latest->metrics,
            ],
            'prior' => $comparison['prior'] ? [
                'window_start' => $comparison['prior']->window_start->toIso8601String(),
                'window_end' => $comparison['prior']->window_end->toIso8601String(),
                'metrics' => $comparison['prior']->metrics,
            ] : null,
            'delta' => $comparison['delta'],
        ]);
    }
}
