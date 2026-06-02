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
 *
 * Metric set ($metricNames) and per-name label overrides come from the
 * caller — typically the user's MVT-context preset.
 */
final class MvtFormatter
{
    /** Default 4-metric row line when caller doesn't pass a preset. */
    private const DEFAULT_METRICS = ['Q Visits', 'Leads', 'Real Approve', 'Total FTDs'];

    public function __construct(
        private readonly LandingFormatter $landings,
    ) {}

    /**
     * @param  array{landing: Landing, window: array{from: CarbonInterface, to: CarbonInterface, timezone: string, label: string}, rows: list<array{variants: array<string,string>, metrics: array<string,int|float|null>}>, active_slugs: list<string>}  $report
     * @param  list<string>|null  $metricNames  override the displayed metric set
     * @param  array<string, string>  $labelOverrides  per-name custom labels
     */
    public function format(array $report, ?array $metricNames = null, array $labelOverrides = []): string
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

        $shown = $metricNames !== null && $metricNames !== [] ? $metricNames : self::DEFAULT_METRICS;

        $blocks = [$header];

        // Sort rows by leads desc — leader on top.
        usort($rows, fn ($a, $b) => ($b['metrics']['Leads'] ?? 0) <=> ($a['metrics']['Leads'] ?? 0));

        $baselineMetrics = $rows[0]['metrics'];
        foreach ($rows as $i => $row) {
            $blocks[] = $this->renderRow(
                $row,
                $active,
                $i === 0 ? null : $baselineMetrics,
                $i + 1,
                $shown,
                $labelOverrides,
            );
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
     * @param  list<string>  $shown
     * @param  array<string, string>  $labelOverrides
     */
    private function renderRow(
        array $row,
        array $activeSlugs,
        ?array $baseline,
        int $rank,
        array $shown,
        array $labelOverrides,
    ): string {
        $lines = [];
        $rankBadge = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : "#{$rank}"));
        $lines[] = "{$rankBadge}";

        foreach ($activeSlugs as $slug) {
            $value = $row['variants'][$slug] ?? '';
            $shownVal = $value === '' ? '∅' : $this->trimVariant($value);
            $lines[] = "  <b>{$this->escape($slug)}</b> = "."<code>{$this->escape($shownVal)}</code>";
        }

        // Metric line
        $metricBits = [];
        foreach ($shown as $name) {
            if (! array_key_exists($name, $row['metrics'])) {
                continue;
            }
            $value = $row['metrics'][$name];
            $label = $labelOverrides[$name] ?? MetricDisplay::label($name);
            $valueStr = MetricDisplay::format($name, $value);

            $deltaStr = '';
            if ($baseline !== null && isset($baseline[$name]) && $baseline[$name] !== null && (float) $baseline[$name] != 0.0 && $value !== null) {
                $pct = (((float) $value - (float) $baseline[$name]) / (float) $baseline[$name]) * 100.0;
                $sign = $pct > 0 ? '+' : '';
                $deltaStr = " ({$sign}".number_format($pct, 1).'%)';
            }

            $metricBits[] = "{$this->escape($label)} <b>{$this->escape($valueStr)}</b>{$deltaStr}";
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
