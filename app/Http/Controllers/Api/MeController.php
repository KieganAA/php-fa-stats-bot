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
            // Never expose the plaintext API key over the wire. The hint
            // ("…abcd") proves it's set and lets the UI distinguish "configured"
            // from "empty" without giving an exfil surface.
            'anthropic_key_hint' => $user->anthropicKeyHint(),
            'anthropic_model' => $user->anthropic_model,
            'env_anthropic_model' => (string) config('services.anthropic.model', ''),
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
            // Anthropic keys are usually 80+ chars starting with `sk-ant-`. We
            // intentionally don't enforce the prefix in case Anthropic changes
            // it — but we cap the length to avoid storing absurd payloads.
            'anthropic_api_key' => 'sometimes|nullable|string|max:255',
            'anthropic_model' => 'sometimes|nullable|string|max:128',
        ]);

        // Empty string == clear the key (UI sends empty after the user blanks
        // the field). Anything else is the new ciphertext via the encrypted cast.
        if (array_key_exists('anthropic_api_key', $data) && $data['anthropic_api_key'] === '') {
            $data['anthropic_api_key'] = null;
        }
        if (array_key_exists('anthropic_model', $data) && $data['anthropic_model'] === '') {
            $data['anthropic_model'] = null;
        }

        $user->fill($data)->save();

        return $this->show($ctx);
    }
}
