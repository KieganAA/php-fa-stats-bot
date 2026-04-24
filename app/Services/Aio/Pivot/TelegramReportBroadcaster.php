<?php

namespace App\Services\Aio\Pivot;

use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Sends a formatted MVT report to every configured Telegram chat
 * (services.telegram.report_chat_ids — comma-separated TELEGRAM_REPORT_CHAT_IDS).
 *
 * Each send is independent: a failure to one chat is logged and broadcasting
 * continues to the remaining chats.
 */
class TelegramReportBroadcaster
{
    public function __construct(
        private readonly Nutgram $bot,
    ) {}

    /** @return array{sent: int, failed: int} */
    public function broadcast(string $html): array
    {
        $chatIds = (array) config('services.telegram.report_chat_ids', []);
        $sent = 0;
        $failed = 0;

        foreach ($chatIds as $chatId) {
            try {
                $this->bot->sendMessage(
                    text: $html,
                    chat_id: (int) $chatId,
                    parse_mode: 'HTML',
                    disable_web_page_preview: true,
                );
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('telegram report broadcast failed', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
