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
    /** Guard against a fat-fingered decade-wide custom range hammering AIO. */
    private const MAX_RANGE_DAYS = 400;

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

    /**
     * Resolve a window from either an explicit [from, to] calendar range or,
     * when those are absent, a named period token. `from`/`to` win when both
     * are present — this is the single entry point the Mini App calendar and
     * the preset picker both funnel through.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, timezone: string, label: string}
     */
    public function resolve(?string $period, ?string $from, ?string $to, ?string $timezone = null): array
    {
        if (is_string($from) && $from !== '' && is_string($to) && $to !== '') {
            return $this->range($from, $to, $timezone);
        }

        return $this->parse($period, $timezone);
    }

    /**
     * Build a window from two calendar dates (`Y-m-d`), inclusive: start-of-day
     * of `from` through end-of-day of `to`, in the requested timezone. Reversed
     * inputs are swapped rather than rejected. Callers should pre-validate the
     * date format; malformed input surfaces as an exception.
     *
     * Feeds AIO's pivot-report endpoint, which — unlike tables/data — has no
     * month-boundary restriction, so arbitrary spans are safe here.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, timezone: string, label: string}
     */
    public function range(string $from, string $to, ?string $timezone = null): array
    {
        $tz = $timezone ?: $this->defaultTimezone;

        // "!" zeroes the time fields so no wall-clock leaks in from "now".
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $tz)->startOfDay();
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, $tz)->endOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            throw new RuntimeException('Слишком большой диапазон (макс. '.self::MAX_RANGE_DAYS.' дней).');
        }

        return $this->result($start, $end, $tz, $this->rangeLabel($start, $end));
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
     * Compact human label for a custom range, e.g. "01.07 – 07.07.2026", or
     * just "07.07.2026" for a single day. Drops the year on the left endpoint
     * when both fall in the same year.
     */
    private function rangeLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('d.m.Y');
        }

        $left = $from->year === $to->year ? $from->format('d.m') : $from->format('d.m.Y');

        return $left.' – '.$to->format('d.m.Y');
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
