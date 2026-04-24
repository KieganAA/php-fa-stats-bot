<?php

namespace App\Services\Aio\Pivot;

use App\Models\MvtSlice;
use App\Models\TrackedLanding;

/**
 * Renders an MvtComparer result as a Telegram HTML message.
 *
 * Layout (per tracked landing):
 *
 *   <b>📊 LANDING NAME [pos N]</b>
 *   3h окно: 12:00–15:00 · с старта: 25 апр 09:00
 *
 *   <b>field_slug = "variant text"</b>
 *   ┌ clicks    100   Δ +25% / +10% от старта
 *   ├ lp_ctr    0.45  Δ +5%  / -3%
 *   └ ...
 *
 * Telegram HTML mode is used (simpler escaping than MarkdownV2). Only
 * &, <, > need escaping; everything else is literal.
 */
class MvtReportFormatter
{
    /** Order of metrics in the per-row block — matches config('aio.target_metrics') key order. */
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

    /** @param  array  $comparison  output of MvtComparer::compare() */
    public function format(TrackedLanding $landing, array $comparison): string
    {
        $current = $comparison['current'];
        assert($current instanceof MvtSlice);

        $title = $this->title($landing, $current, $comparison['prior'] ?? null, $comparison['since_start'] ?? null);

        $blocks = [$title];
        foreach ($comparison['rows'] as $row) {
            if ($this->isAllZero($row['current'])) {
                continue;
            }
            $blocks[] = $this->renderRow($row);
        }

        if (count($blocks) === 1) {
            $blocks[] = '<i>За окно нет данных по вариантам.</i>';
        }

        return implode("\n\n", $blocks);
    }

    private function title(TrackedLanding $landing, MvtSlice $current, ?MvtSlice $prior, ?MvtSlice $sinceStart): string
    {
        $name = $this->escape((string) ($landing->landing?->name ?? "landing {$landing->landing_uuid}"));
        $window3h = $current->window_start->format('d.m H:i').'–'.$current->window_end->format('H:i');
        $priorPart = $prior
            ? ' · prev '.$prior->window_start->format('H:i').'–'.$prior->window_end->format('H:i')
            : ' · prev: —';
        $startPart = $sinceStart
            ? ' · с '.$sinceStart->window_start->format('d.m H:i')
            : '';

        return "<b>📊 {$name} [pos {$landing->position}]</b>\n"
            .'3h: '.$window3h.$priorPart.$startPart;
    }

    /** @param  array{dimensions: array<string,string>, current: array<string,int|float|null>, delta_prior: ?array, delta_since_start: ?array}  $row */
    private function renderRow(array $row): string
    {
        $header = $this->renderDimensions($row['dimensions']);
        $lines = [];
        foreach (self::METRIC_ORDER as $slug) {
            if (! array_key_exists($slug, $row['current'])) {
                continue;
            }
            $lines[] = $this->renderMetricLine($slug, $row['current'][$slug], $row['delta_prior'][$slug] ?? null, $row['delta_since_start'][$slug] ?? null);
        }

        return $header."\n".implode("\n", $lines);
    }

    /** @param  array<string,string>  $dimensions */
    private function renderDimensions(array $dimensions): string
    {
        $parts = [];
        foreach ($dimensions as $slug => $value) {
            $shown = $value === '' ? '∑ all' : $this->trimVariant($value);
            $parts[] = '<b>'.$this->escape($slug).'</b>='.$this->escape($shown);
        }

        return implode(' · ', $parts);
    }

    private function renderMetricLine(string $slug, int|float|null $value, ?array $deltaPrior, ?array $deltaSince): string
    {
        $label = self::METRIC_LABELS[$slug] ?? $slug;
        $valueStr = $this->formatValue($slug, $value);
        $deltaPart = $this->formatDelta($deltaPrior, $deltaSince);

        return '<code>'.str_pad($label, 10).$valueStr.'</code>'.$deltaPart;
    }

    private function formatValue(string $slug, int|float|null $value): string
    {
        if ($value === null) {
            return str_pad('—', 10);
        }

        $isRate = in_array($slug, ['lp_ctr', 'real_cr', 'interest_rate', 'scrolling'], true);
        if ($isRate) {
            return str_pad(number_format((float) $value, 2), 10);
        }

        return str_pad((string) (is_int($value) ? $value : round((float) $value, 2)), 10);
    }

    private function formatDelta(?array $deltaPrior, ?array $deltaSince): string
    {
        $bits = [];
        if ($deltaPrior !== null && $deltaPrior['pct'] !== null) {
            $bits[] = 'Δ '.$this->signedPct($deltaPrior['pct']);
        }
        if ($deltaSince !== null && $deltaSince['pct'] !== null) {
            $bits[] = 'старт '.$this->signedPct($deltaSince['pct']);
        }
        if ($bits === []) {
            return '';
        }

        return ' '.implode(' / ', $bits);
    }

    private function signedPct(float $pct): string
    {
        $sign = $pct > 0 ? '+' : '';

        return $sign.number_format($pct * 100, 1).'%';
    }

    private function trimVariant(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if (mb_strlen($value) <= 40) {
            return $value;
        }

        return mb_substr($value, 0, 37).'…';
    }

    /** @param  array<string,int|float|null>  $metrics */
    private function isAllZero(array $metrics): bool
    {
        foreach ($metrics as $v) {
            if ($v !== null && $v != 0) {
                return false;
            }
        }

        return true;
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
