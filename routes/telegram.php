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
        "👋 Привет! Я fa-stats-bot — статка из AIO без захода на сайт.\n\n".
        "<b>Самое простое</b> — просто напиши в чат, что хочешь:\n".
        "• <code>DK</code> — Дания сегодня\n".
        "• <code>33169</code> — прокл по human_id\n".
        "• <code>сравни 33169 и 205215 за неделю</code>\n".
        "• <code>че там по BR вчера</code>\n\n".
        "Если бот не понимает или хочешь сразу нужный отчёт — есть точные команды (/help).\n\n".
        "<b>📱 Мини-апп</b> — кнопками, без перепечатывания, с настройками. Гайд внутри (/guide).\n\n".
        "<i>Краткая шпаргалка по командам — /help.</i>",
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

$command('extension', function (Nutgram $bot) {
    $user = app(AppContext::class)->user();
    if ($user === null) {
        $bot->sendMessage('Не могу определить юзера.');

        return;
    }

    $service = app(\App\Services\Auth\ExtensionTokenService::class);
    $plain = $service->rotate($user);
    $appUrl = rtrim((string) config('app.url', ''), '/');

    // Send the ZIP as a Telegram document attachment so it bypasses any
    // tunnel/proxy/interstitial between the bot and the user (especially
    // ngrok-free which shows an HTML splash before binary downloads).
    try {
        $built = app(\App\Services\Support\ExtensionZipBuilder::class)->build();
        $bot->sendDocument(
            document: \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make($built['path'], $built['filename']),
            caption: "📦 Архив расширения bot-stats (".round($built['size'] / 1024)." KB)",
        );
        @unlink($built['path']);
    } catch (Throwable $e) {
        $bot->sendMessage(
            "⚠️ Не удалось собрать архив расширения: ".htmlspecialchars($e->getMessage()).
            ($appUrl !== '' ? "\n\nМожно скачать вручную: <code>{$appUrl}/extension.zip</code>" : ''),
            parse_mode: 'HTML',
        );
    }

    $bot->sendMessage(
        "🧩 <b>Установка Chrome-расширения</b>\n\n".
        "Расширение помогает прямо со страниц AIO отмечать ленды и одним кликом ".
        "отправлять их в бот как новые подписки (пуш каждые 1/3/6/12/24 часа).\n\n".

        "━━━━━━━━━━━━━━━━━━\n\n".

        "<b>Шаг 1 — распакуй архив</b>\n".
        "Файл выше: <code>bot-stats-extension.zip</code>. Скачай (в Telegram у файла кнопка ⬇️), ".
        "потом двойной клик → появится папка <code>bot-stats-extension/</code>. Запомни путь.\n\n".

        "<b>Шаг 2 — открой страницу расширений</b>\n".
        "В адресной строке Chrome: <code>chrome://extensions</code>\n\n".

        "<b>Шаг 3 — включи «Режим разработчика»</b>\n".
        "Тумблер <b>справа сверху</b>. После включения слева появятся 3 кнопки: ".
        "«Загрузить распакованное», «Упаковать расширение», «Обновить».\n\n".

        "<b>Шаг 4 — загрузи расширение</b>\n".
        "Жми <b>«Загрузить распакованное» / «Load unpacked»</b> (первая кнопка слева). ".
        "В диалоге выбери папку <code>bot-stats-extension/</code> из шага 1. ".
        "В списке появится «bot-stats helper».\n\n".

        "<b>Шаг 5 — закрепи иконку</b>\n".
        "Справа от адресной строки иконка <b>🧩 пазла</b> → клик → у «bot-stats helper» жми <b>📌</b>. ".
        "Теперь иконка расширения в тулбаре всегда видна.\n\n".

        "<b>Шаг 6 — введи токен</b>\n".
        "Клик по иконке расширения → ⚙️ <b>«Открыть настройки»</b>. Вставь:\n\n".
        "<b>API URL:</b>\n<code>".htmlspecialchars($appUrl ?: '(не настроен)')."</code>\n\n".
        "<b>Токен:</b>\n<code>".htmlspecialchars($plain).'</code>'."\n\n".
        "Жми «Сохранить». Должно появиться <i>✓ Подключено как ...</i>\n\n".

        "━━━━━━━━━━━━━━━━━━\n\n".

        "<b>Как пользоваться</b>\n".
        "• Открой <code>app.aio.tech</code> → справа снизу плавающая панель с найденными лендами\n".
        "• Отмечай чекбоксами → жми <b>«→ N»</b> → они улетят в очередь\n".
        "• Клик по иконке расширения → выбери частоту → <b>«Создать»</b>\n\n".

        "<b>Бонусы</b>\n".
        "• Выдели любой текст с id (например <code>33169, 205215</code>) → правый клик → <b>«Отправить выделенное в bot-stats»</b>\n".
        "• Панель на странице AIO можно таскать за заголовок — позиция запомнится\n".
        "• Потерял токен? Запусти /extension_token — выдам новый\n".
        "• Совсем отозвать: /extension_revoke",
        parse_mode: 'HTML',
        disable_web_page_preview: true,
    );
})->description('Установить Chrome-расширение');

