<?php

namespace App\Services\Stats;

use App\Models\Aio\Landing;
use App\Models\LandingAlias;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Resolves user-facing alias strings ("dk-blue") to landing identifiers,
 * and accepts numeric human_ids / raw uuids as a fallback so /stats and
 * /compare work even before an alias has been created.
 */
class AliasResolver
{
    /**
     * @return array{alias: ?LandingAlias, landing: Landing}
     */
    public function resolve(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Empty alias.');
        }

        $alias = LandingAlias::query()->where('alias', $token)->first();
        if ($alias !== null) {
            $landing = Landing::query()->where('uuid', $alias->landing_uuid)->first();
            if ($landing === null) {
                throw new RuntimeException("Alias '{$token}' points to a landing that is no longer in aio_landings.");
            }

            return ['alias' => $alias, 'landing' => $landing];
        }

        if (ctype_digit($token)) {
            $landing = Landing::query()->where('human_id', (int) $token)->first();
            if ($landing !== null) {
                return ['alias' => null, 'landing' => $landing];
            }
        }

        if ($this->looksLikeUuid($token)) {
            $landing = Landing::query()->where('uuid', $token)->first();
            if ($landing !== null) {
                return ['alias' => null, 'landing' => $landing];
            }
        }

        throw new RuntimeException("Unknown alias / id: '{$token}'.");
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array{alias: ?LandingAlias, landing: Landing, token: string}>
     */
    public function resolveAll(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $resolved = $this->resolve($token);
            $out[] = ['alias' => $resolved['alias'], 'landing' => $resolved['landing'], 'token' => $token];
        }

        return $out;
    }

    /** @return Collection<int, LandingAlias> */
    public function listAll(): Collection
    {
        return LandingAlias::query()->with('landing')->orderBy('alias')->get();
    }

    private function looksLikeUuid(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token);
    }
}
