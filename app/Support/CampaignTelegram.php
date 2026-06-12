<?php

namespace App\Support;

use App\Models\CampaignSubscription;
use App\Models\UserCompareGroup;
use App\Services\Auth\AppContext;
use App\Services\Campaign\CampaignSubscriptionService;
use App\Services\Campaign\CampaignTokenResolver;
use App\Services\Campaign\Dto\ResyncResult;
use App\Services\Tracking\CompareGroupUnbinder;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/**
 * Telegram command handlers for the campaign-subscription flow. Kept apart
 * from the (legacy) TelegramHelpers so the new "campaign-first" surface is
 * easy to reason about in isolation.
 *
 *   /campaign <uuid>   — subscribe; bot derives splits + MVTs and reports them
 *   /campaigns         — list the user's campaign subscriptions
 *   /resync [uuid]     — re-derive children for all / one campaign
 */
final class CampaignTelegram
{
    public static function subscribe(Nutgram $bot, array $args): void
    {
        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }
        if ($args === []) {
            $bot->sendMessage(
                "Использование: <code>/campaign &lt;human_id или uuid&gt;</code>\n".
                "например <code>/campaign 036469</code>\n\n".
                'Проще всего — открыть кампанию через расширение, оно подставит uuid само.',
                parse_mode: 'HTML',
            );

            return;
        }

        $resolver = app(CampaignTokenResolver::class);
        try {
            $campaignUuid = $resolver->resolve($args[0]);
        } catch (Throwable $e) {
            $bot->sendMessage('❌ '.htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5), parse_mode: 'HTML');

