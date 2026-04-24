<?php

/** @var Nutgram $bot */

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Stats\AliasResolver;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\StatsFormatter;
use App\Support\TelegramHelpers as H;
use Illuminate\Support\Facades\Redis;
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

// Per-user TG command rate limit. Sliding window over Redis ZSET.
// Defends our own infra and the AIO budget against runaway clients.
$bot->middleware(function (Nutgram $bot, callable $next) {
    $limit = (int) config('services.telegram.rate_limit', 30);
    $window = (int) config('services.telegram.rate_window_seconds', 60);
    if ($limit <= 0) {
        return $next($bot);
    }

    $userId = (string) $bot->userId();
    if ($userId === '') {
        return $next($bot);
    }

    $key = 'tg:rate:'.$userId;
    $now = (int) (microtime(true) * 1000);
    $cutoff = $now - $window * 1000;

    $conn = Redis::connection();
    $conn->zremrangebyscore($key, '-inf', (string) $cutoff);
    if ((int) $conn->zcard($key) >= $limit) {
        $bot->sendMessage('⏳ Слишком часто. Попробуй чуть позже.');

        return;
    }
    $conn->zadd($key, $now, $now.':'.bin2hex(random_bytes(4)));
    $conn->expire($key, $window + 1);

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
        "/ai <вопрос> — свободный запрос (Claude Haiku)\n".
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
        "/alias add|list|rm\n".
        "/ai <вопрос> — свободный запрос\n\n".
        'Период: today | yesterday | 7d | 24h | week | month.'
    );
})->description('Справка');

$bot->onCommand('alias', function (Nutgram $bot) {
    $args = H::args($bot);
    $action = strtolower($args[0] ?? 'list');

    try {
        match ($action) {
            'add' => H::aliasAdd($bot, array_slice($args, 1)),
            'rm', 'remove', 'del', 'delete' => H::aliasRm($bot, array_slice($args, 1)),
            'list', 'ls' => H::aliasList($bot),
            default => $bot->sendMessage("Неизвестное действие: {$action}\nДоступно: add | list | rm"),
        };
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Управление алиасами лендингов');

$bot->onCommand('stats', function (Nutgram $bot) {
    $args = H::args($bot);
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

        $label = H::label($resolved['alias']?->alias, $resolved['landing']->name, $position);
        $html = app(StatsFormatter::class)->format($window, [
            ['label' => $label, 'metrics' => $projected],
        ]);

        $bot->sendMessage($html, parse_mode: 'HTML', disable_web_page_preview: true);
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Метрики лендинга');

$bot->onCommand('compare', function (Nutgram $bot) {
    $args = H::args($bot);
    if (count($args) < 2) {
        $bot->sendMessage('Использование: /compare <alias1> <alias2> [...] [период]');

        return;
    }

    [$tokens, $period] = H::splitPeriod($args);
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
                'label' => H::label($r['alias']?->alias, $r['landing']->name, $position),
                'metrics' => $targets->project($raw),
            ];
        }

        $html = app(StatsFormatter::class)->format($window, $entries);
        $bot->sendMessage($html, parse_mode: 'HTML', disable_web_page_preview: true);
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Сравнить лендинги');

$bot->onCommand('ai', function (Nutgram $bot) {
    $text = (string) ($bot->message()?->text ?? '');
    $question = trim((string) preg_replace('/^\/ai(@\S+)?\s*/u', '', $text));

    if ($question === '') {
        $bot->sendMessage('Использование: /ai <вопрос>');

        return;
    }

    H::runAi($bot, $question);
})->description('Свободный запрос (AI)');

$bot->fallback(function (Nutgram $bot) {
    $bot->sendMessage('Не понял. /help — список команд.');
});
