<?php

/** @var Nutgram $bot */

use App\Models\LandingAlias;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Stats\AliasResolver;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\StatsFormatter;
use SergiX44\Nutgram\Nutgram;

// Allowlist middleware. User passes if their numeric id OR their username matches.
// If BOTH lists are empty, everyone passes.
$bot->middleware(function (Nutgram $bot, callable $next) {
    $allowedIds = config('services.telegram.allowed_user_ids', []);
    $allowedUsernames = config('services.telegram.allowed_usernames', []);

    if (empty($allowedIds) && empty($allowedUsernames)) {
        return $next($bot);
    }

    $userId = (string) $bot->userId();
    $username = strtolower((string) ($bot->user()?->username ?? ''));

    $idAllowed = $userId !== '' && in_array($userId, $allowedIds, true);
    $nameAllowed = $username !== '' && in_array($username, $allowedUsernames, true);

    if (! $idAllowed && ! $nameAllowed) {
        $bot->sendMessage('⛔ Access denied.');

        return;
    }

    return $next($bot);
});

$bot->onCommand('start', function (Nutgram $bot) {
    $bot->sendMessage(
        "👋 Привет! Я fa-stats-bot.\n\n".
        "Команды:\n".
        "/stats <alias> [период] — метрики лендинга\n".
        "/compare <alias…> [период] — сравнить лендинги\n".
        "/alias add <name> <id> [pos] — привязать алиас\n".
        "/alias list — список алиасов\n".
        "/alias rm <name> — удалить алиас\n".
        "/ping — проверка связи\n".
        "/help — справка\n\n".
        'Период: today (по умолчанию), yesterday, 7d, 24h, week, month.'
    );
})->description('Стартовое сообщение');

$bot->onCommand('ping', function (Nutgram $bot) {
    $bot->sendMessage('pong 🏓');
})->description('Проверка связи');

$bot->onCommand('help', function (Nutgram $bot) {
    $bot->sendMessage(
        "Команды:\n".
        "/stats <alias> [период]\n".
        "/compare <alias…> [период]\n".
        "/alias add|list|rm\n\n".
        'Период: today | yesterday | 7d | 24h | week | month.'
    );
})->description('Справка');

$bot->onCommand('alias', function (Nutgram $bot) {
    $args = telegram_args($bot);
    $action = strtolower($args[0] ?? 'list');

    try {
        match ($action) {
            'add' => telegram_alias_add($bot, array_slice($args, 1)),
            'rm', 'remove', 'del', 'delete' => telegram_alias_rm($bot, array_slice($args, 1)),
            'list', 'ls' => telegram_alias_list($bot),
            default => $bot->sendMessage("Неизвестное действие: {$action}\nДоступно: add | list | rm"),
        };
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Управление алиасами лендингов');

$bot->onCommand('stats', function (Nutgram $bot) {
    $args = telegram_args($bot);
    if ($args === []) {
        $bot->sendMessage('Использование: /stats <alias> [период]');

        return;
    }

    $token = array_shift($args);
    $period = $args[0] ?? null;

    try {
        $resolved = app(AliasResolver::class)->resolve($token);
        $window = app(PeriodParser::class)->parse($period);

        $position = $resolved['alias']?->position ?? 1;
        $reports = app(LandingReports::class);
        $pivot = $reports->landingStats(
            landingUuid: $resolved['landing']->uuid,
            position: $position,
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $metrics = $pivot->rows[0]['metrics'] ?? [];
        $projected = app(TargetMetricSet::class)->project($metrics);

        $label = telegram_label($resolved['alias']?->alias, $resolved['landing']->name, $position);
        $html = app(StatsFormatter::class)->format($window, [
            ['label' => $label, 'metrics' => $projected],
        ]);

        $bot->sendMessage($html, parse_mode: 'HTML', disable_web_page_preview: true);
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Метрики лендинга');

$bot->onCommand('compare', function (Nutgram $bot) {
    $args = telegram_args($bot);
    if (count($args) < 2) {
        $bot->sendMessage('Использование: /compare <alias1> <alias2> [...] [период]');

        return;
    }

    [$tokens, $period] = telegram_split_period($args);
    if (count($tokens) < 2) {
        $bot->sendMessage('Нужно минимум 2 алиаса для сравнения.');

        return;
    }

    try {
        $resolver = app(AliasResolver::class);
        $resolved = $resolver->resolveAll($tokens);

        $positions = array_unique(array_map(fn ($r) => $r['alias']?->position ?? 1, $resolved));
        if (count($positions) > 1) {
            $bot->sendMessage('Все алиасы должны быть на одной позиции (LP1, LP2, …).');

            return;
        }
        $position = (int) array_values($positions)[0];

        $window = app(PeriodParser::class)->parse($period);
        $uuids = array_map(fn ($r) => $r['landing']->uuid, $resolved);

        $pivot = app(LandingReports::class)->compareLandings(
            landingUuids: array_values($uuids),
            position: $position,
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $byUuid = [];
        foreach ($pivot->rows as $row) {
            $uuid = (string) ($row['dimensions']['group_0'] ?? '');
            $byUuid[$uuid] = $row['metrics'];
        }

        $targets = app(TargetMetricSet::class);
        $entries = [];
        foreach ($resolved as $r) {
            $raw = $byUuid[$r['landing']->uuid] ?? [];
            $entries[] = [
                'label' => telegram_label($r['alias']?->alias, $r['landing']->name, $position),
                'metrics' => $targets->project($raw),
            ];
        }

        $html = app(StatsFormatter::class)->format($window, $entries);
        $bot->sendMessage($html, parse_mode: 'HTML', disable_web_page_preview: true);
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Сравнить лендинги');

$bot->fallback(function (Nutgram $bot) {
    $bot->sendMessage('Не понял. /help — список команд.');
});

// ---------- helpers ----------

/** @return list<string> */
function telegram_args(Nutgram $bot): array
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
function telegram_split_period(array $args): array
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

function telegram_label(?string $alias, string $name, int $position): string
{
    if ($alias) {
        return "{$alias} (LP{$position})";
    }

    return $name.' [LP'.$position.']';
}

function telegram_alias_add(Nutgram $bot, array $args): void
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
            'created_by_user_id' => (string) $bot->userId(),
            'notes' => $notes,
        ],
    );

    $bot->sendMessage(
        "✅ Алиас <code>".htmlspecialchars($alias->alias)."</code> → ".htmlspecialchars($resolved['landing']->name)." (LP{$position})",
        parse_mode: 'HTML',
    );
}

function telegram_alias_rm(Nutgram $bot, array $args): void
{
    if ($args === []) {
        $bot->sendMessage('Использование: /alias rm <name>');

        return;
    }
    $name = $args[0];
    $deleted = LandingAlias::query()->where('alias', $name)->delete();
    $bot->sendMessage($deleted ? "🗑 Алиас {$name} удалён." : "Алиас {$name} не найден.");
}

function telegram_alias_list(Nutgram $bot): void
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
