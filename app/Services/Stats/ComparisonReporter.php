<?php

namespace App\Services\Stats;

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use DateTimeInterface;
use RuntimeException;

/**
 * Orchestrates a multi-primitive comparison: resolve all tokens, sanity-check
 * they share the same dimension key (e.g. all landings on the same LP, or all
 * countries), query AIO once, project metrics, and render the side-by-side
 * report.
 *
 * Used by both the ad-hoc /compare command and the future scheduled
 * compare-group notifier — they all need the same "from list-of-tokens to
 * Telegram HTML" pipeline.
 */
final class ComparisonReporter
{
    public function __construct(
        private readonly PrimitiveResolver $resolver,
        private readonly LandingReports $reports,
        private readonly TargetMetricSet $targets,
        private readonly ComparisonFormatter $formatter,
    ) {}

    /**
     * @param  list<string>  $tokens
     * @param  array{from: DateTimeInterface, to: DateTimeInterface, timezone: string, label: string}  $window
     * @param  list<string>|null  $metricNames  AIO metric names; null = defaults
     * @param  array<string, string>  $labelOverrides  per-name display labels
     * @return string  Telegram HTML
     */
    public function report(array $tokens, array $window, ?array $metricNames = null, array $labelOverrides = []): string
    {
        if (count($tokens) < 2) {
            throw new RuntimeException('Нужно минимум 2 примитива для сравнения.');
        }

        $resolved = array_map(fn (string $t) => $this->resolver->resolve($t), $tokens);

        // All primitives must share the same dimension key — otherwise it's
        // not a side-by-side, it's apples vs oranges.
        $keys = array_unique(array_map(fn ($r) => $r['filter_key'], $resolved));
        if (count($keys) > 1) {
            throw new RuntimeException(
                'Compare принимает примитивы одного типа: только страны, либо только лендинги (на одной позиции).'
            );
        }
        $filterKey = $keys[0];

        $values = array_map(fn ($r) => (string) $r['filter_value'], $resolved);

        $pivot = $this->reports->compareByPrimitive(
            filterKey: $filterKey,
            filterValues: $values,
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $byValue = [];
        foreach ($pivot->rows as $row) {
            $value = (string) ($row['dimensions']['group_0'] ?? '');
            if ($value === '') {
                continue;
            }
            $byValue[$value] = $this->targets->project($row['metrics'], $metricNames);
        }

        $entries = [];
        foreach ($resolved as $r) {
            $entries[] = [
                'label' => $r['label'],
                'metrics' => $byValue[(string) $r['filter_value']] ?? [],
            ];
        }

        return $this->formatter->format($window, $entries, $metricNames, $labelOverrides);
    }
}
