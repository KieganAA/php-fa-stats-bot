<?php

namespace App\Services\Stats;

/**
 * Single source of truth for "how do we render this AIO metric to the user".
 *
 * Each AIO metric is identified by its `aio_metrics.name`. We compute three
 * things per metric:
 *
 *   - label: short caption shown in tables/reports (kept narrow on purpose
 *            so phone layouts don't wrap)
 *   - kind:  one of count / ratio / percent / money — drives value formatting
 *   - format($value): the rendered string
 *
 * Kinds:
 *   count   → integer, thousands separator   (e.g. "2 081")
 *   ratio   → 0..1 from AIO → ×100 + '%'      (e.g. 0.1176 → "11.76%")
 *   percent → already 0..100 → just append %  (e.g. 27.93 → "27.93%")
 *   money   → count style + ' $' suffix       (e.g. "135.29 $")
 *
 * We hardcode overrides for the metrics we ship with by default — those
 * names also drive the canonical short-label vocabulary buyers expect
 * ("clicks", "CR%", "FTDs"). For everything else, kind is inferred from
 * the AIO name suffix (% / $) and a few keyword rules (CTR, CR, Rate,
 * Approve → ratio).
 *
 * defaultNames() returns the curated set shown when the user hasn't picked
 * their own list yet — same 7 metrics as before, just keyed by AIO name now.
 */
final class MetricDisplay
{
    public const KIND_COUNT = 'count';

    public const KIND_RATIO = 'ratio';

    public const KIND_PERCENT = 'percent';

    public const KIND_MONEY = 'money';

    /**
     * Curated displays for the default set. Names match aio_metrics.name
     * exactly (case-sensitive — that's how AIO returns them).
     *
     * @var array<string, array{label: string, kind: string}>
     */
    private const OVERRIDES = [
        'Q Visits' => ['label' => 'clicks', 'kind' => self::KIND_COUNT],
        'Q LP1 CTR' => ['label' => 'LP CTR', 'kind' => self::KIND_RATIO],
        'Leads' => ['label' => 'leads', 'kind' => self::KIND_COUNT],
        'Total FTDs' => ['label' => 'FTDs', 'kind' => self::KIND_COUNT],
        'Real Approve' => ['label' => 'CR%', 'kind' => self::KIND_RATIO],
        'LP1 Interest Rate' => ['label' => 'interest', 'kind' => self::KIND_RATIO],
        'Q LP1 Scroll Avg' => ['label' => 'scroll', 'kind' => self::KIND_PERCENT],
    ];

    /** AIO metric names shown when the user has no custom preference. */
    public static function defaultNames(): array
    {
        return array_keys(self::OVERRIDES);
    }

    /** Top-N overview screens use a narrower set (3 columns fit a phone). */
    public static function topNames(): array
    {
        return ['Q Visits', 'Leads', 'Real Approve'];
    }

    /** Short display caption for the metric. */
    public static function label(string $name): string
    {
        return self::OVERRIDES[$name]['label'] ?? self::shortenName($name);
    }

    public static function kind(string $name): string
    {
        return self::OVERRIDES[$name]['kind'] ?? self::inferKind($name);
    }

    public static function format(string $name, int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match (self::kind($name)) {
            self::KIND_RATIO => self::pct((float) $value * 100),
            self::KIND_PERCENT => self::pct((float) $value),
            self::KIND_MONEY => self::count($value).' $',
            default => self::count($value),
        };
    }

    /**
     * For a list of AIO names, return parallel arrays of label + kind — useful
     * for API responses (so the Mini App can pick metrics from a known menu).
     *
     * @param  list<string>  $names
     * @return list<array{name: string, label: string, kind: string}>
     */
    public static function describe(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[] = [
                'name' => $name,
                'label' => self::label($name),
                'kind' => self::kind($name),
            ];
        }

        return $out;
    }

    /**
     * Heuristic — invoked when no explicit override exists. Designed to handle
     * the ~80 metrics in our AIO without manual labelling each one.
     */
    private static function inferKind(string $name): string
    {
        $lower = strtolower($name);

        if (str_ends_with($name, '$')) {
            return self::KIND_MONEY;
        }
        if (str_ends_with($name, '%')) {
            return self::KIND_RATIO;
        }
        // Names that AIO stores as already-percent (0..100). The only one we
        // know of right now is the scroll average; add more here as they
        // surface (the override list above will usually win first anyway).
        if (str_contains($lower, 'scroll avg')) {
            return self::KIND_PERCENT;
        }
        // Conversion-flavoured names without explicit % suffix. Most of these
        // come back from AIO as 0..1 floats and need ×100 for display.
        if (
            str_contains($lower, 'ctr')
            || str_contains($lower, ' rate')
            || str_ends_with($lower, 'rate')
            || str_contains($lower, 'approve')
            || preg_match('/\bcr\b/', $lower)
        ) {
            return self::KIND_RATIO;
        }

        return self::KIND_COUNT;
    }

    /**
     * Mild abbreviation for names we don't have an override for. Keeps long
     * AIO names ("Q LP1 CR FTD Per Mile") from blowing out table layout.
     */
    private static function shortenName(string $name): string
    {
        $name = trim($name);
        if (mb_strlen($name) <= 12) {
            return $name;
        }

        return mb_substr($name, 0, 11).'…';
    }

    private static function pct(float $value): string
    {
        return number_format($value, 2).'%';
    }

    /**
     * Compact integer formatting that fits in Telegram's mobile `<code>` width.
     *
     * 1234567 → "1.23M"   (was "1 234 567" = 9 chars, now 5)
     * 12345   → "12.3K"   (was "12 345"    = 6 chars, now 5)
     * 999     → "999"
     *
     * Fractional counts get one decimal at most. Keeps every cell narrow
     * enough that 3-4 metric columns + label fit on a phone line without
     * wrapping mid-row.
     */
    private static function count(int|float $value): string
    {
        $v = (float) $value;
        $abs = abs($v);

        if ($abs >= 1_000_000) {
            return self::trimZeros(number_format($v / 1_000_000, 2)).'M';
        }
        if ($abs >= 10_000) {
            return self::trimZeros(number_format($v / 1000, 1)).'K';
        }
        if (is_int($value) || floor($v) == $v) {
            return (string) (int) $v;
        }

        return self::trimZeros(number_format($v, 2));
    }

    private static function trimZeros(string $formatted): string
    {
        // "1.20" → "1.2"; "1.00" → "1"
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }
}
