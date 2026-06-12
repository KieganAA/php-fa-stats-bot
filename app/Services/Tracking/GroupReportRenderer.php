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
    /** Funnel positions to probe when the structure guess yields no data. */
    private const CANDIDATE_POSITIONS = [1, 2, 3, 4];

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
        $structurePosition = 1;
        foreach ($group->members as $m) {
            $landing = $m->trackedLanding?->landing;
            if ($landing === null) {
                continue;
            }
            $structurePosition = (int) ($m->trackedLanding->position ?? 1);
            $tokens[] = $landing->human_id !== null ? (string) $landing->human_id : $landing->uuid;
        }
        if (count($tokens) < 2) {
            return null; // compare requires ≥2
        }

        $render = fn (int $pos, bool $nullIfEmpty): ?string => $this->compare->report(
            $tokens, $window, $metricNames, $labelOverrides, $campaignUuid, $pos,
            nullIfEmpty: $nullIfEmpty, withHeader: ! $digestMode,
        );

        return $this->withResolvedPosition($group, $campaignUuid, $structurePosition, $digestMode, $render);
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

        $structurePosition = (int) ($member->trackedLanding->position ?? 1);

        $render = function (int $pos, bool $nullIfEmpty) use ($landing, $window, $metricNames, $labelOverrides, $campaignUuid, $digestMode): ?string {
            $report = $this->mvt->report($landing, $window, $pos, $campaignUuid);
            if ($nullIfEmpty && $report['rows'] === []) {
                return null;
            }

            return $this->mvtFormatter->format($report, $metricNames, $labelOverrides, withHeader: ! $digestMode);
        };

        return $this->withResolvedPosition($group, $campaignUuid, $structurePosition, $digestMode, $render);
    }

    /**
     * Run $render at the right funnel position, self-healing the structure
     * guess. Once a position with data is found it's cached on the group, so
     * later pushes go straight there. Non-campaign groups (no scope) just
     * render at their stored position.
     *
     * @param  callable(int, bool): ?string  $render  (position, nullIfEmpty) → html|null
     */
    private function withResolvedPosition(UserCompareGroup $group, ?string $campaignUuid, int $structurePosition, bool $digestMode, callable $render): ?string
    {
        // Non-campaign groups (legacy /bind): no position ambiguity to resolve.
        if ($campaignUuid === null) {
            return $render($structurePosition, $digestMode);
        }

        // Already detected once — trust it, no probing (even on a zero-traffic
        // day, where every position would read empty anyway).
        if ($group->resolved_position !== null) {
            return $render($group->resolved_position, $digestMode);
        }

        // Never detected: AIO's settings-dict step order can disagree with the
        // analytics LP position (even be reversed), so probe candidates and
        // cache the first that returns data.
        $candidates = array_values(array_unique([$structurePosition, ...self::CANDIDATE_POSITIONS]));
        foreach ($candidates as $pos) {
            $html = $render($pos, true);
            if ($html !== null) {
                $group->resolved_position = $pos;
                $group->save();

                return $html;
            }
        }

        // Nothing anywhere this window — digest collapses the section; a
        // standalone group still shows its (empty) table at the guess.
        return $digestMode ? null : $render($structurePosition, false);
    }
}
