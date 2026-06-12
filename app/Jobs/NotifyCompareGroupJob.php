<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Stats\PeriodParser;
use App\Services\Tracking\GroupReportRenderer;
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
 * Push for ONE standalone tracking group (legacy /bind-style subscriptions).
 * Campaign-derived children go through NotifyCampaignJob instead, which packs
 * a whole campaign's sections into a single digest message.
 *
 * Rendering is shared with the digest via GroupReportRenderer; this job just
 * wires it to Telegram delivery + last_notified_at bookkeeping.
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
        GroupReportRenderer $renderer,
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

        // Reports cover the whole current day in the user's timezone — the
        // interval only controls how OFTEN we push, not the window size.
        $window = $periods->parse('today', $user->timezone);
        $html = null;

        try {
            $html = $renderer->render($group, $user, $window);
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

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
