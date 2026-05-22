<?php

namespace App\Services\Stats;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Parses period strings used in /stats into [from, to] datetimes.
 *
 * Accepts (case-insensitive, mixed RU/EN):
 *   - today / сегодня                            00:00 today → now
 *   - yesterday / вчера                          full day before today
 *   - позавчера                                  day before yesterday
 *   - week / эта неделя / this week              Monday → now
 *   - last week / прошлая неделя                 previous Mon..Sun
 *   - month / этот месяц / this month            1st → now
 *   - last month / прошлый месяц                 previous full month
 *   - now / сейчас                               last 1 hour
 *   - час                                        last 1 hour
 *   - 24h / сутки                                last 24 hours
 *   - N{h|d|w|m} (24h, 7d, 2w, 1m)
 *   - N дн / N д / N дней                        N days
 *   - N ч / N часов                              N hours
 *   - N нед                                      N weeks
 *   - за <something>                             "за неделю" == week, "за вчера" == yesterday
 *
 * Returns CarbonImmutable in UTC after applying the requested timezone-aware
 * boundaries — AIO's pivot endpoint takes its own `timezone` separately so we
 * still pass the original tz alongside the timestamps.
 */
class PeriodParser
{
    public function __construct(
        private readonly string $defaultTimezone = 'UTC',
    ) {}

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, timezone: string, label: string}
     */
    public function parse(?string $input, ?string $timezone = null): array
    {
        $tz = $timezone ?: $this->defaultTimezone;
        $now = CarbonImmutable::now($tz);
        $token = mb_strtolower(trim((string) $input));

        // "за <что-то>" — strip the leading preposition and recurse.
        if (preg_match('/^за\s+(.+)$/u', $token, $m)) {
            return $this->parse($m[1], $timezone);
        }

        if ($token === '' || $token === 'today' || $token === 'сегодня') {
            return $this->result($now->startOfDay(), $now, $tz, 'today');
        }

        if ($token === 'yesterday' || $token === 'вчера') {
            $start = $now->subDay()->startOfDay();
            $end = $now->subDay()->endOfDay();

            return $this->result($start, $end, $tz, 'yesterday');
        }

        if ($token === 'позавчера') {
            $start = $now->subDays(2)->startOfDay();
            $end = $now->subDays(2)->endOfDay();

            return $this->result($start, $end, $tz, 'day before yesterday');
        }

        if (in_array($token, ['week', 'эта неделя', 'this week', 'неделя', 'неделю'], true)) {
            return $this->result($now->startOfWeek(), $now, $tz, 'this week');
        }

        if (in_array($token, ['last week', 'прошлая неделя', 'прошлую неделю'], true)) {
            $start = $now->subWeek()->startOfWeek();
            $end = $now->subWeek()->endOfWeek();

            return $this->result($start, $end, $tz, 'last week');
        }

        if (in_array($token, ['month', 'этот месяц', 'this month', 'месяц'], true)) {
            return $this->result($now->startOfMonth(), $now, $tz, 'this month');
        }

        if (in_array($token, ['last month', 'прошлый месяц'], true)) {
            $start = $now->subMonth()->startOfMonth();
            $end = $now->subMonth()->endOfMonth();

            return $this->result($start, $end, $tz, 'last month');
        }

        if (in_array($token, ['now', 'сейчас', 'час', 'hour', '1h'], true)) {
            return $this->result($now->subHour(), $now, $tz, '1h');
        }

        if (in_array($token, ['24h', 'сутки', 'day', 'last 24h', 'последние сутки'], true)) {
            return $this->result($now->subDay(), $now, $tz, '24h');
        }

        // N + unit, e.g. "7d", "24h", "2w", "1m"
        if (preg_match('/^(\d+)\s*([hdwm])$/u', $token, $m)) {
            $n = max(1, (int) $m[1]);

            return $this->ago($now, $tz, $n, $m[2]);
        }

        // N + Russian unit shorthand. Covers nominative, genitive 2-4 ("дня"),
        // and plural-many forms ("дней"/"часов"/"недель"/"месяцев").
        if (preg_match('/^(\d+)\s*(ч|час|часа|часов|д|дн|день|дня|дней|нед|неделя|недели|недель|мес|месяц|месяца|месяцев)\.?$/u', $token, $m)) {
            $n = max(1, (int) $m[1]);
            $unit = match (true) {
                str_starts_with($m[2], 'ч') => 'h',
                str_starts_with($m[2], 'нед') => 'w',
                str_starts_with($m[2], 'мес') => 'm',
                default => 'd',
            };

            return $this->ago($now, $tz, $n, $unit);
        }

        throw new RuntimeException("Unknown period: '{$input}'");
    }

    private function ago(CarbonImmutable $now, string $tz, int $n, string $unit): array
    {
        $start = match ($unit) {
            'h' => $now->subHours($n),
            'd' => $now->subDays($n),
            'w' => $now->subWeeks($n),
            'm' => $now->subMonths($n),
        };

        return $this->result($start, $now, $tz, "{$n}{$unit}");
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, timezone: string, label: string}
     */
    private function result(CarbonImmutable $from, CarbonImmutable $to, string $tz, string $label): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'timezone' => $tz,
            'label' => $label,
        ];
    }
}
