<?php

namespace App\Services\Stats;

use App\Services\Aio\Pivot\PivotKeys;
use RuntimeException;

/**
 * Maps a user-supplied token (e.g. "DK", "BR", later: campaign / source /
 * buyer / landing names) onto an AIO pivot filter.
 *
 * Phase K: country codes only (2-letter ISO alpha-2). Phase L will widen
 * this to other AIO dimensions via fuzzy-name lookup against the synced
 * tables.
 *
 * Returned shape:
 *
 *   [
 *     'kind'        => 'country',        // dimension family (today: always 'country')
 *     'filter_key'  => 'location_country_code',  // AIO pivot key
 *     'filter_value'=> 'DK',             // value to filter on
 *     'label'       => 'DK',             // for the report header
 *     'group_key'   => 'location_country_code',  // default group_by for the totals row
 *   ]
 */
final class PrimitiveResolver
{
    public function resolve(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Пустой примитив.');
        }

        // ISO alpha-2 country codes — strict 2 letters, case-insensitive.
        if (preg_match('/^[a-z]{2}$/i', $token)) {
            $code = strtoupper($token);

            return [
                'kind' => 'country',
                'filter_key' => PivotKeys::COUNTRY,
                'filter_value' => $code,
                'label' => $code,
                'group_key' => PivotKeys::COUNTRY,
            ];
        }

        throw new RuntimeException(
            "Не понял примитив «{$token}». Пока поддерживаются только коды стран (DK, BR, IT…). ".
            'Поддержка кампаний/источников/баеров/лендов — на подходе.'
        );
    }
}
