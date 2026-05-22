<?php

namespace App\Services\Stats;

use App\Models\Aio\Landing;
use App\Models\Aio\User as AioUser;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\PivotKeys;
use App\Services\Aio\Pivot\TargetMetricSet;
use RuntimeException;

/**
 * Top-N daily-overview reports.
 *
 * Each "kind" is a (dimension key, title, row-label function) tuple:
 *   - geo    → group by location_country_code, label = country code as-is
 *   - buyers → group by campaign_owner_uuid, label = aio_users.name (batch
 *              looked up locally; falls back to "@uuid[…8]" for unknowns)
 *   - lp1    → group by landing_uuids[1], label = LandingFormatter::shortLine
 *   - lp2    → group by landing_uuids[2], label = LandingFormatter::shortLine
 *
 * One AIO call per report; row labels batched against local sync tables to
 * keep the latency invariant in the number of rows returned.
 */
final class RankingReporter
{
    public function __construct(
        private readonly LandingReports $reports,
        private readonly TargetMetricSet $targets,
        private readonly LandingFormatter $landingFmt,
        private readonly RankingFormatter $formatter,
    ) {}

    /**
     * @param  array{from: \DateTimeInterface, to: \DateTimeInterface, timezone: string, label: string}  $window
     * @return string Telegram HTML
     */
    public function report(string $kind, array $window, int $topN = 15, string $sortMetric = 'leads'): string
    {
        $dim = $this->dimensionConfig($kind);

        $pivot = $this->reports->rankByPrimitive(
            filterKey: $dim['filter_key'],
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $uuids = [];
        foreach ($pivot->rows as $row) {
            $uuid = (string) ($row['dimensions']['group_0'] ?? '');
            if ($uuid !== '') {
                $uuids[] = $uuid;
            }
        }
        $labels = $this->batchLabels($kind, $uuids);

        $entries = [];
        foreach ($pivot->rows as $row) {
            $uuid = (string) ($row['dimensions']['group_0'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $metrics = $this->targets->project($row['metrics']);
            $entries[] = [
                'label' => $labels[$uuid] ?? $uuid,
                'metrics' => $metrics,
                'sort_value' => $this->sortValue($metrics, $sortMetric),
            ];
        }

        usort($entries, fn ($a, $b) => ($b['sort_value'] ?? 0) <=> ($a['sort_value'] ?? 0));
        $entries = array_slice($entries, 0, $topN);

        // Drop the helper sort_value before handing to the formatter.
        $clean = array_map(fn ($e) => ['label' => $e['label'], 'metrics' => $e['metrics']], $entries);

        return $this->formatter->format($window, $dim['title'], $clean, $dim['header']);
    }

    /** @param  list<string>  $uuids @return array<string, string> */
    private function batchLabels(string $kind, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $uniq = array_values(array_unique($uuids));

        return match ($kind) {
            'geo' => array_combine($uniq, $uniq),
            'buyers' => $this->batchBuyers($uniq),
            'lp1', 'lp2' => $this->batchLandings($uniq),
            default => array_combine($uniq, $uniq),
        };
    }

    /** @param  list<string>  $uuids @return array<string, string> */
    private function batchBuyers(array $uuids): array
    {
        $users = AioUser::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');

        $out = [];
        foreach ($uuids as $u) {
            $name = $users->get($u)?->name;
            $out[$u] = $name !== null && $name !== '' ? '@'.$name : '@'.substr($u, 0, 8);
        }

        return $out;
    }

    /** @param  list<string>  $uuids @return array<string, string> */
    private function batchLandings(array $uuids): array
    {
        $landings = Landing::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');

        $out = [];
        foreach ($uuids as $u) {
            $landing = $landings->get($u);
            $out[$u] = $landing !== null
                ? $this->landingFmt->shortLine($landing)
                : substr($u, 0, 8).'…';
        }

        return $out;
    }

    /** @param  array<string, int|float|null>  $metrics */
    private function sortValue(array $metrics, string $sortMetric): int|float
    {
        $v = $metrics[$sortMetric] ?? 0;

        return is_numeric($v) ? (float) $v : 0;
    }

    private function dimensionConfig(string $kind): array
    {
        return match ($kind) {
            'geo' => [
                'filter_key' => PivotKeys::COUNTRY,
                'title' => 'топ стран',
                'header' => 'country',
            ],
            'buyers' => [
                'filter_key' => PivotKeys::CAMPAIGN_OWNER,
                'title' => 'топ баеров',
                'header' => 'buyer',
            ],
            'lp1' => [
                'filter_key' => PivotKeys::landingUuid(1),
                'title' => 'топ LP1',
                'header' => 'landing',
            ],
            'lp2' => [
                'filter_key' => PivotKeys::landingUuid(2),
                'title' => 'топ LP2',
                'header' => 'landing',
            ],
            default => throw new RuntimeException("Неизвестный ranking kind: {$kind}"),
        };
    }
}
