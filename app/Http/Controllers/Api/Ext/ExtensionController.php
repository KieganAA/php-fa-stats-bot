<?php

namespace App\Http\Controllers\Api\Ext;

use App\Http\Controllers\Api\GroupsController;
use App\Http\Controllers\Api\LandingsController;
use App\Models\UserCompareGroup;
use App\Services\Auth\AppContext;
use App\Services\Stats\LandingFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chrome extension API. Lives under /api/ext/ and is gated by Bearer-token
 * auth (VerifyExtensionToken). Methods that map 1:1 onto the Mini App API
 * just delegate — keeps the contract identical so any change to subscription
 * shape is reflected in both surfaces.
 */
class ExtensionController
{
    public function __construct(
        private readonly LandingFormatter $landingFmt,
        private readonly AppContext $ctx,
    ) {}

    /**
     * GET /api/ext/me
     *
     * Lightweight identity probe — the popup uses this to confirm the saved
     * token is still valid + show the user who they're logged in as.
     */
    public function me(AppContext $ctx): JsonResponse
    {
        $user = $ctx->userOrFail();

        return response()->json([
            'id' => $user->id,
            'telegram_user_id' => $user->telegram_user_id,
            'username' => $user->telegram_username,
            'display_name' => $user->displayName(),
            'timezone' => $user->timezone,
        ]);
    }

    /** GET /api/ext/groups — delegate to the Mini App controller. */
    public function groups(AppContext $ctx, GroupsController $delegate): JsonResponse
    {
        return $delegate->index($ctx);
    }

    /** POST /api/ext/groups — same wire format as Mini App. */
    public function createGroup(
        Request $request,
        AppContext $ctx,
        GroupsController $delegate,
        \App\Services\Tracking\CompareGroupBinder $binder,
    ): JsonResponse {
        return $delegate->store($request, $ctx, $binder);
    }

    /** PATCH /api/ext/groups/{group} */
    public function updateGroup(Request $request, AppContext $ctx, GroupsController $delegate, UserCompareGroup $group): JsonResponse
    {
        return $delegate->update($request, $ctx, $group);
    }

    /** DELETE /api/ext/groups/{group} */
    public function destroyGroup(
        AppContext $ctx,
        GroupsController $delegate,
        \App\Services\Tracking\CompareGroupUnbinder $unbinder,
        UserCompareGroup $group,
    ): JsonResponse {
        return $delegate->destroy($ctx, $unbinder, $group);
    }

    /** GET /api/ext/landings?q=... — same controller as Mini App. */
    public function landings(Request $request, AppContext $ctx, LandingsController $delegate): JsonResponse
    {
        return $delegate->index($request, $ctx, $this->landingFmt);
    }

    /**
     * POST /api/ext/resolve
     *
     * Bulk-resolve human_ids / UUIDs the content script scraped from a page
     * — returns which ones actually exist in our local aio_landings table.
     * Used by the popup to show "found 3 of 5 lands you selected".
     */
    public function resolve(Request $request, AppContext $ctx, LandingsController $delegate): JsonResponse
    {
        $ctx->userOrFail();
        $data = $request->validate([
            'tokens' => 'required|array|min:1|max:100',
            'tokens.*' => 'string|max:64',
        ]);

        $opts = $this->ctx->user()?->landingDisplayOpts() ?? [];
        $resolved = [];
        $missing = [];
        foreach ($data['tokens'] as $token) {
            $token = trim((string) $token);
            $landing = null;
            if (ctype_digit($token)) {
                $landing = \App\Models\Aio\Landing::query()->where('human_id', (int) $token)->first();
            } elseif (preg_match('/^[0-9a-f-]{36}$/i', $token)) {
                $landing = \App\Models\Aio\Landing::query()->where('uuid', $token)->first();
            }
            if ($landing === null) {
                $missing[] = $token;

                continue;
            }
            $resolved[] = [
                'token' => $token,
                'uuid' => $landing->uuid,
                'human_id' => $landing->human_id,
                'name' => $landing->name,
                'type' => $landing->landing_type_name,
                'country' => $landing->countries[0] ?? null,
                'label' => $this->landingFmt->line($landing, $opts),
            ];
        }

        return response()->json([
            'resolved' => $resolved,
            'missing' => $missing,
        ]);
    }
}
