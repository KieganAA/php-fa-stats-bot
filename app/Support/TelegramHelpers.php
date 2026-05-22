<?php

namespace App\Support;

use App\Models\LandingAlias;
use App\Services\Ai\AiHandler;
use App\Services\Ai\AiRateLimiter;
use App\Services\Auth\AppContext;
use App\Services\Stats\AliasResolver;
use App\Services\Stats\PeriodParser;
use SergiX44\Nutgram\Nutgram;
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

    public static function label(?string $alias, string $name, int $position): string
    {
        if ($alias) {
            return "{$alias} (LP{$position})";
        }

        return $name.' [LP'.$position.']';
    }

    /** @param  list<string>  $args */
    public static function aliasAdd(Nutgram $bot, array $args): void
    {
        if (count($args) < 2) {
            $bot->sendMessage('Использование: /alias add <name> <human_id|uuid> [position] [notes...]');

            return;
        }
        [$name, $token] = $args;
        $position = (int) ($args[2] ?? 1);
        $notes = isset($args[3]) ? implode(' ', array_slice($args, 3)) : null;

        $resolved = app(AliasResolver::class)->resolve($token);

        $alias = LandingAlias::query()->updateOrCreate(
            ['alias' => $name],
            [
                'landing_uuid' => $resolved['landing']->uuid,
                'position' => $position,
                'created_by_id' => app(AppContext::class)->user()?->id,
                'notes' => $notes,
            ],
        );

        $bot->sendMessage(
            '✅ Алиас <code>'.htmlspecialchars($alias->alias).'</code> → '.htmlspecialchars($resolved['landing']->name)." (LP{$position})",
            parse_mode: 'HTML',
        );
    }

    /** @param  list<string>  $args */
    public static function aliasRm(Nutgram $bot, array $args): void
    {
        if ($args === []) {
            $bot->sendMessage('Использование: /alias rm <name>');

            return;
        }
        $name = $args[0];
        $deleted = LandingAlias::query()->where('alias', $name)->delete();
        $bot->sendMessage($deleted ? "🗑 Алиас {$name} удалён." : "Алиас {$name} не найден.");
    }

    public static function aliasList(Nutgram $bot): void
    {
        $aliases = app(AliasResolver::class)->listAll();
        if ($aliases->isEmpty()) {
            $bot->sendMessage('Алиасов нет. /alias add <name> <id>');

            return;
        }

        $lines = ['<b>Алиасы:</b>'];
        foreach ($aliases as $a) {
            $name = htmlspecialchars((string) $a->landing?->name ?: $a->landing_uuid, ENT_QUOTES);
            $lines[] = "• <code>{$a->alias}</code> → {$name} (LP{$a->position})";
        }
        $bot->sendMessage(implode("\n", $lines), parse_mode: 'HTML');
    }

    public static function runAi(Nutgram $bot, string $question): void
    {
        // Prefer the internal user id (stable, immutable) over the raw
        // telegram_user_id so a future identity migration doesn't reset
        // anyone's rate-limit budget.
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
