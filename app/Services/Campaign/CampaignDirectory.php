<?php

namespace App\Services\Campaign;

use App\Services\Aio\AioClient;

/**
 * Resolves a campaign human_id (e.g. 036469 / 36469) to its AIO uuid by
 * searching the `Tracker\Campaigns` table. The table's `_identity.human_id` is
 * a zero-padded string while the action endpoint reports it unpadded, so we
 * compare numerically. Search can return near-matches (substring hits) — we
 * only accept an exact human_id match.
 */
final class CampaignDirectory
{
    public function __construct(private readonly AioClient $aio) {}

    /**
     * @return array{uuid: string, human_id: int, name: string}|null
     */
    public function findByHumanId(string $humanId): ?array
    {
        $target = (int) ltrim(trim($humanId), '0');
        if ($target <= 0) {
            return null;
        }

        foreach ($this->aio->searchCampaigns($humanId) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $identity = $row['_identity'] ?? [];
            $identity = is_array($identity) ? $identity : [];

            $hid = $identity['human_id'] ?? null;
            if ($hid === null || (int) $hid !== $target) {
                continue;
            }

            $uuid = $identity['uuid'] ?? ($row['uuid'] ?? null);
            if (is_string($uuid) && $uuid !== '') {
                return [
                    'uuid' => strtolower($uuid),
                    'human_id' => $target,
                    'name' => is_string($identity['name'] ?? null) ? $identity['name'] : '',
                ];
            }
        }

        return null;
    }
}
