<?php

namespace App\Http\Controllers\Api;

use App\Models\Aio\Landing;
use App\Models\UserCompareGroup;
use App\Services\Auth\AppContext;
use App\Services\Stats\LandingFormatter;
use App\Services\Tracking\CompareGroupBinder;
use App\Services\Tracking\CompareGroupUnbinder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tracking-group CRUD for the Mini App.
 *
 * Wire format:
 *   GET    /api/v1/groups            → list current user's groups + members
 *   POST   /api/v1/groups            → create / re-bind a group by name
 *   PATCH  /api/v1/groups/{group}    → toggle paused / rename
 *   DELETE /api/v1/groups/{group}    → unbind (drop + pause orphan tracked
 *                                       landings)
 */
class GroupsController
{
    public function __construct(
        private readonly LandingFormatter $landingFmt,
        private readonly \App\Services\Auth\AppContext $ctx,
    ) {}

    public function index(AppContext $ctx): JsonResponse
    {
        $user = $ctx->userOrFail();
        $groups = $user->compareGroups()
            ->with('members.trackedLanding.landing')
            ->orderBy('name')
            ->get();

        return response()->json([
            'groups' => $groups->map(fn ($g) => $this->serialize($g))->all(),
        ]);
    }

    public function store(Request $request, AppContext $ctx, CompareGroupBinder $binder): JsonResponse
    {
        $data = $request->validate([
            'primitives' => 'required|array|min:1',
            'primitives.*' => 'string|max:64',
            'name' => 'sometimes|nullable|string|max:64',
        ]);

        $landings = [];
        foreach ($data['primitives'] as $token) {
            $token = trim((string) $token);
            $landing = null;
            if (ctype_digit($token)) {
                $landing = Landing::query()->where('human_id', (int) $token)->first();
            } elseif (preg_match('/^[0-9a-f-]{36}$/i', $token)) {
                $landing = Landing::query()->where('uuid', $token)->first();
            }
            if ($landing === null) {
                return response()->json(['error' => "Лендинг «{$token}» не найден."], 422);
            }
            $landings[] = $landing;
        }

        $group = $binder->bind($ctx->userOrFail(), $landings, $data['name'] ?? null);

        return response()->json([
            'group' => $this->serialize($group->load('members.trackedLanding.landing')),
        ], 201);
    }

    public function update(Request $request, AppContext $ctx, UserCompareGroup $group): JsonResponse
    {
        $user = $ctx->userOrFail();
        if ($group->user_id !== $user->id) {
            return response()->json(['error' => 'not yours'], 403);
        }

        $data = $request->validate([
            'paused' => 'sometimes|boolean',
            'name' => 'sometimes|string|max:64',
        ]);

        if (array_key_exists('paused', $data)) {
            $group->paused_at = $data['paused'] ? now() : null;
        }
        if (array_key_exists('name', $data)) {
            $group->name = $data['name'];
        }
        $group->save();

        return response()->json([
            'group' => $this->serialize($group->fresh('members.trackedLanding.landing')),
        ]);
    }

    public function destroy(AppContext $ctx, CompareGroupUnbinder $unbinder, UserCompareGroup $group): JsonResponse
    {
        $user = $ctx->userOrFail();
        if ($group->user_id !== $user->id) {
            return response()->json(['error' => 'not yours'], 403);
        }
        $unbinder->unbind($group);

        return response()->json(['ok' => true]);
    }

    private function serialize(UserCompareGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'mode' => $group->mode,
            'paused' => $group->paused_at !== null,
            'last_notified_at' => $group->last_notified_at?->toIso8601String(),
            'members' => $group->members->map(function ($m) {
                $landing = $m->trackedLanding?->landing;
                if ($landing === null) {
                    return ['tracked_landing_id' => $m->tracked_landing_id, 'short_label' => null];
                }

                return [
                    'tracked_landing_id' => $m->tracked_landing_id,
                    'human_id' => $landing->human_id,
                    'uuid' => $landing->uuid,
                    'name' => $landing->name,
                    'type' => $landing->landing_type_name,
                    'country' => $landing->countries[0] ?? null,
                    'short_label' => $this->landingFmt->line($landing, $this->ctx->user()?->landingDisplayOpts() ?? []),
                ];
            })->values()->all(),
        ];
    }
}
