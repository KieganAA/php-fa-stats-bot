<?php

namespace App\Services\Stats;

/**
 * Single source of truth for "how do we render this metric to the user".
 *
 * Each AIO metric we project to has:
 *   - a short display label (what the user reads in the table)
 *   - a kind that controls value formatting:
 *       count   → integer with thousands separator
 *       ratio   → 0..1 from AIO  → multiply by 100, append '%'  (e.g. CR, CTR)
 *       percent → already 0..100 → append '%' as-is             (e.g. scroll avg)
 *
 * The display order is the iteration order of SPEC — formatters loop over
 * MetricDisplay::order() so adding/reordering a metric is one-place.
 *
 * NB: AIO column names live in config/aio.php under target_metrics — that's
 * the *projection* layer (slug → AIO metric name). This class is the
 * *presentation* layer (slug → human label + display formatter). They're
 * deliberately separate so a metric mapping change doesn't ripple into UI
 * tweaks and vice-versa.
 */
final class MetricDisplay
{
    public const KIND_COUNT = 'count';

    /** AIO returns 0..1; UI shows percent. */
    public const KIND_RATIO = 'ratio';

    /** AIO returns 0..100 already (e.g. "Q LP1 Scroll Avg"). */
    public const KIND_PERCENT = 'percent';

    /**
     * @var array<string, array{label: string, kind: string}>
     */
    private const SPEC = [
        'clicks' => ['label' => 'clicks', 'kind' => self::KIND_COUNT],
        'lp_ctr' => ['label' => 'LP CTR', 'kind' => self::KIND_RATIO],
        'leads' => ['label' => 'leads', 'kind' => self::KIND_COUNT],
        'ftds_real' => ['label' => 'FTDs', 'kind' => self::KIND_COUNT],
        'real_cr' => ['label' => 'CR%', 'kind' => self::KIND_RATIO],
        'interest_rate' => ['label' => 'interest', 'kind' => self::KIND_RATIO],
        'scrolling' => ['label' => 'scroll', 'kind' => self::KIND_PERCENT],
    ];

    /** Ordered slugs (display order). */
    public static function order(): array
    {
        return array_keys(self::SPEC);
    }

    public static function label(string $slug): string
    {
        return self::SPEC[$slug]['label'] ?? $slug;
    }

    public static function kind(string $slug): string
    {
        return self::SPEC[$slug]['kind'] ?? self::KIND_COUNT;
    }

    /** Format a single value for display ("88", "1 234", "11.76%", "—"). */
    public static function format(string $slug, int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match (self::kind($slug)) {
            self::KIND_RATIO => self::pct((float) $value * 100),
            self::KIND_PERCENT => self::pct((float) $value),
            default => self::count($value),
        };
    }

    /** Subset useful for narrower overview tables (Top screens). */
    public static function topOrder(): array
    {
        return ['clicks', 'leads', 'real_cr'];
    }

    private static function pct(float $value): string
    {
        return number_format($value, 2).'%';
    }

    private static function count(int|float $value): string
    {
        if (is_int($value) || floor((float) $value) == $value) {
            return number_format((int) $value, 0, '.', ' ');
        }

        return number_format((float) $value, 2);
    }
}
