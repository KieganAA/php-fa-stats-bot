<?php

namespace App\Services\Campaign;

use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\LandingMvtInfo;
use App\Services\Aio\Dto\MvtField;
use RuntimeException;

/**
 * Fetches the `mvt_settings` of one or more landings via AIO's
 * `Lander\Create` action and decodes it into LandingMvtInfo DTOs.
 *
 * The AIO endpoint accepts a list of uuids, but it appears to return only the
 * first one's fields in the `fields[]` envelope (the other uuids end up in
 * `data` or `primary`). To stay robust we call one landing at a time — the
 * action endpoint is cached on AIO's side for short windows, so the
 * per-request overhead is small.
 */
final class LandingMvtFetcher
{
    public function __construct(private readonly AioClient $aio) {}

    public function fetch(string $landingUuid): LandingMvtInfo
    {
        $response = $this->aio->runLanderCreateAction([$landingUuid]);

        $fields = $this->fieldMap($response);
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

        return new LandingMvtInfo($landingUuid, $mvtFields);
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
