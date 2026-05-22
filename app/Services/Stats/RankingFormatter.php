<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * Compact top-N table for daily-overview commands (/geo, /buyers, /lps1, /lps2).
 *
 *   🏆 топ стран — today (UTC, 22.05 00:00..22.05 14:30)
 *
 *   #  country  clicks    leads  CR%
 *   1. DK       12 345    87     11.76%
 *   2. IT       8 932     65     8.34%
 *
 * Three metric columns is what fits on a phone without wrapping; everything
 * else stays available via /stats on a specific row's primitive. Values come
 * from MetricDisplay so units match across the bot.
 */
final class RankingFormatter
{
    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $window
     * @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries
     */
    public function format(array $window, string $title, array $entries, string $kindHeader = 'item'): string
    {
        $header = $this->header($window, $title);
        if ($entries === []) {
            return $header."\n\n<i>Нет данных за окно.</i>";
        }

        $shownMetrics = MetricDisplay::topOrder();

        $labelWidth = max(8, ...array_map(fn ($e) => mb_strlen($e['label']), $entries));
        $labelWidth = min($labelWidth, 40);

        $rankWidth = 4;
        $colWidth = max(7, ...array_map(fn ($s) => mb_strlen(MetricDisplay::label($s)) + 2, $shownMetrics));

        $head = str_pad('#', $rankWidth).str_pad($kindHeader, $labelWidth + 1);
        foreach ($shownMetrics as $slug) {
            $head .= str_pad(MetricDisplay::label($slug), $colWidth);
        }
        $lines = ["<code>{$this->escape(rtrim($head))}</code>"];

        foreach ($entries as $i => $e) {
            $row = str_pad((string) ($i + 1).'.', $rankWidth);
            $row .= str_pad($this->truncate($e['label'], $labelWidth), $labelWidth + 1);
            foreach ($shownMetrics as $slug) {
                $row .= str_pad(MetricDisplay::format($slug, $e['metrics'][$slug] ?? null), $colWidth);
            }
            $lines[] = "<code>{$this->escape(rtrim($row))}</code>";
        }

        return $header."\n\n".implode("\n", $lines);
    }

    /** @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $window */
    private function header(array $window, string $title): string
    {
        $w = $window['from']->format('d.m H:i').'..'.$window['to']->format('d.m H:i');

        return "<b>🏆 {$this->escape($title)}</b> — {$this->escape($window['label'])} ({$this->escape($window['timezone'])}, {$w})";
    }

    private function truncate(string $s, int $width): string
    {
        if (mb_strlen($s) <= $width) {
            return $s;
        }

        return mb_substr($s, 0, max(1, $width - 1)).'…';
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
