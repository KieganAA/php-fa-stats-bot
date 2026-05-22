<?php

namespace App\Services\Tracking;

use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Captures aggregate snapshots for a TrackedLanding. Two kinds:
 *   - 3h           rolling, used by the scheduler for "what just changed?"
 *   - since_start  tracking_started_at → now, baseline
 *
 * Sibling to MvtSlicer but lighter: no MVT field breakdown, just the
 * total metrics projected onto the configured target slugs.
 */
final class LandingSnapshotter
{
    public function __construct(
        private readonly LandingReports $reports,
        private readonly TargetMetricSet $targets,
    ) {}

    /** @return array{0: LandingSnapshot, 1: LandingSnapshot} */
    public function captureBoth(
        TrackedLanding $landing,
        ?DateTimeInterface $now = null,
        string $timezone = 'UTC',
    ): array {
        $now ??= CarbonImmutable::now();

        return [
            $this->capture($landing, LandingSnapshot::KIND_3H, $now, $timezone),
            $this->capture($landing, LandingSnapshot::KIND_SINCE_START, $now, $timezone),
        ];
    }

    public function capture(
        TrackedLanding $landing,
        string $kind,
        ?DateTimeInterface $now = null,
        string $timezone = 'UTC',
    ): LandingSnapshot {
        $now ??= CarbonImmutable::now();
        [$start, $end] = $this->resolveWindow($landing, $kind, $now);

        $response = $this->reports->landingStats(
            landingUuid: $landing->landing_uuid,
            position: (int) $landing->position,
            from: $start,
            to: $end,
            timezone: $timezone,
        );

        $raw = $response->rows[0]['metrics'] ?? [];
        $metrics = $this->targets->project($raw);

        return LandingSnapshot::create([
            'tracked_landing_id' => $landing->id,
            'kind' => $kind,
            'window_start' => $start,
            'window_end' => $end,
            'metrics' => $metrics,
            'captured_at' => $now,
        ]);
    }

    /** @return array{0: DateTimeInterface, 1: DateTimeInterface} */
    private function resolveWindow(TrackedLanding $landing, string $kind, DateTimeInterface $now): array
    {
        $immutableNow = CarbonImmutable::instance($now);

        return match ($kind) {
            LandingSnapshot::KIND_3H => [
                $immutableNow->subHours(3),
                $immutableNow,
            ],
            LandingSnapshot::KIND_SINCE_START => [
                $landing->tracking_started_at?->toImmutable()
                    ?? throw new RuntimeException("TrackedLanding #{$landing->id} has no tracking_started_at"),
                $immutableNow,
            ],
            default => throw new RuntimeException("Unknown LandingSnapshot kind: {$kind}"),
        };
    }
}
