<?php

namespace App\Services\Aio\Pivot;

use App\Models\MvtSlice;
use App\Models\TrackedLanding;
use App\Services\Aio\Dto\PivotResponse;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Captures one MVT slice for a TrackedLanding:
 *   - calls LandingReports::mvtBreakdown over the chosen window
 *   - decodes content_object dimension values to plain text
 *   - replaces group_N keys with the corresponding MVT field slug
 *   - projects raw uuid metrics onto the configured target slugs
 *   - persists as an MvtSlice row
 *
 * Two kinds of slice are produced per cycle:
 *   - 3h:          last 3 hours (rolling, used for current vs prior comparison)
 *   - since_start: tracking_started_at → now (long-term baseline)
 *
 * Empty-string dimension values are aggregate rows ("all variants for this
 * dimension"); they are kept in the persisted rows so the comparer/formatter
 * can choose to surface or filter them.
 */
class MvtSlicer
{
    public function __construct(
        private readonly LandingReports $reports,
        private readonly TargetMetricSet $targets,
    ) {}

    public function captureBoth(
        TrackedLanding $landing,
        ?DateTimeInterface $now = null,
        string $timezone = 'UTC',
    ): array {
        $now ??= new DateTimeImmutable('now');

        return [
            $this->capture($landing, MvtSlice::KIND_3H, $now, $timezone),
            $this->capture($landing, MvtSlice::KIND_SINCE_START, $now, $timezone),
        ];
    }

    public function capture(
        TrackedLanding $landing,
        string $kind,
        ?DateTimeInterface $now = null,
        string $timezone = 'UTC',
    ): MvtSlice {
        $now ??= new DateTimeImmutable('now');
        [$start, $end] = $this->resolveWindow($landing, $kind, $now);

        $fields = $landing->mvtFields()->orderBy('tracked_landing_fields.created_at')->get()->all();
        if ($fields === []) {
            throw new RuntimeException(
                "TrackedLanding #{$landing->id} has no MVT fields — cannot slice."
            );
        }

        $response = $this->reports->mvtBreakdown(
            landingUuid: $landing->landing_uuid,
            position: (int) $landing->position,
            mvtFields: $fields,
            from: $start,
            to: $end,
            timezone: $timezone,
        );

        $rows = $this->buildRows($response, $fields);

        return MvtSlice::create([
            'tracked_landing_id' => $landing->id,
            'kind' => $kind,
            'window_start' => $start,
            'window_end' => $end,
            'rows' => $rows,
            'captured_at' => $now,
        ]);
    }

    /**
     * @param  list<\App\Models\Aio\Field>  $fields  in the same order they were passed to mvtBreakdown
     * @return list<array{dimensions: array<string,string>, metrics: array<string,int|float|null>}>
     */
    private function buildRows(PivotResponse $response, array $fields): array
    {
        $slugByGroup = [];
        foreach (array_values($fields) as $i => $field) {
            $slugByGroup["group_{$i}"] = $field->slug;
        }

        $out = [];
        foreach ($response->rows as $row) {
            $dimensions = [];
            foreach ($row['dimensions'] as $groupKey => $value) {
                $slug = $slugByGroup[$groupKey] ?? $groupKey;
                $dimensions[$slug] = VariantValueDecoder::decode((string) $value);
            }

            $out[] = [
                'dimensions' => $dimensions,
                'metrics' => $this->targets->project($row['metrics']),
            ];
        }

        return $out;
    }

    /**
     * @return array{0: DateTimeInterface, 1: DateTimeInterface}
     */
    private function resolveWindow(TrackedLanding $landing, string $kind, DateTimeInterface $now): array
    {
        return match ($kind) {
            MvtSlice::KIND_3H => [
                (new DateTimeImmutable('@'.$now->getTimestamp()))->modify('-3 hours'),
                $now,
            ],
            MvtSlice::KIND_SINCE_START => [
                $landing->tracking_started_at?->toDateTimeImmutable()
                    ?? throw new RuntimeException("TrackedLanding #{$landing->id} has no tracking_started_at"),
                $now,
            ],
            default => throw new RuntimeException("Unknown MvtSlice kind: {$kind}"),
        };
    }
}
