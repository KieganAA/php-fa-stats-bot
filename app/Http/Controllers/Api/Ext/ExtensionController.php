<?php

namespace App\Http\Controllers\Api\Ext;

use App\Http\Controllers\Api\CampaignsController;
use App\Http\Controllers\Api\GroupsController;
use App\Http\Controllers\Api\LandingsController;
use App\Models\CampaignSubscription;
use App\Models\UserCompareGroup;
use App\Services\Auth\AppContext;
use App\Services\Campaign\CampaignSubscriptionService;
use App\Services\Campaign\CampaignTokenResolver;
use App\Services\Stats\LandingFormatter;
use App\Services\Tracking\CompareGroupUnbinder;
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

    // ===== Campaign subscriptions =====
    // All delegate to the canonical CampaignsController so the extension and the
    // Mini App speak the exact same wire format.

    /** GET /api/ext/campaigns — the user's campaign subscriptions + children. */
    public function campaigns(AppContext $ctx, CampaignsController $delegate): JsonResponse
    {
        return $delegate->index($ctx);
    }

    /**
     * POST /api/ext/campaign — the extension's headline action. The content
     * script grabs a campaign human_id (or uuid) off an AIO page and posts it;
     * the backend derives the splits + MVT landings and wires up the child
     * subscriptions. Idempotent: re-posting the same campaign is a resync.
     */
    public function subscribeCampaign(
        Request $request,
        AppContext $ctx,
        CampaignsController $delegate,
        CampaignTokenResolver $resolver,
        CampaignSubscriptionService $service,
    ): JsonResponse {
        return $delegate->store($request, $ctx, $resolver, $service);
    }

    /** PATCH /api/ext/campaigns/{campaign} — cadence / pause. */
    public function updateCampaign(Request $request, AppContext $ctx, CampaignsController $delegate, CampaignSubscription $campaign): JsonResponse
    {
        return $delegate->update($request, $ctx, $campaign);
    }

    /** POST /api/ext/campaigns/{campaign}/resync — re-derive structure. */
    public function resyncCampaign(AppContext $ctx, CampaignsController $delegate, CampaignSubscriptionService $service, CampaignSubscription $campaign): JsonResponse
    {
        return $delegate->resync($ctx, $service, $campaign);
    }

    /** DELETE /api/ext/campaigns/{campaign} — drop the subscription. */
    public function destroyCampaign(AppContext $ctx, CampaignsController $delegate, CompareGroupUnbinder $unbinder, CampaignSubscription $campaign): JsonResponse
    {
        return $delegate->destroy($ctx, $unbinder, $campaign);
    }
}
