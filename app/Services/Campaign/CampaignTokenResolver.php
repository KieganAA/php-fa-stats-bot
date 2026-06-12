<?php

namespace App\Services\Campaign;

use RuntimeException;

/**
 * Resolves a user-supplied campaign token into the AIO campaign uuid the
 * fetcher needs.
 *
 * The extension (primary entry point) hands us a uuid straight from the AIO
 * page, so that path is a no-op passthrough. Manual chat input may instead be a
 * human_id (e.g. 036469) — we look that up in the `Tracker\Campaigns` table via
 * CampaignDirectory and swap in the uuid.
 */
final class CampaignTokenResolver
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly CampaignDirectory $directory) {}

    public function resolve(string $token): string
    {
        $token = trim($token);

        if (preg_match(self::UUID_RE, $token)) {
            return strtolower($token);
        }

        if (ctype_digit($token)) {
            $found = $this->directory->findByHumanId($token);
            if ($found !== null) {
                return $found['uuid'];
            }

            throw new RuntimeException(
                "Кампания с human_id {$token} не найдена в AIO. ".
                'Проверь номер или открой кампанию через расширение.',
            );
        }

        throw new RuntimeException(
            'Не похоже на кампанию. Нужен human_id (например 036469) или uuid кампании '.
            '— проще всего через расширение.',
        );
    }

    public function isUuid(string $token): bool
    {
        return (bool) preg_match(self::UUID_RE, trim($token));
    }
}
