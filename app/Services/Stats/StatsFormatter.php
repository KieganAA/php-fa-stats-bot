<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * Renders a stats snapshot as Telegram HTML.
 *
 * Single landing → per-metric block; two or more → tabular layout with
 * landings in columns and metrics in rows. Both share the same target-metric
 * set (config('aio.target_metrics')) so comparisons line up cleanly.
 */
class StatsFormatter
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
        foreach (self::METRIC_ORDER as $slug) {
            if (! array_key_exists($slug, $entry['metrics'])) {
                continue;
            }
            $label = str_pad(self::METRIC_LABELS[$slug] ?? $slug, 10);
            $value = $this->formatValue($slug, $entry['metrics'][$slug]);
            $lines[] = "<code>{$label}{$value}</code>";
        }

        return implode("\n", $lines);
    }

    /** @param  list<array{label: string, metrics: array<string, int|float|null>}>  $entries */
    private function tableLayout(array $entries): string
    {
        $colWidth = max(8, ...array_map(fn ($e) => mb_strlen($e['label']), $entries)) + 1;
        $labelWidth = 10;

        $headerCols = str_pad('', $labelWidth);
        foreach ($entries as $e) {
            $headerCols .= str_pad($this->truncate($e['label'], $colWidth - 1), $colWidth);
        }
        $lines = ["<code>{$this->escape(rtrim($headerCols))}</code>"];

        foreach (self::METRIC_ORDER as $slug) {
            $label = str_pad(self::METRIC_LABELS[$slug] ?? $slug, $labelWidth);
            $row = $label;
            foreach ($entries as $e) {
                $value = array_key_exists($slug, $e['metrics']) ? $this->formatValue($slug, $e['metrics'][$slug]) : '—';
                $row .= str_pad($this->truncate($value, $colWidth - 1), $colWidth);
            }
            $lines[] = "<code>{$this->escape(rtrim($row))}</code>";
        }

        return implode("\n", $lines);
    }

    private function formatValue(string $slug, int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        $isRate = in_array($slug, ['lp_ctr', 'real_cr', 'interest_rate', 'scrolling'], true);
        if ($isRate) {
            return number_format((float) $value, 2);
        }

        if (is_int($value) || floor((float) $value) == $value) {
            return (string) (int) $value;
        }

        return (string) round((float) $value, 2);
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
