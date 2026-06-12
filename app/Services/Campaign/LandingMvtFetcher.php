<?php

namespace App\Services\Campaign;

use App\Models\Aio\Landing;
use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\LandingMvtInfo;
use App\Services\Aio\Dto\MvtField;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Fetches the `mvt_settings` of one or more landings via AIO's
 * `Lander\Create` action and decodes it into LandingMvtInfo DTOs.
 *
 * The AIO endpoint accepts a list of uuids, but it appears to return only the
 * first one's fields in the `fields[]` envelope (the other uuids end up in
 * `data` or `primary`). To stay robust we call one landing at a time — the
 * action endpoint is cached on AIO's side for short windows, so the
 * per-request overhead is small.
 *
 * Side effect: each fetch also backfills the landing into the local
 * `aio_landings` catalog if it's missing there (see ensureInCatalog). Push
 * rendering resolves landings through that catalog, and the hourly bulk sync
 * can lag behind (AIO rate limits) — without the backfill, a freshly
 * subscribed campaign whose landings haven't synced yet pushes nothing.
 */
final class LandingMvtFetcher
{
    public function __construct(private readonly AioClient $aio) {}

    public function fetch(string $landingUuid): LandingMvtInfo
    {
        $response = $this->aio->runLanderCreateAction([$landingUuid]);

        $fields = $this->fieldMap($response);
        $this->ensureInCatalog($landingUuid, $fields);
        $rawSettings = $fields['mvt_settings'] ?? null;

        // No mvt_settings at all (or empty string) → landing simply isn't MVT.
        // We still return a DTO with an empty fields list so the caller can
        // treat presence/absence uniformly via hasMvt().
        if (! is_string($rawSettings) || $rawSettings === '' || $rawSettings === '[]') {
            return new LandingMvtInfo($landingUuid, []);
        }

        $decoded = json_decode($rawSettings, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Не смог распарсить mvt_settings для ленда {$landingUuid}");
        }

        $mvtFields = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = $entry['key'] ?? null;
            $fieldUuid = $entry['uuid'] ?? null;
            $items = $entry['settings']['items'] ?? null;
            if (! is_string($key) || ! is_string($fieldUuid) || ! is_array($items)) {
                continue;
            }

            $variants = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $payload = $item['payload'] ?? null;
                if (! is_array($payload)) {
                    continue;
                }
                $content = $payload['content'] ?? null;
                if (is_string($content)) {
                    $variants[] = $content;
                } elseif ($content !== null) {
                    // Numeric / array values still count as a variant slot;
                    // serialize for stable equality downstream.
                    $variants[] = (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            $mvtFields[] = new MvtField(
                key: $key,
                fieldUuid: $fieldUuid,
                variants: $variants,
            );
        }

        $this->storeMvtSummary($landingUuid, $mvtFields);

        return new LandingMvtInfo($landingUuid, $mvtFields);
    }

    /**
     * Persist a compact variant summary onto the catalog row so UIs (Mini App
     * subscription details) can show WHAT is being MVT-tested without another
     * AIO round-trip. Unlike the identity backfill this always overwrites —
     * variants change as users edit the landing and fresh data wins.
     *
     * @param  list<MvtField>  $mvtFields
     */
    private function storeMvtSummary(string $landingUuid, array $mvtFields): void
    {
        try {
            Landing::query()->where('uuid', $landingUuid)->update([
                'mvt_settings' => array_map(fn (MvtField $f) => [
                    'key' => $f->key,
                    'variants' => $f->variants,
                ], $mvtFields),
            ]);
        } catch (Throwable) {
            // Summary is a nice-to-have; never break the subscribe flow.
        }
    }

    /**
     * Fetch many landings sequentially. Returned map is keyed by landing uuid.
     *
     * @param  list<string>  $landingUuids
     * @return array<string, LandingMvtInfo>
     */
    public function fetchMany(array $landingUuids): array
    {
        $out = [];
        foreach (array_unique($landingUuids) as $uuid) {
            $out[$uuid] = $this->fetch($uuid);
        }

        return $out;
    }

    /**
     * Backfill the landing into the local catalog when the bulk sync hasn't
     * reached it yet. firstOrCreate on purpose: the hourly sync's rows are
     * richer (owner, type name, archive flag) — never overwrite them with this
     * thinner identity; we only plug holes so pushes can resolve the landing.
     *
     * Never throws — a catalog miss here must not break the subscribe flow.
     *
     * @param  array<string, mixed>  $fields
     */
    private function ensureInCatalog(string $landingUuid, array $fields): void
    {
        try {
            $name = $fields['name'] ?? null;
            if (! is_string($name) || $name === '') {
                return; // not enough identity to be useful
            }

            $humanId = $fields['human_id'] ?? null;
            $countries = $fields['countries'] ?? [];

            Landing::query()->firstOrCreate(
                ['uuid' => $landingUuid],
                [
                    'name' => mb_substr($name, 0, 500),
                    'human_id' => is_numeric($humanId) ? (int) $humanId : null,
                    'landing_type_uuid' => is_string($fields['lander_type_uuid'] ?? null) ? $fields['lander_type_uuid'] : null,
                    'countries' => is_array($countries) ? array_values(array_filter($countries, 'is_string')) : [],
                    'raw' => ['backfilled_from' => 'Lander\\Create'],
                    'synced_at' => CarbonImmutable::now(),
                ],
            );
        } catch (Throwable) {
            // Catalog backfill is best-effort; MVT detection must proceed.
        }
    }

    /** @return array<string, mixed> */
    private function fieldMap(array $response): array
    {
        $fields = $response['fields'] ?? null;
        if (! is_array($fields)) {
            return [];
        }
        $out = [];
        foreach ($fields as $f) {
            if (! is_array($f)) {
                continue;
            }
            $name = $f['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            $out[$name] = $f['value'] ?? null;
        }

        return $out;
    }
}
