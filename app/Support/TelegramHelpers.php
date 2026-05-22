<?php

namespace App\Support;

use App\Services\Ai\AiHandler;
use App\Services\Ai\AiRateLimiter;
use App\Services\Auth\AppContext;
use App\Services\Stats\PeriodParser;
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
