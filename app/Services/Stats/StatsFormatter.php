<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * Renders a stats snapshot as Telegram HTML.
 *
 * Single entry → per-metric block; two or more → tabular layout with entries
 * in columns and metrics in rows. Metric set (names + display order) comes
 * from MetricDisplay::defaultNames() unless the caller passes their own
 * list (per-user preference).
 */
class StatsFormatter
{
    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $period
     * @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries
     * @param  list<string>|null  $metricNames  AIO metric names; null = defaults
     */
    public function format(array $period, array $entries, ?array $metricNames = null): string
    {
        $names = $metricNames ?? MetricDisplay::defaultNames();
        $header = $this->header($period, count($entries));
        if ($entries === []) {
            return $header."\n\n<i>Нет данных.</i>";
        }

        $body = count($entries) === 1
            ? $this->blockLayout($entries[0], $names)
            : $this->tableLayout($entries, $names);

        return $header."\n\n".$body;
    }

    /** @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $period */
    private function header(array $period, int $count): string
    {
        $title = $count <= 1 ? '📊 stats' : '📊 compare';
        $window = $period['from']->format('d.m H:i').'..'.$period['to']->format('d.m H:i');

        return "<b>{$title}</b> — {$this->escape($period['label'])} ({$this->escape($period['timezone'])}, {$window})";
    }

    /**
     * @param  array{label: string, metrics: array<string, int|float|null>}  $entry
     * @param  list<string>  $names
     */
    private function blockLayout(array $entry, array $names): string
    {
        if ($names === []) {
            return '<i>Не выбраны метрики для отображения.</i>';
        }
        $labelWidth = max(10, ...array_map(fn ($n) => mb_strlen(MetricDisplay::label($n)) + 2, $names));

        $lines = ['<b>'.$this->escape($entry['label']).'</b>'];
        foreach ($names as $name) {
            if (! array_key_exists($name, $entry['metrics'])) {
                continue;
            }
            $label = mb_str_pad(MetricDisplay::label($name), $labelWidth);
            $value = MetricDisplay::format($name, $entry['metrics'][$name]);
            $lines[] = "<code>{$label}{$value}</code>";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries
     * @param  list<string>  $names
     */
    private function tableLayout(array $entries, array $names): string
    {
        if ($names === []) {
            return '<i>Не выбраны метрики.</i>';
        }
        $colWidth = max(10, ...array_map(fn ($e) => mb_strlen($e['label']) + 1, $entries));
        $colWidth = min($colWidth, 20);
        $labelWidth = max(10, ...array_map(fn ($n) => mb_strlen(MetricDisplay::label($n)) + 1, $names));

        $headerCols = mb_str_pad('', $labelWidth);
        foreach ($entries as $e) {
            $headerCols .= mb_str_pad($this->truncate($e['label'], $colWidth - 1), $colWidth);
        }
        $lines = ["<code>{$this->escape(rtrim($headerCols))}</code>"];

        foreach ($names as $name) {
            $row = mb_str_pad(MetricDisplay::label($name), $labelWidth);
            foreach ($entries as $e) {
                $value = array_key_exists($name, $e['metrics'])
                    ? MetricDisplay::format($name, $e['metrics'][$name])
                    : '—';
                $row .= mb_str_pad($this->truncate($value, $colWidth - 1), $colWidth);
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
