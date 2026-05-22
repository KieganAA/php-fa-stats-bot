<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Stats\ComparisonReporter;
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
 * Periodic 3h compare report for one user's compare group. Re-uses the same
 * ComparisonReporter that powers the ad-hoc /compare command — single source
 * of truth for what "compare" looks like.
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
        ComparisonReporter $reporter,
        PeriodParser $periods,
    ): void {
        $user = User::query()->find($this->userId);
        $group = UserCompareGroup::query()
            ->with('members.trackedLanding.landing')
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

        $tokens = [];
        foreach ($group->members as $m) {
            $landing = $m->trackedLanding?->landing;
            if ($landing === null) {
                continue;
            }
            // Prefer human_id (what the user typed). Fall back to uuid.
            $tokens[] = $landing->human_id !== null ? (string) $landing->human_id : $landing->uuid;
        }

        if (count($tokens) < 2) {
            // Single-member groups don't have a Δ% column worth showing — skip
            // for now. (Future: route through stats instead of compare.)
            return;
        }

        try {
            $window = $periods->parse('3h', $user->timezone);
            $html = $reporter->report($tokens, $window);

            $header = "<b>🔔 group <code>{$this->escape($group->name)}</code></b>\n";
            $bot->sendMessage(
                text: $header.$html,
                chat_id: (int) $user->telegram_user_id,
                parse_mode: 'HTML',
                disable_web_page_preview: true,
            );

            $group->last_notified_at = CarbonImmutable::now();
            $group->save();
        } catch (Throwable $e) {
            Log::warning('compare group notify failed', [
                'user_id' => $this->userId,
                'group_id' => $this->groupId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
