<?php

/** @var Nutgram $bot */

use App\Services\Auth\AppContext;
use App\Services\Auth\TelegramUserResolver;
use App\Services\Stats\ComparisonReporter;
use App\Services\Stats\PeriodParser;
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
        "<b>Самое простое — просто пиши:</b>\n".
        "<code>DK</code> — DK сегодня\n".
        "<code>33169</code> — прокл #33169 сегодня\n".
        "<code>BR 7d</code> — BR за 7 дней\n".
        "<code>33169 за неделю</code>\n\n".
        "<b>Топы:</b>\n".
        "/geo · /buyers · /lps1 · /lps2 [период]\n\n".
        "<b>MVT (разбивка по вариантам):</b>\n".
        "/mvt 33169 [период]\n\n".
        "<b>Compare двух (с Δ%):</b>\n".
        "/compare 33169 205215\n".
        "/compare DK BR за неделю\n\n".
        "<b>3h-пуш с compare:</b>\n".
        "/bind 33169 205215 [name]\n".
        "/groups · /unbind &lt;name&gt;\n\n".
        "<b>Свободно:</b>\n".
        "/ai &lt;вопрос&gt;\n\n".
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
        "<b>Сырой ввод:</b>\n".
        "<code>DK</code>, <code>33169</code>, <code>BR 7d</code> — \n".
        "то же что /stats но без слэша\n\n".
        "<b>Статы:</b>\n".
        "/stats &lt;примитив&gt; [период]\n".
        "/compare &lt;a&gt; &lt;b&gt; [...] [период]\n\n".
        "<b>Топы (overview):</b>\n".
        "/geo [период] — все страны\n".
        "/buyers [период] — топ баеров\n".
        "/lps1 / /lps2 — топ лендов по позиции\n\n".
        "<b>MVT-разбивка:</b>\n".
        "/mvt &lt;id&gt; [период]\n\n".
        "<b>Группы (3h-пуш):</b>\n".
        "/bind &lt;id1&gt; &lt;id2&gt; [name]\n".
        "/groups · /unbind &lt;name&gt;\n\n".
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

    if (! H::runStats($bot, $args)) {
        $bot->sendMessage('Не понял примитив. /help — что поддерживается.');
    }
})->description('Метрики (страна, прокл, …)');

$command('geo', function (Nutgram $bot) {
    H::runRanking($bot, 'geo', H::args($bot));
})->description('Топ стран');

$command('buyers', function (Nutgram $bot) {
    H::runRanking($bot, 'buyers', H::args($bot));
})->description('Топ баеров');

$command('lps1', function (Nutgram $bot) {
    H::runRanking($bot, 'lp1', H::args($bot));
})->description('Топ лендингов на LP1');

$command('lps2', function (Nutgram $bot) {
    H::runRanking($bot, 'lp2', H::args($bot));
})->description('Топ лендингов на LP2');

$command('mvt', function (Nutgram $bot) {
    H::runMvt($bot, H::args($bot));
})->description('MVT-разбивка по лендингу');

$command('bind', function (Nutgram $bot) {
    try {
        H::bind($bot, H::args($bot));
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Забиндить группу лендингов (3h compare push)');

$command('groups', function (Nutgram $bot) {
    try {
        H::groupsList($bot);
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Мои compare-группы');

$command('unbind', function (Nutgram $bot) {
    try {
        H::unbind($bot, H::args($bot));
    } catch (Throwable $e) {
        $bot->sendMessage('Ошибка: '.$e->getMessage());
    }
})->description('Снять группу');

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

    H::withPlaceholder($bot, function () use ($tokens, $period): string {
        $user = app(\App\Services\Auth\AppContext::class)->user();
        $window = app(PeriodParser::class)->parse($period, $user?->timezone);
        $names = $user?->metricPreferences();

        return app(ComparisonReporter::class)->report($tokens, $window, $names);
    });
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

// Raw-input shortcut: bare "33169" / "DK" / "33169 7d" without the /stats
// prefix routes straight into the stats pipeline. The regex deliberately
// only matches things that LOOK like our primitives — country codes, numeric
// human_ids, or UUIDs — followed by an optional period blob. Anything else
// falls through to the fallback handler.
$bot->onText('([A-Za-z]{2}|\d+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:\s+(.+))?', function (Nutgram $bot, ?string $token, ?string $rest) {
    if ($token === null) {
        return;
    }
    $args = [$token];
    if ($rest !== null && $rest !== '') {
        foreach (preg_split('/\s+/', trim($rest)) ?: [] as $w) {
            if ($w !== '') {
                $args[] = $w;
            }
        }
    }

    // Soft-fail: if the resolver can't understand it, drop through to fallback
    // by silently returning — fallback then sends "Не понял" so we don't get
    // double messages.
    if (! H::runStats($bot, $args)) {
        $bot->sendMessage('Не понял. /help — список команд.');
    }
});

$bot->fallback(function (Nutgram $bot) {
    $bot->sendMessage('Не понял. /help — список команд.');
});
