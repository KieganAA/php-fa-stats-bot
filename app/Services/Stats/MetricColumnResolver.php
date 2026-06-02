<?php

namespace App\Services\Stats;

use App\Models\User;

/**
 * Single source of truth for "what metrics, in what order, with what labels"
 * — keyed by `context`. Every report (stats, compare, geo, buyers, lp1, lp2,
 * mvt, the 3h tracking push) asks this service for its column spec.
 *
 * Storage shape on User:
 *
 *   settings.metric_presets.<context> = list<string>   // names per context
 *   settings.metric_labels.<name>     = string         // per-name rename
 *
 * Contexts are intentionally narrow so a user who wants "give me a small
 * 3-column geo report but a big 7-column /stats" can tune each independently.
 *
 * Backwards compat: the old single-key `settings.metrics` (Phase Q.2) still
 * works as the stats-context preset until the user picks one explicitly.
 *
 * Renamed labels are GLOBAL — if you rename "Q Visits" to "Qualified" it
 * shows up that way everywhere. That's deliberate: a metric is the same thing
 * regardless of which table it appears in.
 */
final class MetricColumnResolver
{
    public const STATS = 'stats';

    public const COMPARE = 'compare';

    public const GEO = 'geo';

    public const BUYERS = 'buyers';

    public const LP1 = 'lp1';

    public const LP2 = 'lp2';

    public const MVT = 'mvt';

    public const TRACKING = 'tracking';

    /**
     * All recognised contexts. Order is also the order shown in the Mini App
     * tab-switcher, so put the "main" ones (stats, compare) first.
     */
    public const ALL = [
        self::STATS,
        self::COMPARE,
        self::GEO,
        self::BUYERS,
        self::LP1,
        self::LP2,
        self::MVT,
        self::TRACKING,
    ];

    /**
     * Effective AIO metric names for a (user, context) pair. Always returns
     * a non-empty list — defaults plug in when the user has no preference.
     *
     * @return list<string>
     */
    public function namesFor(?User $user, string $context): array
    {
        $context = $this->normalise($context);

        if ($user !== null) {
            $settings = is_array($user->settings) ? $user->settings : [];

            $presets = (array) ($settings['metric_presets'] ?? []);
            $picked = $presets[$context] ?? null;
            if (is_array($picked) && $picked !== []) {
                return $this->cleanNames($picked);
            }

            // Legacy single-key — used to mean "stats context" before this
            // refactor. Keep honouring it so an existing user's pick survives.
            if ($context === self::STATS || $context === self::COMPARE || $context === self::TRACKING) {
                $legacy = $settings['metrics'] ?? null;
                if (is_array($legacy) && $legacy !== []) {
                    return $this->cleanNames($legacy);
                }
            }
        }

        return self::defaultNames($context);
    }

    /**
     * Per-name custom labels (applies to every context the metric shows up in).
     * Empty array means "no overrides — use MetricDisplay::label()".
     *
     * @return array<string, string>
     */
    public function labelsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        $settings = is_array($user->settings) ? $user->settings : [];
        $raw = $settings['metric_labels'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $name => $label) {
            if (! is_string($name) || ! is_string($label)) {
                continue;
            }
            $label = trim($label);
            if ($label === '' || $name === '') {
                continue;
            }
            $out[$name] = $label;
        }

        return $out;
    }

    /**
     * Full column spec: names + resolved label + display kind. Drives both
     * what the formatter shows and how the value is formatted (kind comes
     * from MetricDisplay; labels respect the user's overrides).
     *
     * @return list<array{name: string, label: string, kind: string}>
     */
    public function columnsFor(?User $user, string $context): array
    {
        $names = $this->namesFor($user, $context);
        $overrides = $this->labelsFor($user);

        return self::columnsFromNames($names, $overrides);
    }

    /**
     * Pure helper: build columns from a name list + an optional override map.
     * Used by callers that already know the names (legacy paths, AI handler).
     *
     * @param  list<string>  $names
     * @param  array<string, string>  $labelOverrides
     * @return list<array{name: string, label: string, kind: string}>
     */
    public static function columnsFromNames(array $names, array $labelOverrides = []): array
    {
        $out = [];
        foreach ($names as $name) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'label' => $labelOverrides[$name] ?? MetricDisplay::label($name),
                'kind' => MetricDisplay::kind($name),
            ];
        }

        return $out;
    }

    /**
     * Default name list for a context, no user. Public so artisan / docs /
     * the Mini App "reset" button can preview them.
     *
     * @return list<string>
     */
    public static function defaultNames(string $context): array
    {
        return match ($context) {
            // Wide reports — the canonical 7-metric set.
            self::STATS, self::COMPARE, self::TRACKING => (array) config('aio.default_metrics', MetricDisplay::defaultNames()),

            // Narrow ranking screens — 3 columns fit a phone <code> line.
            self::GEO, self::BUYERS, self::LP1, self::LP2 => MetricDisplay::topNames(),

            // MVT shows per-variant; 4 cols is the sweet spot.
            self::MVT => ['Q Visits', 'Leads', 'Real Approve', 'Total FTDs'],

            default => MetricDisplay::defaultNames(),
        };
    }

    /**
     * True iff the user explicitly picked this context (vs. inheriting defaults
     * or the legacy single-key fallback). Used by the Mini App to render the
     * "reset" affordance differently per tab.
     */
    public function hasCustomPreset(?User $user, string $context): bool
    {
        if ($user === null) {
            return false;
        }
        $settings = is_array($user->settings) ? $user->settings : [];
        $presets = (array) ($settings['metric_presets'] ?? []);
        $picked = $presets[$this->normalise($context)] ?? null;

        return is_array($picked);
    }

    private function normalise(string $context): string
    {
        $c = strtolower(trim($context));
        if (! in_array($c, self::ALL, true)) {
            return self::STATS;
        }

        return $c;
    }

    /** @param  array<int, mixed>  $raw  @return list<string> */
    private function cleanNames(array $raw): array
    {
        $out = [];
        foreach ($raw as $n) {
            if (! is_string($n)) {
                continue;
            }
            $n = trim($n);
            if ($n === '') {
                continue;
            }
            $out[] = $n;
        }

        return array_values(array_unique($out));
    }
}
