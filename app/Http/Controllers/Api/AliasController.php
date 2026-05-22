<?php

namespace App\Http\Controllers\Api;

use App\Models\LandingAlias;
use App\Services\Auth\AppContext;
use App\Services\Stats\AliasResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AliasController
{
    public function index(AliasResolver $resolver): JsonResponse
    {
        $aliases = $resolver->listAll()->map(fn (LandingAlias $a) => [
            'id' => $a->id,
            'alias' => $a->alias,
            'landing_uuid' => $a->landing_uuid,
            'landing_name' => $a->landing?->name,
            'position' => $a->position,
            'notes' => $a->notes,
            'created_by_id' => $a->created_by_id,
        ])->values();

        return response()->json(['aliases' => $aliases]);
    }

    public function store(Request $request, AliasResolver $resolver, AppContext $ctx): JsonResponse
    {
        $data = $request->validate([
            'alias' => 'required|string|max:64',
            'token' => 'required|string',
            'position' => 'sometimes|integer|min:1|max:9',
            'notes' => 'sometimes|nullable|string',
        ]);

        $resolved = $resolver->resolve($data['token']);

        $alias = LandingAlias::query()->updateOrCreate(
            ['alias' => $data['alias']],
            [
                'landing_uuid' => $resolved['landing']->uuid,
                'position' => $data['position'] ?? 1,
                'created_by_id' => $ctx->user()?->id,
                'notes' => $data['notes'] ?? null,
            ],
        );

        return response()->json([
            'alias' => $alias->fresh(),
            'landing_name' => $resolved['landing']->name,
        ], 201);
    }

    public function destroy(LandingAlias $alias): JsonResponse
    {
        $alias->delete();

        return response()->json(['ok' => true]);
    }
}
