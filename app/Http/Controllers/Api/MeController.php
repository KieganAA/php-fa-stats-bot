<?php

namespace App\Http\Controllers\Api;

use App\Services\Auth\AppContext;
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

        return response()->json([
            'id' => $user->id,
            'telegram_user_id' => $user->telegram_user_id,
            'username' => $user->telegram_username,
            'display_name' => $user->displayName(),
            'timezone' => $user->timezone,
            'default_period' => $user->default_period,
            'default_position' => $user->default_position,
            'settings' => $user->settings ?? [],
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
        ]);

        $user->fill($data)->save();

        return $this->show($ctx);
    }
}
