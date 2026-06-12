<?php

namespace App\Services\Tracking;

use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Stats\ComparisonReporter;
use App\Services\Stats\MetricColumnResolver;
use App\Services\Stats\MvtFormatter;
use App\Services\Stats\MvtReporter;

/**
 * Renders ONE compare/MVT group into Telegram HTML — shared by the standalone
 * group notifier and the per-campaign digest. Returns null when the group's
 * members can't be resolved through the local landing catalog OR (in digest
 * mode) when the window has zero traffic, so the caller can collapse the
 * section instead of sending a dash-filled table.
 */
final class GroupReportRenderer
{
    public function __construct(
        private readonly ComparisonReporter $compare,
        private readonly MvtReporter $mvt,
        private readonly MvtFormatter $mvtFormatter,
    ) {}

    /**
     * @param  array{from: \DateTimeInterface, to: \DateTimeInterface, timezone: string, label: string}  $window
     */
    public function render(UserCompareGroup $group, User $user, array $window, bool $digestMode = false): ?string
    {
        $campaignUuid = $group->campaignSubscription?->campaign_uuid;
        $labels = $user->metricLabelOverrides();

        if (($group->mode ?? UserCompareGroup::MODE_COMPARE) === UserCompareGroup::MODE_MVT) {
            return $this->renderMvt($group, $window, $user->metricNamesFor(MetricColumnResolver::MVT), $labels, $campaignUuid, $digestMode);
        }

        return $this->renderCompare($group, $window, $user->metricNamesFor(MetricColumnResolver::TRACKING), $labels, $campaignUuid, $digestMode);
    }

    /**
     * @param  list<string>|null  $metricNames
     * @param  array<string, string>  $labelOverrides
     */
    private function renderCompare(UserCompareGroup $group, array $window, ?array $metricNames, array $labelOverrides, ?string $campaignUuid, bool $digestMode): ?string
    {
        $tokens = [];
        $position = 1;
        foreach ($group->members as $m) {
            $landing = $m->trackedLanding?->landing;
            if ($landing === null) {
                continue;
            }
            // Split members all live on the same funnel step — querying the
            // wrong position returns zero rows, so carry the tracked position.
            $position = (int) ($m->trackedLanding->position ?? 1);
            $tokens[] = $landing->human_id !== null ? (string) $landing->human_id : $landing->uuid;
        }
        if (count($tokens) < 2) {
            return null; // compare requires ≥2
        }

        return $this->compare->report(
            $tokens,
            $window,
            $metricNames,
            $labelOverrides,
            $campaignUuid,
            $position,
            nullIfEmpty: $digestMode,
            withHeader: ! $digestMode,
        );
    }

    /**
     * @param  list<string>|null  $metricNames
     * @param  array<string, string>  $labelOverrides
     */
    private function renderMvt(UserCompareGroup $group, array $window, ?array $metricNames, array $labelOverrides, ?string $campaignUuid, bool $digestMode): ?string
    {
        $member = $group->members->first();
        $landing = $member?->trackedLanding?->landing;
        if ($landing === null) {
            return null;
        }

        $position = (int) ($member->trackedLanding->position ?? 1);
        $report = $this->mvt->report($landing, $window, $position, $campaignUuid);

        if ($digestMode && $report['rows'] === []) {
            return null; // no variant traffic in the window
        }

        return $this->mvtFormatter->format($report, $metricNames, $labelOverrides, withHeader: ! $digestMode);
    }
}
