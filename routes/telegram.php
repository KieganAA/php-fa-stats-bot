<?php

/** @var Nutgram $bot */

use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\TargetMetricSet;
use App\Services\Auth\AppContext;
use App\Services\Auth\TelegramUserResolver;
use App\Services\Stats\ComparisonReporter;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\PrimitiveResolver;
use App\Services\Stats\StatsFormatter;
use App\Support\FlexibleCommand;
use App\Support\TelegramHelpers as H;
use Illuminate\Support\Facades\Redis;
use SergiX44\Nutgram\Handlers\Type\Command;
use SergiX44\Nutgram\Nutgram;

// FlexibleCommand fixes Nutgram's "command name must match exactly" behaviour
// so `/stats DK 7d` routes correctly. See its class docblock.
$command = fn (string $name, callable $handler): Command => $bot->registerCommand(
    new FlexibleCommand($handler, $name),
);

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

$command('start', function (Nutgram $bot) {
    $bot->sendMessage(
        "👋 Привет! Я fa-stats-bot.\n\n".
        "Откроешь <b>мини-апп</b> (/open) — там удобнее.\n\n".
        "<b>Быстрые статы:</b>\n".
        "/stats DK — страна DK сегодня\n".
        "/stats 33169 — прокл по human_id\n".
        "/stats 33169 за неделю — он же за 7 дней\n".
        "/stats BR 7d — BR за последние 7 дней\n\n".
        "<b>Compare:</b>\n".
        "/compare 33169 205215 — два прокла рядом + Δ%\n".
        "/compare DK BR за неделю — две страны\n\n".
        "<b>Свободно:</b>\n".
        "/ai &lt;вопрос&gt; — спроси словами, я разберусь\n\n".
        "<b>Сервис:</b>\n".
        "/ping · /help",
        parse_mode: 'HTML',
        reply_markup: H::openMiniAppKeyboard(),
    );
})->description('Начать');

$command('open', function (Nutgram $bot) {
    $keyboard = H::openMiniAppKeyboard();
    if ($keyboard === null) {
        $bot->sendMessage('Mini App URL не настроен. Задай APP_URL в .env.');

        return;
    }
    $bot->sendMessage('📱 Открыть приложение:', reply_markup: $keyboard);
})->description('Открыть мини-апп');

$command('ping', function (Nutgram $bot) {
    $bot->sendMessage('pong 🏓');
})->description('Проверка связи');

$command('help', function (Nutgram $bot) {
    $bot->sendMessage(
        "<b>Статы:</b>\n".
        "/stats &lt;примитив&gt; [период]\n".
        "/compare &lt;a&gt; &lt;b&gt; [...] [период]\n\n".
        "Примитив — что угодно из:\n".
        "• Код страны (DK, BR, IT, US…)\n".
        "• human_id лендинга (33169, 205228…)\n".
        "• UUID лендинга\n\n".
        "Скоро добавлю: кампании, баеров, источники.\n\n".
        "Период (любой из):\n".
        "• today / сегодня (по умолчанию)\n".
        "• yesterday / вчера / позавчера\n".
        "• 7d, 24h, 2w, 1m\n".
        "• неделя / за неделю / прошлая неделя\n".
        "• месяц / за месяц / прошлый месяц\n".
        "• 3 дня, 5 часов\n\n".
        "<b>AI:</b> /ai &lt;вопрос&gt;\n".
        "<b>Мини-апп:</b> /open",
        parse_mode: 'HTML',
    );
})->description('Справка');

$command('stats', function (Nutgram $bot) {
    $args = H::args($bot);
    if ($args === []) {
        $bot->sendMessage('Использование: /stats <примитив> [период]');

        return;
    }

    $token = array_shift($args);
    $period = $args !== [] ? implode(' ', $args) : null;

    try {
        $resolved = app(PrimitiveResolver::class)->resolve($token);
        $window = app(PeriodParser::class)->parse($period);

        $pivot = app(LandingReports::class)->statsByPrimitive(
            filterKey: $resolved['filter_key'],
            filterValue: $resolved['filter_value'],
            from: $window['from'],
            to: $window['to'],
            timezone: $window['timezone'],
        );

        $metrics = $pivot->rows[0]['metrics'] ?? [];
        $projected = app(TargetMetricSet::class)->project($metrics);

        $html = app(StatsFormatter::class)->format($window, [
            ['label' => $resolved['label'], 'metrics' => $projected],
        ]);

        $bot->sendMessage($html, parse_mode: 'HTML', disable_web_page_preview: true);
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Метрики (страна, кампания, …)');

$command('compare', function (Nutgram $bot) {
    $args = H::args($bot);
    if (count($args) < 2) {
        $bot->sendMessage('Использование: /compare <id|страна> <id|страна> [...] [период]');

        return;
    }

    [$tokens, $period] = H::splitPeriod($args);
    if (count($tokens) < 2) {
        $bot->sendMessage('Нужно минимум 2 примитива.');

        return;
    }

    try {
        $window = app(PeriodParser::class)->parse($period);
        $html = app(ComparisonReporter::class)->report($tokens, $window);
        $bot->sendMessage($html, parse_mode: 'HTML', disable_web_page_preview: true);
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Сравнить N лендов / стран');

$command('ai', function (Nutgram $bot) {
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
