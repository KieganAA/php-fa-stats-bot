<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Stats\ComparisonReporter;
use App\Services\Stats\MetricColumnResolver;
use App\Services\Stats\MvtFormatter;
use App\Services\Stats\MvtReporter;
use App\Services\Stats\PeriodParser;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Periodic 3h tracking-group push.
 *
 * Branches on group mode:
 *   - 'compare' → ComparisonReporter (same path as /compare)
 *   - 'mvt'     → MvtReporter (same path as /mvt)
 *
 * Both reporters live elsewhere; this job just wires them to Telegram delivery
 * + last_notified_at bookkeeping.
 */
class NotifyCompareGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $userId,
        public readonly int $groupId,
    ) {}

    public function handle(
        Nutgram $bot,
        ComparisonReporter $compare,
        MvtReporter $mvt,
        MvtFormatter $mvtFormatter,
        PeriodParser $periods,
    ): void {
        $user = User::query()->find($this->userId);
        $group = UserCompareGroup::query()
            ->with('members.trackedLanding.landing', 'campaignSubscription')
            ->find($this->groupId);

        if ($user === null || $group === null) {
            return; // cleaned up since dispatch
        }
        if (! $user->telegram_user_id) {
            return;
        }
        if ($group->paused_at !== null) {
            return;
        }
        // Orphaned campaign children await a keep/delete decision — don't push.
        if ($group->orphaned_at !== null) {
            return;
        }
        // A paused parent campaign subscription gates all its children.
        if ($group->campaignSubscription !== null && $group->campaignSubscription->paused_at !== null) {
            return;
        }

        // Campaign children scope their report to the owning campaign so a
        // landing reused across campaigns doesn't pollute the numbers.
        $campaignUuid = $group->campaignSubscription?->campaign_uuid;

        // Reports cover the whole current day in the user's timezone — the
        // interval only controls how OFTEN we push, not the window size.
        $window = $periods->parse('today', $user->timezone);
        $html = null;

        try {
            $compareNames = $user->metricNamesFor(MetricColumnResolver::TRACKING);
            $mvtNames = $user->metricNamesFor(MetricColumnResolver::MVT);
            $labels = $user->metricLabelOverrides();
            $html = match ($group->mode ?? UserCompareGroup::MODE_COMPARE) {
                UserCompareGroup::MODE_MVT => $this->renderMvt($group, $window, $mvt, $mvtFormatter, $mvtNames, $labels, $campaignUuid),
                default => $this->renderCompare($group, $window, $compare, $compareNames, $labels, $campaignUuid),
            };
        } catch (Throwable $e) {
            Log::warning('tracking-group notify failed', [
                'user_id' => $this->userId,
                'group_id' => $this->groupId,
                'mode' => $group->mode,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if ($html === null) {
            // Renderers bail (null) when members can't be resolved through the
            // local landing catalog. Loud log — a silent "DONE" here cost us a
            // mute-push debugging session once already.
            Log::warning('tracking-group notify skipped: members unresolved', [
                'group_id' => $this->groupId,
                'mode' => $group->mode,
                'member_uuids' => $group->members->map(
                    fn ($m) => $m->trackedLanding?->landing_uuid,
                )->all(),
            ]);

            return;
        }

        $header = "<b>🔔 group <code>{$this->escape($group->name)}</code></b>\n";
        $bot->sendMessage(
            text: $header.$html,
            chat_id: (int) $user->telegram_user_id,
            parse_mode: 'HTML',
            disable_web_page_preview: true,
        );

        $group->last_notified_at = CarbonImmutable::now();
        $group->save();
    }

    /**
     * @param  list<string>|null  $metricNames
     * @param  array<string, string>  $labelOverrides
     */
    private function renderCompare(UserCompareGroup $group, array $window, ComparisonReporter $compare, ?array $metricNames, array $labelOverrides, ?string $campaignUuid = null): ?string
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

        return $compare->report($tokens, $window, $metricNames, $labelOverrides, $campaignUuid, $position);
    }

    /**
     * @param  list<string>|null  $metricNames
     * @param  array<string, string>  $labelOverrides
     */
    private function renderMvt(UserCompareGroup $group, array $window, MvtReporter $mvt, MvtFormatter $fmt, ?array $metricNames, array $labelOverrides, ?string $campaignUuid = null): ?string
    {
        $member = $group->members->first();
        $landing = $member?->trackedLanding?->landing;
        if ($landing === null) {
            return null;
        }

        $position = (int) ($member->trackedLanding->position ?? 1);
        $report = $mvt->report($landing, $window, $position, $campaignUuid);

        return $fmt->format($report, $metricNames, $labelOverrides);
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
