<?php

namespace App\Support;

use App\Models\Aio\Landing;
use App\Services\Ai\AiHandler;
use App\Services\Ai\AiRateLimiter;
use App\Services\Auth\AppContext;
use App\Services\Stats\LandingFormatter;
use App\Services\Stats\PeriodParser;
use App\Services\Tracking\CompareGroupBinder;
use App\Services\Tracking\CompareGroupUnbinder;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use Throwable;

/**
 * Static helpers used by Telegram command handlers in routes/telegram.php.
 *
 * Lives as static methods (not free functions) so the routes file can be
 * loaded more than once per process — required by tests that boot Laravel
 * multiple times in the same worker.
 */
final class TelegramHelpers
{
    /** @return list<string> */
    public static function args(Nutgram $bot): array
    {
        $text = (string) ($bot->message()?->text ?? '');
        $parts = preg_split('/\s+/', trim($text)) ?: [];
        array_shift($parts); // drop the command itself

        return array_values(array_filter($parts, fn ($s) => $s !== ''));
    }

    /**
     * Splits trailing period from a list of args. Last arg is treated as a period
     * if it parses; otherwise all args are tokens and the period is null.
     *
     * @param  list<string>  $args
     * @return array{0: list<string>, 1: ?string}
     */
    public static function splitPeriod(array $args): array
    {
        if ($args === []) {
            return [[], null];
        }
        $last = end($args);
        try {
            app(PeriodParser::class)->parse($last);

            return [array_slice($args, 0, -1), $last];
        } catch (Throwable) {
            return [$args, null];
        }
    }

    /**
     * Inline keyboard with a single "open Mini App" button. Returns null if
     * APP_URL isn't set / isn't HTTPS — callers should fall back to text.
     */
    public static function openMiniAppKeyboard(?string $label = null): ?InlineKeyboardMarkup
    {
        $appUrl = (string) config('app.url', '');
        if ($appUrl === '') {
            return null;
        }
        $url = rtrim($appUrl, '/').'/app';

        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        $button = InlineKeyboardButton::make(
            text: $label ?? '📱 Открыть мини-апп',
            web_app: WebAppInfo::make(url: $url),
        );

        return InlineKeyboardMarkup::make()->addRow($button);
    }

    /**
     * /bind 33169 205215 [name]
     *
     * Final non-numeric arg is treated as the group name (if not a UUID).
     * Otherwise the binder auto-generates "g1", "g2", …
     *
     * @param  list<string>  $args
     */
    public static function bind(Nutgram $bot, array $args): void
    {
        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }

        // Optional trailing name — last arg that's not a number / uuid.
        $name = null;
        if ($args !== []) {
            $last = end($args);
            $isId = ctype_digit($last) || preg_match('/^[0-9a-f-]{36}$/i', $last);
            if (! $isId) {
                $name = array_pop($args);
            }
        }

        if (count($args) < 1) {
            $bot->sendMessage("Использование: /bind <id1> [id2…] [name]\nМинимум 1 лендинг; для сравнения 3h-пуша — 2+.");

            return;
        }

        // Resolve every arg to a Landing — countries aren't supported in
        // compare groups yet (they don't have a stable "human_id" anchor).
        $landings = [];
        foreach ($args as $token) {
            $landing = null;
            if (ctype_digit($token)) {
                $landing = Landing::query()->where('human_id', (int) $token)->first();
            } elseif (preg_match('/^[0-9a-f-]{36}$/i', $token)) {
                $landing = Landing::query()->where('uuid', $token)->first();
            }
            if ($landing === null) {
                $bot->sendMessage("Лендинг «{$token}» не найден.");

                return;
            }
            $landings[] = $landing;
        }

        $group = app(CompareGroupBinder::class)->bind($user, $landings, $name);

        $lines = ["✅ Группа <code>".htmlspecialchars($group->name).'</code> забинжена'];
        $fmt = app(LandingFormatter::class);
        foreach ($group->members as $m) {
            if ($m->trackedLanding?->landing) {
                $lines[] = '• '.htmlspecialchars($fmt->shortLine($m->trackedLanding->landing));
            }
        }
        if (count($group->members) >= 2) {
            $lines[] = "\n🔔 Каждые 3 часа будет приходить compare-отчёт.";
        } else {
            $lines[] = "\n💡 В группе один лендинг — для 3h-пуша добавь второй: /bind id ... ".htmlspecialchars($group->name);
        }

        $bot->sendMessage(implode("\n", $lines), parse_mode: 'HTML');
    }

    public static function groupsList(Nutgram $bot): void
    {
        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }

        $groups = $user->compareGroups()->with('members.trackedLanding.landing')->get();
        if ($groups->isEmpty()) {
            $bot->sendMessage('Групп нет. /bind &lt;id1&gt; &lt;id2&gt; — создать.', parse_mode: 'HTML');

            return;
        }

        $fmt = app(LandingFormatter::class);
        $lines = ['<b>Мои группы:</b>'];
        foreach ($groups as $g) {
            $paused = $g->paused_at !== null ? ' ⏸' : '';
            $lines[] = "\n<code>".htmlspecialchars($g->name)."</code>{$paused}";
            foreach ($g->members as $m) {
                if ($m->trackedLanding?->landing) {
                    $lines[] = '  • '.htmlspecialchars($fmt->shortLine($m->trackedLanding->landing));
                }
            }
        }
        $bot->sendMessage(implode("\n", $lines), parse_mode: 'HTML');
    }

    /** @param  list<string>  $args */
    public static function unbind(Nutgram $bot, array $args): void
    {
        if ($args === []) {
            $bot->sendMessage('Использование: /unbind &lt;name&gt; — посмотреть имена через /groups', parse_mode: 'HTML');

            return;
        }
        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }
        $name = $args[0];
        $group = $user->compareGroups()->where('name', $name)->first();
        if ($group === null) {
            $bot->sendMessage("Группы <code>{$name}</code> нет.", parse_mode: 'HTML');

            return;
        }
        app(CompareGroupUnbinder::class)->unbind($group);
        $bot->sendMessage("🗑 Группа <code>".htmlspecialchars($name).'</code> удалена.', parse_mode: 'HTML');
    }

    public static function runAi(Nutgram $bot, string $question): void
    {
        // Internal user id is stable; prefer it over telegram_user_id so a
        // future identity migration doesn't reset anyone's rate-limit budget.
        $subject = (string) (app(AppContext::class)->user()?->id ?? $bot->userId());
        if (! app(AiRateLimiter::class)->attempt($subject)) {
            $bot->sendMessage('⏳ Слишком много запросов. Попробуй чуть позже.');

            return;
        }

        try {
            $reply = app(AiHandler::class)->handle($question);
            if ($reply === '') {
                $bot->sendMessage('<i>Пустой ответ.</i>', parse_mode: 'HTML');

                return;
            }

            $bot->sendMessage($reply, parse_mode: 'HTML', disable_web_page_preview: true);
        } catch (Throwable $e) {
            $bot->sendMessage('Ошибка: '.$e->getMessage());
        }
    }
}
