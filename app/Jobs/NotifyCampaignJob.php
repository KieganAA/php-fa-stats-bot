<?php

namespace App\Jobs;

use App\Models\CampaignSubscription;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Stats\PeriodParser;
use App\Services\Tracking\GroupReportRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * The campaign digest push: ONE Telegram message per campaign per tick,
 * containing a section for every active split/MVT child — instead of the old
 * message-per-child spam.
 *
 *   🔔 #93076 IT — Sigfrido Ranucci…
 *   today · 00:00–20:45 (Europe/Moscow)
 *
 *   🔀 шаг 1 сплит
 *   <table>
 *
 *   🧬 MVT #221674
 *   <table>
 *
 *   😴 шаг 2 сплит — нет трафика
 *
 * Sections with zero traffic collapse to one line; if EVERY section is empty
 * the whole message shrinks to a one-liner ("нет трафика сегодня") so the
 * user still sees the bot is alive without a wall of dashes.
 */
class NotifyCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Telegram hard limit is 4096; leave headroom for the closing tags. */
    private const CHUNK_LIMIT = 3900;

    public function __construct(
        public readonly int $userId,
        public readonly int $campaignSubscriptionId,
    ) {}

    public function handle(Nutgram $bot, GroupReportRenderer $renderer, PeriodParser $periods): void
    {
        $user = User::query()->find($this->userId);
        $sub = CampaignSubscription::query()
            ->with('children.members.trackedLanding.landing')
            ->find($this->campaignSubscriptionId);

        if ($user === null || $sub === null || ! $user->telegram_user_id) {
            return; // cleaned up since dispatch
        }
        if ($sub->paused_at !== null) {
            return;
        }

        $children = $sub->children
            ->whereNull('orphaned_at')
            ->whereNull('paused_at')
            ->filter(fn (UserCompareGroup $g) => $g->members->isNotEmpty())
            ->values();
        if ($children->isEmpty()) {
            return;
        }

        // Reports cover the whole current day in the user's timezone — the
        // schedule only controls how OFTEN we push, not the window size.
        $window = $periods->parse('today', $user->timezone);

        $sections = [];
        $emptyLabels = [];
        $erroredLabels = [];
        foreach ($children as $child) {
            try {
                $html = $renderer->render($child, $user, $window, digestMode: true);
            } catch (Throwable $e) {
                // One section failing (e.g. a transient AIO error) must NOT kill
                // the whole digest — that would block the campaign forever and
                // never stamp last_notified. Collapse the section to a note and
                // carry on; the rest still delivers.
                Log::warning('campaign digest section failed', [
                    'campaign_subscription_id' => $sub->id,
                    'group_id' => $child->id,
                    'error' => $e->getMessage(),
                ]);
                $erroredLabels[] = $this->sectionCaption($child);

                continue;
            }

            $caption = $this->sectionCaption($child);
            if ($html === null) {
                $emptyLabels[] = $caption;

                continue;
            }
            $sections[] = "<b>{$this->escape($caption)}</b>\n{$html}";
        }

        $header = $this->header($sub, $window);
        $errored = $erroredLabels !== []
            ? "\n⚠️ <i>".$this->escape(implode(' · ', $erroredLabels)).' — ошибка отчёта, попробую снова</i>'
            : '';

        if ($sections === []) {
            // Whole campaign is silent — one short line, not a dash table.
            // Still counts as a push so the schedule doesn't re-fire each tick.
            $note = $erroredLabels !== [] && $emptyLabels === []
                ? '' // all sections errored — the ⚠️ line below says enough
                : "\nнет трафика за окно — все сплиты/MVT по нулям.";
            $bot->sendMessage(
                text: "😴 {$header}{$note}{$errored}",
                chat_id: (int) $user->telegram_user_id,
                parse_mode: 'HTML',
                disable_web_page_preview: true,
            );
        } else {
            if ($emptyLabels !== []) {
                $sections[] = '😴 <i>'.$this->escape(implode(' · ', $emptyLabels)).' — нет трафика</i>';
            }
            if ($errored !== '') {
                $sections[] = ltrim($errored, "\n");
            }
            foreach ($this->chunk($header, $sections) as $message) {
                $bot->sendMessage(
                    text: $message,
                    chat_id: (int) $user->telegram_user_id,
                    parse_mode: 'HTML',
                    disable_web_page_preview: true,
                );
            }
        }

        $now = CarbonImmutable::now();
        foreach ($children as $child) {
            $child->last_notified_at = $now;
            $child->save();
        }
    }

    private function header(CampaignSubscription $sub, array $window): string
    {
        $name = mb_strlen($sub->campaign_name) > 48
            ? mb_substr($sub->campaign_name, 0, 47).'…'
            : $sub->campaign_name;
        $w = $window['from']->format('H:i').'–'.$window['to']->format('H:i');

        return "🔔 <b>{$sub->shortLabel()}</b> — {$this->escape($name)}\n".
            "<i>{$this->escape($window['label'])} · {$w} ({$this->escape($window['timezone'])})</i>";
    }

    private function sectionCaption(UserCompareGroup $child): string
    {
        // "#116400 CA · шаг 1 сплит" → "🔀 шаг 1 сплит" (campaign prefix is
        // already in the message header).
        $name = (string) $child->name;
        $pos = strpos($name, '· ');
        $short = $pos !== false ? trim(substr($name, $pos + 2)) : $name;
        $icon = ($child->mode ?? UserCompareGroup::MODE_COMPARE) === UserCompareGroup::MODE_MVT ? '🧬' : '🔀';

        return "{$icon} {$short}";
    }

    /**
     * Pack sections into as few messages as possible, each under the Telegram
     * size limit. The header rides on the first message only.
     *
     * @param  list<string>  $sections
     * @return list<string>
     */
    private function chunk(string $header, array $sections): array
    {
        $messages = [];
        $current = $header;
        foreach ($sections as $section) {
            $candidate = $current."\n\n".$section;
            if (mb_strlen($candidate) > self::CHUNK_LIMIT && $current !== $header) {
                $messages[] = $current;
                $current = $section;

                continue;
            }
            $current = $candidate;
        }
        $messages[] = $current;

        return $messages;
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
