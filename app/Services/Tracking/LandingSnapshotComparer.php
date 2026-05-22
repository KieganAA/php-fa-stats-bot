<?php

namespace App\Services\Tracking;

use App\Models\LandingSnapshot;

/**
 * Diffs a freshly-captured snapshot against the previous one of the same kind
 * for the same TrackedLanding. Output is a flat map of metric → {abs, pct}.
 *
 * pct is null when the baseline value is 0 (avoids div-by-zero / +Inf%).
 */
final class LandingSnapshotComparer
{
    /**
     * @return array{
     *   current: LandingSnapshot,
     *   prior:   LandingSnapshot|null,
     *   delta:   array<string, array{abs: int|float|null, pct: float|null}>,
     * }
     */
    public function compare(LandingSnapshot $current): array
    {
        $prior = LandingSnapshot::query()
            ->where('tracked_landing_id', $current->tracked_landing_id)
            ->where('kind', $current->kind)
            ->where('id', '!=', $current->id)
            ->where('window_end', '<=', $current->window_start)
            ->orderByDesc('window_end')
            ->first();

        return [
            'current' => $current,
            'prior' => $prior,
            'delta' => $prior ? $this->delta($current->metrics, $prior->metrics) : [],
        ];
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
