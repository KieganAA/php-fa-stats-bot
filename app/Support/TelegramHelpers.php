<?php

namespace App\Support;

use App\Models\LandingAlias;
use App\Models\LandingSnapshot;
use App\Models\UserLandingBinding;
use App\Services\Ai\AiHandler;
use App\Services\Ai\AiRateLimiter;
use App\Services\Auth\AppContext;
use App\Services\Stats\AliasResolver;
use App\Services\Stats\PeriodParser;
use App\Services\Tracking\LandingBinder;
use App\Services\Tracking\LandingSnapshotComparer;
use App\Services\Tracking\LandingSnapshotFormatter;
use App\Services\Tracking\LandingUnbinder;
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

    /** @param  list<string>  $args */
    public static function bind(Nutgram $bot, array $args): void
    {
        if ($args === []) {
            $bot->sendMessage("Использование: /bind <alias> [silent]\nДобавь silent чтобы не получать пуши.");

            return;
        }

        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }

        $token = $args[0];
        $silent = isset($args[1]) && in_array(strtolower($args[1]), ['silent', 'тихо', 'mute'], true);

        $resolved = app(AliasResolver::class)->resolve($token);
        $position = $resolved['alias']?->position ?? 1;

        $binding = app(LandingBinder::class)->bind(
            user: $user,
            landingUuid: $resolved['landing']->uuid,
            position: $position,
            notify3h: ! $silent,
        );

        $label = self::label($resolved['alias']?->alias, $resolved['landing']->name, $position);
        $notifyHint = $silent ? '🔕 без уведомлений' : '🔔 пуш каждые 3ч';
        $bot->sendMessage(
            "✅ Отслеживаю <b>{$label}</b>\n{$notifyHint}",
            parse_mode: 'HTML',
        );
        unset($binding); // touched by tests via DB, but unused in the response
    }

    /** @param  list<string>  $args */
    public static function unbind(Nutgram $bot, array $args): void
    {
        if ($args === []) {
            $bot->sendMessage('Использование: /unbind <alias>');

            return;
        }
        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }

        $resolved = app(AliasResolver::class)->resolve($args[0]);
        $position = $resolved['alias']?->position ?? 1;

        $tracked = \App\Models\TrackedLanding::query()
            ->where('landing_uuid', $resolved['landing']->uuid)
            ->where('position', $position)
            ->first();

        if ($tracked === null) {
            $bot->sendMessage('Не отслеживается.');

            return;
        }

        $ok = app(LandingUnbinder::class)->unbind($user, $tracked);
        $label = self::label($resolved['alias']?->alias, $resolved['landing']->name, $position);
        $bot->sendMessage(
            $ok ? "🗑 Снял с отслеживания <b>{$label}</b>" : 'Не было биндинга.',
            parse_mode: 'HTML',
        );
    }

    /** Lists the current user's bindings. */
    public static function bindingsList(Nutgram $bot): void
    {
        $user = app(AppContext::class)->user();
        if ($user === null) {
            $bot->sendMessage('Не могу определить юзера.');

            return;
        }

        $bindings = UserLandingBinding::query()
            ->where('user_id', $user->id)
            ->with('trackedLanding.landing')
            ->get();

        if ($bindings->isEmpty()) {
            $bot->sendMessage('Биндингов нет. /bind <alias>');

            return;
        }

        $lines = ['<b>Мои биндинги:</b>'];
        foreach ($bindings as $b) {
            $tracked = $b->trackedLanding;
            if ($tracked === null) {
                continue;
            }
            $name = htmlspecialchars((string) ($tracked->landing?->name ?? $tracked->landing_uuid), ENT_QUOTES);
            $flags = ($b->notify_3h ? '🔔3h' : '🔕3h').' '.($b->notify_since_start ? '🔔start' : '🔕start');
            $lines[] = "• {$name} (LP{$tracked->position}) — {$flags}";
        }
        $bot->sendMessage(implode("\n", $lines), parse_mode: 'HTML');
    }

    /** Shows the latest 3h snapshot diff for a bound landing. */
    public static function mvtStatus(Nutgram $bot, array $args): void
    {
        if ($args === []) {
            $bot->sendMessage('Использование: /mvt <alias>');

            return;
        }

        $resolved = app(AliasResolver::class)->resolve($args[0]);
        $position = $resolved['alias']?->position ?? 1;

        $tracked = \App\Models\TrackedLanding::query()
            ->where('landing_uuid', $resolved['landing']->uuid)
            ->where('position', $position)
            ->with('landing')
            ->first();

        if ($tracked === null) {
            $bot->sendMessage('Не отслеживается. Сначала /bind '.$args[0]);

            return;
        }

        $latest = LandingSnapshot::query()
            ->where('tracked_landing_id', $tracked->id)
            ->where('kind', LandingSnapshot::KIND_3H)
            ->orderByDesc('window_end')
            ->first();

        if ($latest === null) {
            $bot->sendMessage('Ещё нет снэпшотов. Жди следующий цикл (раз в 3ч).');

            return;
        }

        $comparison = app(LandingSnapshotComparer::class)->compare($latest);
        $html = app(LandingSnapshotFormatter::class)->format($tracked, $comparison);
        $bot->sendMessage($html, parse_mode: 'HTML', disable_web_page_preview: true);
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
