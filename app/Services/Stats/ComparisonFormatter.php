<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * Side-by-side comparison report.
 *
 * Layout — landings/countries in columns, metrics in rows. When there are
 * exactly two entries we append a Δ% column versus the leftmost one (the
 * primary use case is "is B better than A?"). For N≥3 we drop the delta
 * column — too many directions to compare.
 *
 *   📊 compare — today (UTC, 22.05 00:00..22.05 14:30)
 *
 *               #33169    #205215   Δ%
 *   clicks      120       480       +300%
 *   LP CTR      0.45      0.62      +37.8%
 *   leads       8         22        +175%
 *   ...
 */
final class ComparisonFormatter
{
    private const METRIC_ORDER = [
        'clicks',
        'lp_ctr',
        'leads',
        'ftds_real',
        'real_cr',
        'interest_rate',
        'scrolling',
    ];

    private const METRIC_LABELS = [
        'clicks' => 'clicks',
        'lp_ctr' => 'LP CTR',
        'leads' => 'leads',
        'ftds_real' => 'FTDs',
        'real_cr' => 'CR%',
        'interest_rate' => 'interest',
        'scrolling' => 'scroll',
    ];

    private const RATE_METRICS = ['lp_ctr', 'real_cr', 'interest_rate', 'scrolling'];

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $period
     * @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries
     */
    public function format(array $period, array $entries): string
    {
        if (count($entries) < 2) {
            return $this->header($period)."\n\n<i>Compare нуждается минимум в 2 примитивах.</i>";
        }

        $header = $this->header($period);
        $body = $this->table($entries);

        return $header."\n\n".$body;
    }

    /** @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $period */
    private function header(array $period): string
    {
        $window = $period['from']->format('d.m H:i').'..'.$period['to']->format('d.m H:i');

        return "<b>📊 compare</b> — {$this->escape($period['label'])} ({$this->escape($period['timezone'])}, {$window})";
    }

    /** @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries */
    private function table(array $entries): string
    {
        $withDelta = count($entries) === 2;

        // Column widths: each entry's column at least 10, capped at 20 to keep
        // the line under Telegram's mobile-rendering width.
        $colWidth = min(20, max(11, ...array_map(fn ($e) => mb_strlen($e['label']) + 1, $entries)));
        $labelWidth = 10;
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

        foreach (self::METRIC_ORDER as $slug) {
            $row = str_pad(self::METRIC_LABELS[$slug] ?? $slug, $labelWidth);
            foreach ($entries as $e) {
                $v = $e['metrics'][$slug] ?? null;
                $row .= str_pad($this->truncate($this->fmtValue($slug, $v), $colWidth - 1), $colWidth);
            }
            if ($withDelta) {
                $row .= str_pad($this->fmtDelta($left[$slug] ?? null, $right[$slug] ?? null), $deltaWidth);
            }
            $lines[] = "<code>{$this->escape(rtrim($row))}</code>";
        }

        return implode("\n", $lines);
    }

    private function fmtValue(string $slug, int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (in_array($slug, self::RATE_METRICS, true)) {
            return number_format((float) $value, 2);
        }
        if (is_int($value) || floor((float) $value) == $value) {
            return (string) (int) $value;
        }

        return (string) round((float) $value, 2);
    }

    private function fmtDelta(int|float|null $a, int|float|null $b): string
    {
        if ($a === null || $b === null) {
            return '—';
        }
        if ((float) $a == 0.0) {
            // Baseline is 0 — can't compute %; show "∞" if B>0, "0" if both 0.
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
