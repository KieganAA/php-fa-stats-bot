<?php

/** @var Nutgram $bot */

use App\Services\Auth\AppContext;
use App\Services\Auth\TelegramUserResolver;
use App\Support\CampaignTelegram as C;
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
        "👋 Привет! Я fa-stats-bot — слежу за твоими кампаниями в AIO.\n\n".
        "Подписываешься на кампанию — я сам нахожу <b>сплиты</b> и <b>MVT</b> внутри ".
        "и шлю по ним пуши каждые 1/3/6/12/24 ч.\n\n".
        "<b>Как подписаться:</b>\n".
        "• <code>/campaign 036469</code> — по human_id кампании\n".
        "• Или жми <b>🔔</b> у кампании прямо на странице AIO — через расширение (/extension)\n\n".
        "<b>Дальше:</b>\n".
        "• /campaigns — твои подписки\n".
        "• 📱 /open — мини-апп: подписки + настройки метрик и частоты\n\n".
        "<i>Свободный текст тоже понимаю (напр. «DK за неделю») — отвечу через AI. /help — детали.</i>",
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
        "Расширение позволяет одним кликом подписаться на кампанию прямо со страницы AIO — ".
        "бот сам найдёт сплиты и MVT и будет слать по ним пуши (каждые 1/3/6/12/24 часа).\n\n".

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
        "• Открой список кампаний на <code>app.aio.tech</code>\n".
        "• У каждой кампании слева появится <b>🔔</b> — клик подписывает (бот сам раскубатурит сплиты и MVT)\n".
        "• Уже подписанные кампании помечены зелёной <b>✓</b> — клик по ней пересоберёт структуру\n\n".

        "<b>Управление</b>\n".
        "• Клик по иконке расширения 🧩 → список твоих кампаний: пауза, частота, resync, удаление\n".
        "• Там же можно подписаться вручную по <code>human_id</code> кампании\n".
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
        "Внутри есть подробное описание вкладок:\n\n".
        "• <b>🔔 Подписки</b> — твои кампании: подписаться по human_id, пауза, частота ".
        "(1/3/6/12/24 ч), resync, удаление; видно сплиты и MVT внутри каждой\n".
        "• <b>⚙️ Настройки</b> — пресеты метрик под каждый отчёт, переименование колонок, ".
        "часовой пояс, ключ Anthropic\n".
        "• <b>❓ Помощь</b> — полный гайд с примерами\n\n".
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

        "<b>🔔 Кампании — основное</b>\n".
        "Подписываешься на кампанию → бот сам находит сплиты и MVT внутри и шлёт по ним пуши.\n".
        "/campaign &lt;human_id|uuid&gt; — подписаться (напр. <code>/campaign 036469</code>)\n".
        "/campaigns — мои подписки\n".
        "/resync [id] — обновить структуру (новые/пропавшие сплиты)\n\n".

        "<b>🧩 Расширение</b> — подписка в один клик прямо на AIO\n".
        "/extension — установить (у каждой кампании появится 🔔)\n".
        "/extension_token — новый токен · /extension_revoke — отозвать\n\n".

        "<b>📱 Мини-апп</b>\n".
        "/open — список подписок + менеджмент (пауза, частота, resync, удаление)\n".
        "и настройки: пресеты метрик под отчёты, переименование колонок, часовой пояс\n\n".

        "<b>💬 Свободный текст</b> (через AI)\n".
        "• <code>DK за неделю</code>, <code>33169 вчера</code>, <code>сравни DK и BR</code>\n".
        "Период: <code>today</code>, <code>вчера</code>, <code>7d</code>, <code>неделя</code>, <code>месяц</code>…\n\n".

        "🏓 /ping — проверка связи",
        parse_mode: 'HTML',
    );
})->description('Справка');

// ===== Campaign subscriptions (the bot's new core) =====
$command('campaign', function (Nutgram $bot) {
    C::subscribe($bot, H::args($bot));
})->description('Подписаться на кампанию (авто-сплиты + MVT)');

$command('campaigns', function (Nutgram $bot) {
    C::listSubscriptions($bot);
})->description('Мои подписки на кампании');

$command('resync', function (Nutgram $bot) {
    C::resync($bot, H::args($bot));
})->description('Обновить структуру кампаний');

// Old primitive query / landing-subscription commands (/stats /geo /buyers
// /lps1 /lps2 /mvt /compare /bind /groups /unbind) were retired in the
// campaign pivot — that surface now lives in the Mini App. The underlying
// TelegramHelpers methods stay (the AI fallback still answers free-text stats
// questions like "DK за неделю"); only the slash-command shortcuts are gone.
// Git history has the registrations if any need to come back.

$command('ai', function (Nutgram $bot) {
    $text = (string) ($bot->message()?->text ?? '');
    $question = trim((string) preg_replace('/^\/ai(@\S+)?\s*/u', '', $text));

    if ($question === '') {
        $bot->sendMessage('Использование: /ai <вопрос>');

        return;
    }

    H::runAi($bot, $question);
})->description('Свободный запрос (AI)');

// Orphan decision buttons. When a resync finds a split/MVT child that vanished
// from the campaign, the bot posts a card with 🗑 удалить / 💤 оставить. The
// pattern params {action}∈{del,keep} and {id} are injected into the handler by
// name. handleOrphanDecision re-checks ownership before acting.
$bot->onCallbackQueryData('corph:{action}:{id}', function (Nutgram $bot, string $action, string $id) {
    C::handleOrphanDecision($bot, $action, (int) $id);
});

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
