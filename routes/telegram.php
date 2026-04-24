<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

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
        "Доступные команды:\n".
        "/ping — проверка связи\n".
        "/help — справка (пока такая же)\n\n".
        "В следующих фазах добавится /stats, /bind, /compare и AI-режим."
    );
})->description('Стартовое сообщение');

$bot->onCommand('ping', function (Nutgram $bot) {
    $bot->sendMessage('pong 🏓');
})->description('Проверка связи');

$bot->onCommand('help', function (Nutgram $bot) {
    $bot->sendMessage(
        "Справка:\n".
        "/start — приветствие\n".
        "/ping — проверка связи"
    );
})->description('Справка');

// Fallback for anything unhandled during Phase 0.
$bot->fallback(function (Nutgram $bot) {
    $bot->sendMessage("Пока не умею отвечать на это. Попробуй /start.");
});