            return;
        }

        $orphans = [];
        TelegramHelpers::withPlaceholder($bot, function () use ($user, $campaignUuid, &$orphans): string {
            $service = app(CampaignSubscriptionService::class);
            $result = $service->create($user, $campaignUuid);
            $orphans = $result->orphaned;
            $sub = CampaignSubscription::query()
                ->where('user_id', $user->id)
                ->where('campaign_uuid', $campaignUuid)
                ->firstOrFail();

            return self::renderCreateSummary($sub, $result);
        }, '⏳ разбираю кампанию в AIO…');

        self::sendOrphanControls($bot, $orphans);
    }

    public static function listSubscriptions(Nutgram $bot): void
    {
        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }

        $subs = CampaignSubscription::query()
            ->where('user_id', $user->id)
            ->with('children')
            ->orderByDesc('id')
            ->get();

        if ($subs->isEmpty()) {
            $bot->sendMessage(
                "Подписок на кампании пока нет.\n\n".
                'Добавь: <code>/campaign &lt;uuid&gt;</code> или через расширение на странице AIO.',
                parse_mode: 'HTML',
            );

            return;
        }

        $lines = ['<b>📋 Мои кампании</b>', ''];
        foreach ($subs as $sub) {
            $lines[] = self::renderSubscriptionBlock($sub);
        }
        $lines[] = '';
        $lines[] = '<i>/resync — обновить структуру всех. /resync &lt;uuid&gt; — одну.</i>';

        $bot->sendMessage(implode("\n", $lines), parse_mode: 'HTML', disable_web_page_preview: true);
    }

    public static function resync(Nutgram $bot, array $args): void
    {
        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }

        $query = CampaignSubscription::query()->where('user_id', $user->id);
        if ($args !== []) {
            $resolver = app(CampaignTokenResolver::class);
            try {
                $uuid = $resolver->resolve($args[0]);
            } catch (Throwable $e) {
                $bot->sendMessage('❌ '.htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_HTML5), parse_mode: 'HTML');

                return;
            }
            $query->where('campaign_uuid', $uuid);
        }

        $subs = $query->get();
        if ($subs->isEmpty()) {
            $bot->sendMessage('Нет подписок для ресинка. /campaigns — список.');

            return;
        }

        $orphans = [];
        TelegramHelpers::withPlaceholder($bot, function () use ($subs, &$orphans): string {
            $service = app(CampaignSubscriptionService::class);
            $blocks = [];
            foreach ($subs as $sub) {
                $result = $service->resync($sub);
                foreach ($result->orphaned as $o) {
                    $orphans[] = $o;
                }
                $blocks[] = self::renderResyncSummary($sub->fresh(), $result);
            }

            return implode("\n\n", $blocks);
        }, '⏳ обновляю структуру кампаний…');

        self::sendOrphanControls($bot, $orphans);
    }

    /**
     * After a resync surfaces children that vanished from the campaign, send one
     * actionable card per orphan: delete it for good, or keep it (paused; it
     * reactivates automatically if the split/MVT reappears on a later resync).
     * This is the "report & wait for decision" half of the orphan policy.
     *
     * @param  list<UserCompareGroup>  $orphans
     */
    public static function sendOrphanControls(Nutgram $bot, array $orphans): void
    {
        foreach ($orphans as $orphan) {
            $kb = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('🗑 удалить', callback_data: 'corph:del:'.$orphan->id),
                InlineKeyboardButton::make('💤 оставить', callback_data: 'corph:keep:'.$orphan->id),
            );
            $bot->sendMessage(
                "⚠️ <b>".self::escape($orphan->name ?? '')."</b>\n".
                "пропал из структуры кампании — пуши по нему остановлены.\n\n".
                "<i>Удалить совсем или оставить? Оставленный вернётся сам, если снова появится в кампании.</i>",
                parse_mode: 'HTML',
                reply_markup: $kb,
            );
        }
    }

    /**
     * Callback for the orphan cards. $action ∈ {del, keep}. Verifies the group
     * is a campaign child owned by the clicking user before acting, then edits
     * the card to reflect the decision and drops the buttons.
     */
    public static function handleOrphanDecision(Nutgram $bot, string $action, int $groupId): void
    {
        $user = app(AppContext::class)->user();
        $group = UserCompareGroup::query()
            ->whereNotNull('campaign_subscription_id')
            ->find($groupId);

        if ($user === null || $group === null || $group->user_id !== $user->id) {
            $bot->answerCallbackQuery(text: 'Не найдено или уже обработано.');

            return;
        }

        $label = self::escape($group->name ?? '');

        if ($action === 'del') {
            app(CompareGroupUnbinder::class)->unbind($group);
            $bot->answerCallbackQuery(text: 'Удалено');
            $newText = "🗑 <b>{$label}</b>\nудалено.";
        } else {
            $bot->answerCallbackQuery(text: 'Оставлено');
            $newText = "💤 <b>{$label}</b>\nоставлено как есть — вернётся, если снова появится в кампании.";
        }

        $messageId = $bot->callbackQuery()?->message?->message_id;
        if ($messageId !== null) {
            // Omitting reply_markup strips the buttons — the decision is final.
            $bot->editMessageText(
                text: $newText,
                chat_id: $bot->chatId(),
                message_id: $messageId,
                parse_mode: 'HTML',
            );
        }
    }

    // ===== rendering =====

    private static function renderCreateSummary(CampaignSubscription $sub, ResyncResult $result): string
    {
        $head = "✅ <b>Подписка на кампанию</b>\n".
            self::escape($sub->campaign_name)."\n".
            "<code>{$sub->shortLabel()}</code>\n";

        $children = $sub->children()->get();
        if ($children->isEmpty()) {
            return $head."\n⚠️ Не нашёл ни сплитов, ни MVT-лендов в этой кампании. Нечего пушить.";
        }

        $splits = $children->where('mode', UserCompareGroup::MODE_COMPARE);
        $mvts = $children->where('mode', UserCompareGroup::MODE_MVT);

        $lines = [$head];
        $lines[] = "Буду пушить (каждые ".self::intervalLabel($sub->notify_interval_minutes)."):";
        if ($splits->isNotEmpty()) {
            $lines[] = "\n<b>Сплиты ({$splits->count()}):</b>";
            foreach ($splits as $c) {
                $lines[] = '• '.self::escape($c->name).' — '.$c->members()->count().' ленда';
            }
        }
        if ($mvts->isNotEmpty()) {
            $lines[] = "\n<b>MVT-ленды ({$mvts->count()}):</b>";
            foreach ($mvts as $c) {
                $lines[] = '• '.self::escape($c->name);
            }
        }

        return implode("\n", $lines);
    }

    private static function renderResyncSummary(CampaignSubscription $sub, ResyncResult $result): string
    {
        $head = "🔄 <b>{$sub->shortLabel()}</b> ".self::escape(self::truncate($sub->campaign_name, 40));

        if (! $result->changed()) {
            return $head."\nБез изменений.";
        }

        $lines = [$head];
        if ($result->created !== []) {
            $lines[] = '➕ добавлено: '.count($result->created).' ('.self::names($result->created).')';
        }
        if ($result->reactivated !== []) {
            $lines[] = '♻️ вернулось: '.count($result->reactivated).' ('.self::names($result->reactivated).')';
        }
        if ($result->updated !== []) {
            $lines[] = '✏️ обновлено: '.count($result->updated).' ('.self::names($result->updated).')';
        }
        if ($result->orphaned !== []) {
            $lines[] = "⚠️ <b>пропало из кампании: ".count($result->orphaned)."</b> (".self::names($result->orphaned).')';
            $lines[] = '<i>Пуши приостановлены — реши по каждому кнопками ниже.</i>';
        }

        return implode("\n", $lines);
    }

    private static function renderSubscriptionBlock(CampaignSubscription $sub): string
    {
        $children = $sub->children;
        $active = $children->whereNull('orphaned_at');
        $orphans = $children->whereNotNull('orphaned_at');
        $splits = $active->where('mode', UserCompareGroup::MODE_COMPARE)->count();
        $mvts = $active->where('mode', UserCompareGroup::MODE_MVT)->count();

        $paused = $sub->paused_at !== null ? ' ⏸' : '';
        $parts = [];
        if ($splits > 0) {
            $parts[] = "{$splits} сплит";
        }
        if ($mvts > 0) {
            $parts[] = "{$mvts} MVT";
        }
        $summary = $parts !== [] ? implode(' · ', $parts) : 'пусто';

        $line = "<code>{$sub->shortLabel()}</code>{$paused} — ".self::escape(self::truncate($sub->campaign_name, 38))."\n".
            "   {$summary}, каждые ".self::intervalLabel($sub->notify_interval_minutes);
        if ($orphans->isNotEmpty()) {
            $line .= "\n   ⚠️ {$orphans->count()} пропало из структуры";
        }

        return $line;
    }

    /** @param  list<UserCompareGroup>  $groups */
    private static function names(array $groups): string
    {
        return implode(', ', array_map(
            fn (UserCompareGroup $g) => self::escape(self::stepLabel($g)),
            $groups,
        ));
    }

    private static function stepLabel(UserCompareGroup $g): string
    {
        // Strip the campaign prefix from the child name for compact listing —
        // "#116400 CA · шаг 1 сплит" → "шаг 1 сплит".
        $name = $g->name ?? '';
        $pos = strpos($name, '· ');

        return $pos !== false ? trim(substr($name, $pos + strlen('· '))) : $name;
    }

    private static function intervalLabel(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            $h = intdiv($minutes, 60);

            return $h === 1 ? '1 ч' : "{$h} ч";
        }

        return "{$minutes} мин";
    }

    private static function truncate(string $s, int $max): string
    {
        return mb_strlen($s) <= $max ? $s : mb_substr($s, 0, $max - 1).'…';
    }

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
