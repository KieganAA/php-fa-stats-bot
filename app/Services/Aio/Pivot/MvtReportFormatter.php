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
    // Metric labels + value formatting come from MetricDisplay; order = defaults.

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
        foreach (\App\Services\Stats\MetricDisplay::defaultNames() as $name) {
            if (! array_key_exists($name, $row['current'])) {
                continue;
            }
            $lines[] = $this->renderMetricLine($name, $row['current'][$name], $row['delta_prior'][$name] ?? null, $row['delta_since_start'][$name] ?? null);
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

    private function renderMetricLine(string $name, int|float|null $value, ?array $deltaPrior, ?array $deltaSince): string
    {
        $label = \App\Services\Stats\MetricDisplay::label($name);
        $valueStr = \App\Services\Stats\MetricDisplay::format($name, $value);
        $deltaPart = $this->formatDelta($deltaPrior, $deltaSince);

        return '<code>'.mb_str_pad($label, 10).mb_str_pad($valueStr, 10).'</code>'.$deltaPart;
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
