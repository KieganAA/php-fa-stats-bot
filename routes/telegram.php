<?php

/** @var Nutgram $bot */

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Auth\AppContext;
use App\Services\Auth\TelegramUserResolver;
use App\Services\Stats\AliasResolver;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\StatsFormatter;
use App\Support\TelegramHelpers as H;
use Illuminate\Support\Facades\Redis;
use SergiX44\Nutgram\Nutgram;

// User-resolver middleware. Upserts a User row from the incoming TG identity
// and parks it in AppContext for the rest of the pipeline. Runs before the
// allowlist so even denied users get a row (useful for audit later).
$bot->middleware(function (Nutgram $bot, callable $next) {
    $tg = $bot->user();
    if ($tg !== null) {
        $user = app(TelegramUserResolver::class)->resolve([
            'id' => $tg->id,
            'username' => $tg->username ?? null,
            'first_name' => $tg->first_name ?? null,
            'last_name' => $tg->last_name ?? null,
            'language_code' => $tg->language_code ?? null,
        ]);
        app(AppContext::class)->setUser($user);
    }

    return $next($bot);
});

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
        "Открой <b>мини-апп</b> (/open) — там удобнее, чем команды.\n\n".
        "<b>Статы:</b>\n".
        "/stats &lt;alias&gt; [период]\n".
        "/compare &lt;alias…&gt; [период]\n\n".
        "<b>Алиасы:</b>\n".
        "/alias add &lt;name&gt; &lt;id&gt; [pos]\n".
        "/alias list · /alias rm &lt;name&gt;\n\n".
        "<b>Мониторинг:</b>\n".
        "/bind &lt;alias&gt; [silent] — отслеживать (пуш каждые 3ч)\n".
        "/unbind &lt;alias&gt; — снять\n".
        "/bindings — мои биндинги\n".
        "/mvt &lt;alias&gt; — последний снэпшот\n\n".
        "<b>AI и сервис:</b>\n".
        "/ai &lt;вопрос&gt; · /ping · /help\n\n".
        'Период: today, yesterday, 7d, 24h, week, month.',
        parse_mode: 'HTML',
        reply_markup: \App\Support\TelegramHelpers::openMiniAppKeyboard(),
    );
})->description('Стартовое сообщение');

$bot->onCommand('open', function (Nutgram $bot) {
    $keyboard = \App\Support\TelegramHelpers::openMiniAppKeyboard();
    if ($keyboard === null) {
        $bot->sendMessage('Mini App URL не настроен. Задай APP_URL в .env.');

        return;
    }
    $bot->sendMessage(
        '📱 Открыть приложение:',
        reply_markup: $keyboard,
    );
})->description('Открыть мини-апп');

$bot->onCommand('ping', function (Nutgram $bot) {
    $bot->sendMessage('pong 🏓');
})->description('Проверка связи');

$bot->onCommand('help', function (Nutgram $bot) {
    $bot->sendMessage(
        "<b>Мини-апп:</b> /open\n\n".
        "<b>Статы:</b>\n".
        "/stats &lt;alias&gt; [период]\n".
        "/compare &lt;alias…&gt; [период]\n\n".
        "<b>Алиасы:</b>\n".
        "/alias add &lt;name&gt; &lt;id&gt; [pos]\n".
        "/alias list · /alias rm\n\n".
        "<b>Мониторинг:</b>\n".
        "/bind &lt;alias&gt; [silent]\n".
        "/unbind &lt;alias&gt;\n".
        "/bindings\n".
        "/mvt &lt;alias&gt;\n\n".
        "<b>AI:</b> /ai &lt;вопрос&gt;\n\n".
        'Период: today | yesterday | 7d | 24h | week | month.',
        parse_mode: 'HTML',
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

$bot->onCommand('bind', function (Nutgram $bot) {
    try {
        H::bind($bot, H::args($bot));
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Отслеживать лендинг (3h снэпшоты)');

$bot->onCommand('unbind', function (Nutgram $bot) {
    try {
        H::unbind($bot, H::args($bot));
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Перестать отслеживать');

$bot->onCommand('bindings', function (Nutgram $bot) {
    try {
        H::bindingsList($bot);
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Список моих биндингов');

$bot->onCommand('mvt', function (Nutgram $bot) {
    try {
        H::mvtStatus($bot, H::args($bot));
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Последний снэпшот лендинга');

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
