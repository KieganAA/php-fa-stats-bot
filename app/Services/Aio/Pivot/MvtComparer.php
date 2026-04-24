<?php

namespace App\Services\Aio\Pivot;

use App\Models\MvtSlice;
use App\Models\TrackedLanding;

/**
 * Compares a freshly-captured 3h MvtSlice against:
 *   - the prior 3h slice for the same TrackedLanding (last one before current.window_start)
 *   - the latest since_start slice for the same TrackedLanding
 *
 * Aligns rows by their dimension tuple (e.g. {lp_landing_header: "A", lp_button: "blue"}).
 * Missing comparisons (no prior slice yet) come back as null so the formatter can render
 * "—" or skip the column.
 *
 * Output shape:
 *   [
 *     'current'      => MvtSlice,
 *     'prior'        => MvtSlice|null,
 *     'since_start'  => MvtSlice|null,
 *     'rows'         => [
 *       [
 *         'dimensions'        => ['<slug>' => '<variant>', ...],
 *         'current'           => ['<metric>' => <value>|null, ...],
 *         'prior'             => ['<metric>' => <value>|null, ...]|null,
 *         'since_start'       => ['<metric>' => <value>|null, ...]|null,
 *         'delta_prior'       => ['<metric>' => ['abs'=>n, 'pct'=>n|null], ...]|null,
 *         'delta_since_start' => ['<metric>' => ['abs'=>n, 'pct'=>n|null], ...]|null,
 *       ],
 *       ...
 *     ],
 *   ]
 */
class MvtComparer
{
    public function compare(MvtSlice $current): array
    {
        $prior = $this->findPriorThreeHour($current);
        $sinceStart = $this->findLatestSinceStart($current);

        $rows = [];
        foreach ($current->rows as $row) {
            $key = $this->dimKey($row['dimensions']);
            $priorRow = $prior ? $this->findRowByKey($prior->rows, $key) : null;
            $sinceRow = $sinceStart ? $this->findRowByKey($sinceStart->rows, $key) : null;

            $rows[] = [
                'dimensions' => $row['dimensions'],
                'current' => $row['metrics'],
                'prior' => $priorRow['metrics'] ?? null,
                'since_start' => $sinceRow['metrics'] ?? null,
                'delta_prior' => $priorRow ? $this->delta($row['metrics'], $priorRow['metrics']) : null,
                'delta_since_start' => $sinceRow ? $this->delta($row['metrics'], $sinceRow['metrics']) : null,
            ];
        }

        return [
            'current' => $current,
            'prior' => $prior,
            'since_start' => $sinceStart,
            'rows' => $rows,
        ];
    }

    private function findPriorThreeHour(MvtSlice $current): ?MvtSlice
    {
        return MvtSlice::query()
            ->where('tracked_landing_id', $current->tracked_landing_id)
            ->where('kind', MvtSlice::KIND_3H)
            ->where('id', '!=', $current->id)
            ->where('window_end', '<=', $current->window_start)
            ->orderByDesc('window_end')
            ->first();
    }

    private function findLatestSinceStart(MvtSlice $current): ?MvtSlice
    {
        return MvtSlice::query()
            ->where('tracked_landing_id', $current->tracked_landing_id)
            ->where('kind', MvtSlice::KIND_SINCE_START)
            ->where('id', '!=', $current->id)
            ->orderByDesc('window_end')
            ->first();
    }

    /**
     * @param  list<array{dimensions: array<string,string>, metrics: array<string,int|float|null>}>  $rows
     */
    private function findRowByKey(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            if ($this->dimKey($row['dimensions']) === $key) {
                return $row;
            }
        }

        return null;
    }

    /** @param  array<string,string>  $dimensions */
    private function dimKey(array $dimensions): string
    {
        ksort($dimensions);

        return json_encode($dimensions, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string,int|float|null>  $current
     * @param  array<string,int|float|null>  $baseline
     * @return array<string, array{abs: int|float|null, pct: float|null}>
     */
    private function delta(array $current, array $baseline): array
    {
        $out = [];
        foreach ($current as $slug => $value) {
            $base = $baseline[$slug] ?? null;
            if ($value === null || $base === null) {
                $out[$slug] = ['abs' => null, 'pct' => null];

                continue;
            }
            $abs = $value - $base;
            $pct = $base != 0.0 ? $abs / $base : null;
            $out[$slug] = ['abs' => $abs, 'pct' => $pct];
        }

        return $out;
    }
}
