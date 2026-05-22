<?php

namespace App\Services\Tracking;

use App\Models\TrackedLanding;

/**
 * Renders a LandingSnapshotComparer result as a Telegram HTML message.
 *
 * Layout:
 *   <b>📈 LANDING NAME [LP1]</b>
 *   3h: 12:00–15:00 · prev: 09:00–12:00
 *
 *   clicks    120    Δ +25.0%
 *   LP CTR    0.45   Δ -3.1%
 *   leads     8      Δ +14.3%
 *   ...
 */
final class LandingSnapshotFormatter
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

    /** @param  array{current: \App\Models\LandingSnapshot, prior: ?\App\Models\LandingSnapshot, delta: array<string,array{abs:int|float|null,pct:float|null}>}  $comparison */
    public function format(TrackedLanding $landing, array $comparison): string
    {
        $current = $comparison['current'];
        $prior = $comparison['prior'];
        $delta = $comparison['delta'];

        $name = $this->escape((string) ($landing->landing?->name ?? "landing {$landing->landing_uuid}"));
        $kindEmoji = $current->kind === 'since_start' ? '📊' : '📈';
        $kindLabel = $current->kind === 'since_start' ? 'с старта' : '3h';

        $header = "<b>{$kindEmoji} {$name} [LP{$landing->position}]</b>";
        $window = $kindLabel.': '.$current->window_start->format('d.m H:i').'–'.$current->window_end->format('H:i');
        if ($prior) {
            $window .= ' · prev '.$prior->window_start->format('H:i').'–'.$prior->window_end->format('H:i');
        }

        $lines = [];
        foreach (self::METRIC_ORDER as $slug) {
            if (! array_key_exists($slug, $current->metrics)) {
                continue;
            }
            $lines[] = $this->renderLine(
                $slug,
                $current->metrics[$slug],
                $delta[$slug] ?? null,
            );
        }

        if ($lines === []) {
            $lines[] = '<i>За окно нет данных.</i>';
        }

        return $header."\n".$window."\n\n".'<code>'.implode("\n", $lines).'</code>';
    }

    /** @param  array{abs: int|float|null, pct: float|null}|null  $delta */
    private function renderLine(string $slug, int|float|null $value, ?array $delta): string
    {
        $label = self::METRIC_LABELS[$slug] ?? $slug;
        $valueStr = $this->formatValue($slug, $value);

        $deltaStr = '';
        if ($delta !== null && $delta['pct'] !== null) {
            $deltaStr = '  Δ '.$this->signedPct($delta['pct']);
        } elseif ($delta !== null && $delta['abs'] !== null) {
            $deltaStr = '  Δ '.$this->signed((float) $delta['abs']);
        }

        return str_pad($label, 10).str_pad($valueStr, 10).$deltaStr;
    }

    private function formatValue(string $slug, int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (in_array($slug, self::RATE_METRICS, true)) {
            return number_format((float) $value, 2);
        }

        return is_int($value) ? (string) $value : (string) round((float) $value, 2);
    }

    private function signedPct(float $pct): string
    {
        $sign = $pct > 0 ? '+' : '';

        return $sign.number_format($pct * 100, 1).'%';
    }

    private function signed(float $value): string
    {
        $sign = $value > 0 ? '+' : '';

        return $sign.(is_int($value) ? (string) $value : number_format($value, 2));
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
