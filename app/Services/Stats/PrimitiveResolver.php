<?php

namespace App\Services\Stats;

use App\Models\Aio\Landing;
use App\Services\Aio\Pivot\PivotKeys;
use RuntimeException;

/**
 * Maps a user-supplied token onto an AIO pivot filter.
 *
 * Recognised token shapes (Phase L scope):
 *   - 2-letter ISO alpha-2:   "DK", "br", "It"            → country slice
 *   - digits, 1+:             "33169", "205228"           → landing by human_id
 *   - uuid:                   "a64f13e6-984e-…"           → landing by uuid
 *
 * Returned shape (array — typed VO is on the backlog):
 *   [
 *     'kind'         => 'country' | 'landing',
 *     'filter_key'   => PivotKeys::COUNTRY | "landing_uuids[1]",
 *     'filter_value' => 'DK' | <uuid>,
 *     'label'        => 'DK' | '#33169 · Celeb Preland · NO · @zigi',
 *     'group_key'    => same as filter_key,
 *     'position'     => 1 (landings only — the funnel slot we filter on),
 *     'landing'      => Landing eloquent model (landings only),
 *   ]
 *
 * Future kinds (Phase L follow-up): campaign / source / buyer — those need a
 * fuzzy-name search against `aio_*` tables, which is more invasive.
 */
final class PrimitiveResolver
{
    public function __construct(
        private readonly LandingFormatter $landings,
    ) {}

    public function resolve(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Пустой примитив.');
        }

        if (preg_match('/^[a-z]{2}$/i', $token)) {
            return $this->countryShape(strtoupper($token));
        }

        if (ctype_digit($token)) {
            $landing = Landing::query()->where('human_id', (int) $token)->first();
            if ($landing === null) {
                throw new RuntimeException("Лендинг #{$token} не найден в локальной БД. Возможно нужен пересинк (artisan aio:sync:landings).");
            }

            return $this->landingShape($landing);
        }

        if ($this->looksLikeUuid($token)) {
            $landing = Landing::query()->where('uuid', $token)->first();
            if ($landing === null) {
                throw new RuntimeException("Лендинг с uuid {$token} не найден.");
            }

            return $this->landingShape($landing);
        }

        throw new RuntimeException(
            "Не понял примитив «{$token}». ".
            'Поддерживаются: коды стран (DK, BR…), human_id лендинга (33169), uuid лендинга. '.
            'Поиск по имени/кампании/баеру — на подходе.'
        );
    }

    private function countryShape(string $code): array
    {
        return [
            'kind' => 'country',
            'filter_key' => PivotKeys::COUNTRY,
            'filter_value' => $code,
            'label' => '🌍 '.$code,
            'group_key' => PivotKeys::COUNTRY,
        ];
    }

    private function landingShape(Landing $landing, int $position = 1): array
    {
        $key = PivotKeys::landingUuid($position);

        return [
            'kind' => 'landing',
            'filter_key' => $key,
            'filter_value' => $landing->uuid,
            'label' => $this->landings->shortLine($landing),
            'group_key' => $key,
            'position' => $position,
            'landing' => $landing,
        ];
    }

    private function looksLikeUuid(string $token): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token);
    }
}