$command('extension_token', function (Nutgram $bot) {
    $user = app(AppContext::class)->user();
    if ($user === null) {
        $bot->sendMessage('Не могу определить юзера.');

        return;
    }

    $service = app(\App\Services\Auth\ExtensionTokenService::class);
    $plain = $service->rotate($user);
    $appUrl = rtrim((string) config('app.url', ''), '/');

    $bot->sendMessage(
        "🔑 <b>Новый токен для расширения</b>\n\n".
        "Скопируй и вставь в настройки расширения (значок ⚙️ в popup):\n\n".
        "<b>API URL:</b>\n<code>".htmlspecialchars($appUrl ?: '(не настроен)')."</code>\n\n".
        "<b>Токен:</b>\n<code>".htmlspecialchars($plain).'</code>'."\n\n".
        "<i>Подробный гайд: /extension</i>",
        parse_mode: 'HTML',
    );
})->description('Только токен (без гайда)');

$command('extension_revoke', function (Nutgram $bot) {
    $user = app(AppContext::class)->user();
    if ($user === null) {
        $bot->sendMessage('Не могу определить юзера.');

        return;
    }
    app(\App\Services\Auth\ExtensionTokenService::class)->revoke($user);
    $bot->sendMessage('🗑 Токен Chrome-расширения отозван. Расширение сразу перестанет работать. Новый — /extension_token.');
})->description('Отозвать токен расширения');

$command('guide', function (Nutgram $bot) {
    // Deep-links straight to the in-app guide page so the user lands on the
    // help tab without a tap. Vue router uses hash-history.
    $keyboard = H::openMiniAppKeyboard('📖 Открыть гайд в мини-аппе', '#/help');
    $bot->sendMessage(
        "<b>📖 Гайд по мини-аппу</b>\n\n".
        "Внутри есть подробное описание всех вкладок:\n\n".
        "• <b>📊 Статы</b> — три режима: один примитив, compare двух+, MVT-разбивка ленда\n".
        "• <b>🏆 Топы</b> — топ-15 стран/баеров/лендов за период\n".
        "• <b>🔔 Подписки</b> — авто-пуш в чат каждые 1/3/6/12/24 часа\n".
        "• <b>⚙️ Настройки</b> — пресеты метрик под каждый отчёт, переименование колонок, ".
        "часовой пояс, ключ Anthropic\n".
        "• <b>❓ Помощь</b> — полный гайд с примерами и инструкциями\n\n".
        ($keyboard !== null
            ? "Жми кнопку — откроется сразу на вкладке Помощь."
            : "Mini App URL не настроен — задай APP_URL в .env."),
        parse_mode: 'HTML',
        reply_markup: $keyboard,
    );
})->description('Гайд по мини-аппу');

