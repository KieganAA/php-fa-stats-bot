<?php

namespace App\Services\Stats;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Parses period strings used in /stats and /compare into [from, to] datetimes.
 *
 * Accepts: today / сегодня, yesterday / вчера, <N>h, <N>d, <N>w, week, month.
 * Default ("" or null) → today (00:00 → now), in the configured timezone.
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
        $token = strtolower(trim((string) $input));

        if ($token === '' || $token === 'today' || $token === 'сегодня') {
            return $this->result($now->startOfDay(), $now, $tz, 'today');
        }

        if ($token === 'yesterday' || $token === 'вчера') {
            $start = $now->subDay()->startOfDay();
            $end = $now->subDay()->endOfDay();

            return $this->result($start, $end, $tz, 'yesterday');
        }

        if ($token === 'week' || $token === 'эта неделя' || $token === 'this week') {
            return $this->result($now->startOfWeek(), $now, $tz, 'this week');
        }

        if ($token === 'last week' || $token === 'прошлая неделя') {
            $start = $now->subWeek()->startOfWeek();
            $end = $now->subWeek()->endOfWeek();

            return $this->result($start, $end, $tz, 'last week');
        }

        if ($token === 'month' || $token === 'этот месяц' || $token === 'this month') {
            return $this->result($now->startOfMonth(), $now, $tz, 'this month');
        }

        if (preg_match('/^(\d+)\s*([hdwm])$/', $token, $m)) {
            $n = max(1, (int) $m[1]);
            $unit = $m[2];
            $start = match ($unit) {
                'h' => $now->subHours($n),
                'd' => $now->subDays($n),
                'w' => $now->subWeeks($n),
                'm' => $now->subMonths($n),
            };

            return $this->result($start, $now, $tz, "{$n}{$unit}");
        }

        throw new RuntimeException("Unknown period: '{$input}'");
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
