<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * Renders a stats snapshot as Telegram HTML.
 *
 * Single landing → per-metric block; two or more → tabular layout with
 * landings in columns and metrics in rows. Display labels + value formatting
 * come from MetricDisplay (ratio×100+%, percent+%, count with thousands sep).
 */
class StatsFormatter
{
    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $period
     * @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries
     */
    public function format(array $period, array $entries): string
    {
        $header = $this->header($period, count($entries));
        if ($entries === []) {
            return $header."\n\n<i>Нет данных.</i>";
        }

        $body = count($entries) === 1
            ? $this->blockLayout($entries[0])
            : $this->tableLayout($entries);

        return $header."\n\n".$body;
    }

    /** @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $period */
    private function header(array $period, int $count): string
    {
        $title = $count <= 1 ? '📊 stats' : '📊 compare';
        $window = $period['from']->format('d.m H:i').'..'.$period['to']->format('d.m H:i');

        return "<b>{$title}</b> — {$this->escape($period['label'])} ({$this->escape($period['timezone'])}, {$window})";
    }

    /** @param  array{label: string, metrics: array<string, int|float|null>}  $entry */
    private function blockLayout(array $entry): string
    {
        $lines = ['<b>'.$this->escape($entry['label']).'</b>'];
        foreach (MetricDisplay::order() as $slug) {
            if (! array_key_exists($slug, $entry['metrics'])) {
                continue;
            }
            $label = str_pad(MetricDisplay::label($slug), 10);
            $value = MetricDisplay::format($slug, $entry['metrics'][$slug]);
            $lines[] = "<code>{$label}{$value}</code>";
        }

        return implode("\n", $lines);
    }

    /** @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries */
    private function tableLayout(array $entries): string
    {
        $colWidth = max(10, ...array_map(fn ($e) => mb_strlen($e['label']) + 1, $entries));
        $colWidth = min($colWidth, 20);
        $labelWidth = 10;

        $headerCols = str_pad('', $labelWidth);
        foreach ($entries as $e) {
            $headerCols .= str_pad($this->truncate($e['label'], $colWidth - 1), $colWidth);
        }
        $lines = ["<code>{$this->escape(rtrim($headerCols))}</code>"];

        foreach (MetricDisplay::order() as $slug) {
            $label = str_pad(MetricDisplay::label($slug), $labelWidth);
            $row = $label;
            foreach ($entries as $e) {
                $value = array_key_exists($slug, $e['metrics']) ? MetricDisplay::format($slug, $e['metrics'][$slug]) : '—';
                $row .= str_pad($this->truncate($value, $colWidth - 1), $colWidth);
            }
            $lines[] = "<code>{$this->escape(rtrim($row))}</code>";
        }

        return implode("\n", $lines);
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
