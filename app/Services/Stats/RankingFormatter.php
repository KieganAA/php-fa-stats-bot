<?php

namespace App\Services\Stats;

use Carbon\CarbonInterface;

/**
 * Compact top-N table for daily-overview commands (/geo, /buyers, /lps1, /lps2).
 *
 *   🏆 топ стран — today (UTC, 22.05 00:00..22.05 14:30)
 *
 *   #  country  clicks  leads  CR%
 *   1. DK       12345   87     0.71
 *   2. IT       8932    65     0.73
 *   3. BR       6543    42     0.64
 *
 * Three metric columns is what fits on a phone without wrapping; everything
 * else stays available via /stats on a specific row's primitive.
 */
final class RankingFormatter
{
    private const SHOWN_METRICS = ['clicks', 'leads', 'real_cr'];

    private const METRIC_LABELS = [
        'clicks' => 'clicks',
        'leads' => 'leads',
        'real_cr' => 'CR%',
    ];

    private const RATE_METRICS = ['lp_ctr', 'real_cr', 'interest_rate', 'scrolling'];

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

        $labelWidth = max(8, ...array_map(fn ($e) => mb_strlen($e['label']), $entries));
        // 40 fits "#33169 · Celeb Preland · NO · @owner" without trimming.
        // Wider rows can wrap on mobile but that's better than losing the
        // country or owner chunk — those are the bits people skim for.
        $labelWidth = min($labelWidth, 40);

        $rankWidth = 4;
        $colWidth = 8;

        $head = str_pad('#', $rankWidth).str_pad($kindHeader, $labelWidth + 1);
        foreach (self::SHOWN_METRICS as $slug) {
            $head .= str_pad(self::METRIC_LABELS[$slug] ?? $slug, $colWidth);
        }
        $lines = ["<code>{$this->escape(rtrim($head))}</code>"];

        foreach ($entries as $i => $e) {
            $row = str_pad((string) ($i + 1).'.', $rankWidth);
            $row .= str_pad($this->truncate($e['label'], $labelWidth), $labelWidth + 1);
            foreach (self::SHOWN_METRICS as $slug) {
                $row .= str_pad($this->fmtValue($slug, $e['metrics'][$slug] ?? null), $colWidth);
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
