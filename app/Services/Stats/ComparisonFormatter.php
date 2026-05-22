<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * Side-by-side comparison report.
 *
 * Layout — landings/countries in columns, metrics in rows. When there are
 * exactly two entries we append a Δ% column versus the leftmost one. For
 * N≥3 we drop the delta column.
 *
 * Metric set comes from MetricDisplay::defaultNames() unless the caller
 * passes a per-user list.
 */
final class ComparisonFormatter
{
    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $period
     * @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries
     * @param  list<string>|null  $metricNames  AIO metric names; null = defaults
     */
    public function format(array $period, array $entries, ?array $metricNames = null): string
    {
        if (count($entries) < 2) {
            return $this->header($period)."\n\n<i>Compare нуждается минимум в 2 примитивах.</i>";
        }

        $names = $metricNames ?? MetricDisplay::defaultNames();
        $header = $this->header($period);
        $body = $this->table($entries, $names);

        return $header."\n\n".$body;
    }

    /** @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $period */
    private function header(array $period): string
    {
        $window = $period['from']->format('d.m H:i').'..'.$period['to']->format('d.m H:i');

        return "<b>📊 compare</b> — {$this->escape($period['label'])} ({$this->escape($period['timezone'])}, {$window})";
    }

    /**
     * @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries
     * @param  list<string>  $names
     */
    private function table(array $entries, array $names): string
    {
        if ($names === []) {
            return '<i>Не выбраны метрики.</i>';
        }

        $withDelta = count($entries) === 2;

        $colWidth = min(20, max(11, ...array_map(fn ($e) => mb_strlen($e['label']) + 1, $entries)));
        $labelWidth = max(10, ...array_map(fn ($n) => mb_strlen(MetricDisplay::label($n)) + 1, $names));
        $deltaWidth = 9;

        $headerCols = str_pad('', $labelWidth);
        foreach ($entries as $e) {
            $headerCols .= str_pad($this->truncate($e['label'], $colWidth - 1), $colWidth);
        }
        if ($withDelta) {
            $headerCols .= str_pad('Δ%', $deltaWidth);
        }
        $lines = ["<code>{$this->escape(rtrim($headerCols))}</code>"];

        $left = $entries[0]['metrics'];
        $right = $withDelta ? $entries[1]['metrics'] : null;

        foreach ($names as $name) {
            $row = str_pad(MetricDisplay::label($name), $labelWidth);
            foreach ($entries as $e) {
                $v = $e['metrics'][$name] ?? null;
                $row .= str_pad($this->truncate(MetricDisplay::format($name, $v), $colWidth - 1), $colWidth);
            }
            if ($withDelta) {
                $row .= str_pad($this->fmtDelta($left[$name] ?? null, $right[$name] ?? null), $deltaWidth);
            }
            $lines[] = "<code>{$this->escape(rtrim($row))}</code>";
        }

        return implode("\n", $lines);
    }

    private function fmtDelta(int|float|null $a, int|float|null $b): string
    {
        if ($a === null || $b === null) {
            return '—';
        }
        if ((float) $a == 0.0) {
            return ((float) $b == 0.0) ? '0' : '∞';
        }
        $pct = (($b - $a) / $a) * 100.0;
        $sign = $pct > 0 ? '+' : '';

        return $sign.number_format($pct, 1).'%';
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
