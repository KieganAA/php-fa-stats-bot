<?php

namespace App\Console\Commands\Telegram;

use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

/**
 * Replacement for nutgram:hook:set that wires up the secret_token from
 * TELEGRAM_WEBHOOK_SECRET (which our VerifyTelegramWebhook middleware
 * checks). The upstream nutgram:hook:set ties secret_token to the
 * nutgram.safe_mode config + md5(APP_KEY), which we don't use — so its
 * webhook registration arrived without a header our middleware would
 * accept, and every update came back 403.
 */
class SetWebhookCommand extends Command
{
    protected $signature = 'tg:set-webhook
        {--url= : Override TELEGRAM_WEBHOOK_URL}
        {--max-connections=50 : Telegram max parallel webhook deliveries}';

    protected $description = 'Register the bot webhook using our TELEGRAM_WEBHOOK_URL + TELEGRAM_WEBHOOK_SECRET.';

    public function handle(Nutgram $bot): int
    {
        $url = (string) ($this->option('url') ?: config('services.telegram.webhook_url'));
        $secret = (string) config('services.telegram.webhook_secret');

        if ($url === '') {
            $this->error('Webhook URL is empty. Set TELEGRAM_WEBHOOK_URL or pass --url=https://…');

            return self::FAILURE;
        }
        if (! str_starts_with($url, 'https://')) {
            $this->error("Webhook URL must be HTTPS, got: {$url}");

            return self::FAILURE;
        }

        $bot->setWebhook(
            url: $url,
            max_connections: (int) $this->option('max-connections'),
            secret_token: $secret !== '' ? $secret : null,
        );

        $this->info("Webhook set → {$url}");
        if ($secret === '') {
            $this->warn('TELEGRAM_WEBHOOK_SECRET is empty — VerifyTelegramWebhook will accept all callers. Set the secret in production.');
        }

        return self::SUCCESS;
    }
}