$command('help', function (Nutgram $bot) {
    $bot->sendMessage(
        "<b>Как пользоваться</b>\n\n".

        "<b>1. Пиши в чат свободно</b> — бот разбирает естественный язык:\n".
        "• <code>DK</code> или <code>как DK</code> — Дания сегодня\n".
        "• <code>33169 за неделю</code> — прокл за 7 дней\n".
        "• <code>сравни DK и BR</code> — Δ% между странами\n".
        "• <code>что там по 205228 вчера</code> — конкретный ленд\n".
        "Под капотом — Claude, со встроенными инструментами stats/compare. ".
        "Если хочется быстрее (без AI-задержки) — есть команды ниже.\n\n".

        "<b>2. Команды (без AI, мгновенно):</b>\n".
        "/stats &lt;примитив&gt; [период] — одиночные цифры\n".
        "/compare &lt;a&gt; &lt;b&gt; [...] [период] — сайд-бай-сайд\n".
        "/geo [период] — топ-15 стран\n".
        "/buyers [период] — топ баеров\n".
        "/lps1 / /lps2 [период] — топ лендов по позиции\n".
        "/mvt &lt;id&gt; [период] — MVT-разбивка ленда\n\n".

        "<b>3. Группы (автопуш каждые 3ч):</b>\n".
        "/bind &lt;id1&gt; &lt;id2&gt; [name] — забиндить compare-группу\n".
        "/bind &lt;id&gt; [name] — забиндить MVT-пуш одного ленда\n".
        "/groups — мои группы\n".
        "/unbind &lt;name&gt; — снять\n\n".

        "<b>Примитив</b> — что-нибудь из:\n".
        "• Код страны: <code>DK</code>, <code>BR</code>, <code>IT</code>, <code>US</code>…\n".
        "• human_id лендинга: <code>33169</code>, <code>205228</code>…\n".
        "• UUID лендинга\n\n".

        "<b>Период</b> (любой из, по умолчанию today):\n".
        "• <code>today</code> / <code>сегодня</code>\n".
        "• <code>yesterday</code> / <code>вчера</code> / <code>позавчера</code>\n".
        "• <code>7d</code>, <code>24h</code>, <code>2w</code>, <code>1m</code>\n".
        "• <code>неделя</code> / <code>прошлая неделя</code>\n".
        "• <code>месяц</code> / <code>прошлый месяц</code>\n".
        "• <code>3 дня</code>, <code>5 часов</code>\n\n".

        "<b>Метрики и пресеты</b> (тонко в мини-аппе):\n".
        "• Свой набор колонок под каждый контекст (stats, geo, mvt, …)\n".
        "• Любую AIO-метрику можно добавить (их ~80)\n".
        "• Переименовать колонку — пример: <i>Q Visits</i> → <i>Quals</i>\n".
        "• Часовой пояс, дефолтный период, отображение лендингов\n\n".

        "📖 /guide — подробный гайд по мини-аппу (для новых)\n".
        "📱 /open — открыть мини-апп\n".
        "🏓 /ping — проверка связи",
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
        $names = $user?->metricNamesFor(\App\Services\Stats\MetricColumnResolver::COMPARE);
        $labels = $user?->metricLabelOverrides() ?? [];

        return app(ComparisonReporter::class)->report($tokens, $window, $names, $labels);
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

// Anything that isn't a registered /command goes to AI. The AI handler has
// stats/compare tools that handle "DK", "33169 за неделю", "сравни 33169 и
// 205215" etc — and is forgiving of natural language ("как там DK сегодня").
// Slash commands above are still fast paths for power users; bare text is
// the friendly default.
$bot->fallback(function (Nutgram $bot) {
    $text = trim((string) ($bot->message()?->text ?? ''));
    if ($text === '') {
        return;
    }
    // Defensive: a leading "/" without a registered command means the user
    // mis-typed a command — don't shovel that into the AI, just nudge.
    if (str_starts_with($text, '/')) {
        $bot->sendMessage('Неизвестная команда. /help — список.');

        return;
    }

    H::runAi($bot, $text);
});
