<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * Compact top-N table for daily-overview commands (/geo, /buyers, /lps1, /lps2).
 *
 * Three metric columns fits a phone without wrapping; the default trio
 * (Q Visits, Leads, Real Approve) can be overridden per call via $metricNames.
 */
final class RankingFormatter
{
    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $window
     * @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries
     * @param  list<string>|null  $metricNames
     */
    public function format(array $window, string $title, array $entries, string $kindHeader = 'item', ?array $metricNames = null): string
    {
        $header = $this->header($window, $title);
        if ($entries === []) {
            return $header."\n\n<i>Нет данных за окно.</i>";
        }

        $shown = $metricNames ?? MetricDisplay::topNames();
        if ($shown === []) {
            return $header."\n\n<i>Не выбраны метрики.</i>";
        }

        // Phone `<code>` wraps around ~40 chars. Budget per row:
        //   #     <label>          metric1   metric2   metric3
        //   3  +  18 (hard cap)  + 7       + 7       + 7    = ~42, just fits.
        $labelWidth = max(8, ...array_map(fn ($e) => mb_strlen($e['label']), $entries));
        $labelWidth = min($labelWidth, 18);

        $rankWidth = 4;
        $colWidth = max(7, ...array_map(fn ($n) => mb_strlen(MetricDisplay::label($n)) + 1, $shown));

        $head = mb_str_pad('#', $rankWidth).mb_str_pad($kindHeader, $labelWidth + 1);
        foreach ($shown as $name) {
            $head .= mb_str_pad(MetricDisplay::label($name), $colWidth);
        }
        $lines = ["<code>{$this->escape(rtrim($head))}</code>"];

        foreach ($entries as $i => $e) {
            $row = mb_str_pad((string) ($i + 1).'.', $rankWidth);
            $row .= mb_str_pad($this->truncate($e['label'], $labelWidth), $labelWidth + 1);
            foreach ($shown as $name) {
                $row .= mb_str_pad(MetricDisplay::format($name, $e['metrics'][$name] ?? null), $colWidth);
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
