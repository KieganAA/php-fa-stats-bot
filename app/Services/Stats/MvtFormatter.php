<?php

namespace App\Services\Stats;

use App\Models\Aio\Landing;
use Carbon\CarbonInterface;

/**
 * Renders an MvtReporter result as Telegram HTML.
 *
 * Layout — when a landing has MVT variants the report groups by their values:
 *
 *   🧪 MVT #33169 · Celeb Preland · NO — today
 *
 *   lp_header = "Headline A"
 *     clicks 120  leads 12  CR% 1.50
 *
 *   lp_header = "Headline B"
 *     clicks 95   leads 18  CR% 2.10  ← если N=2 показываем delta vs первой
 *
 * If a landing has no MVT variants (single row, all empty dimensions),
 * MvtReporter filters that out and we just say so here.
 */
final class MvtFormatter
{
    private const SHOWN_METRICS = ['clicks', 'leads', 'real_cr', 'ftds_real'];

    public function __construct(
        private readonly LandingFormatter $landings,
    ) {}

    /** @param  array{landing: Landing, window: array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}, rows: list<array{variants: array<string,string>, metrics: array<string,int|float|null>}>, active_slugs: list<string>}  $report */
    public function format(array $report): string
    {
        $header = $this->header($report['landing'], $report['window']);
        $rows = $report['rows'];

        if ($rows === []) {
            return $header."\n\n<i>У этого лендинга нет активных MVT-вариантов в окне.</i>";
        }

        // Slugs that actually varied across rows — others are constant or empty.
        $active = $report['active_slugs'];
        if ($active === []) {
            return $header."\n\n<i>Все MVT-поля пустые.</i>";
        }

        $blocks = [$header];

        // Sort rows by leads desc — leader on top.
        usort($rows, fn ($a, $b) => ($b['metrics']['leads'] ?? 0) <=> ($a['metrics']['leads'] ?? 0));

        $baselineMetrics = $rows[0]['metrics'];
        foreach ($rows as $i => $row) {
            $blocks[] = $this->renderRow($row, $active, $i === 0 ? null : $baselineMetrics, $i + 1);
        }

        return implode("\n\n", $blocks);
    }

    /** @param  array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}  $window */
    private function header(Landing $landing, array $window): string
    {
        $w = $window['from']->format('d.m H:i').'..'.$window['to']->format('d.m H:i');
        $short = $this->landings->shortLine($landing);

        return "<b>🧪 MVT</b> {$this->escape($short)} — {$this->escape($window['label'])} ({$w})";
    }

    /**
     * @param  array{variants: array<string,string>, metrics: array<string,int|float|null>}  $row
     * @param  list<string>  $activeSlugs
     * @param  array<string,int|float|null>|null  $baseline
     */
    private function renderRow(array $row, array $activeSlugs, ?array $baseline, int $rank): string
    {
        $lines = [];
        $rankBadge = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : "#{$rank}"));
        $lines[] = "{$rankBadge}";

        foreach ($activeSlugs as $slug) {
            $value = $row['variants'][$slug] ?? '';
            $shown = $value === '' ? '∅' : $this->trimVariant($value);
            $lines[] = "  <b>{$this->escape($slug)}</b> = "."<code>{$this->escape($shown)}</code>";
        }

        // Metric line
        $metricBits = [];
        foreach (self::SHOWN_METRICS as $slug) {
            if (! array_key_exists($slug, $row['metrics'])) {
                continue;
            }
            $value = $row['metrics'][$slug];
            $label = MetricDisplay::label($slug);
            $valueStr = MetricDisplay::format($slug, $value);

            $deltaStr = '';
            if ($baseline !== null && isset($baseline[$slug]) && $baseline[$slug] !== null && (float) $baseline[$slug] != 0.0 && $value !== null) {
                $pct = (((float) $value - (float) $baseline[$slug]) / (float) $baseline[$slug]) * 100.0;
                $sign = $pct > 0 ? '+' : '';
                $deltaStr = " ({$sign}".number_format($pct, 1).'%)';
            }

            $metricBits[] = "{$label} <b>{$valueStr}</b>{$deltaStr}";
        }
        $lines[] = '  '.implode(' · ', $metricBits);

        return implode("\n", $lines);
    }

    /** Variant values can be huge (full HTML paragraphs). Cap to a readable length. */
    private function trimVariant(string $value): string
    {
        // Collapse whitespace + decode common HTML entities AIO leaves in.
        $value = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5)));
        if (mb_strlen($value) <= 70) {
            return $value;
        }

        return mb_substr($value, 0, 67).'…';
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
