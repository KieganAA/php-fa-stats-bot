<?php

namespace App\Console\Commands\Telegram;

use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Common\MenuButtonWebApp;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

class SetMenuButtonCommand extends Command
{
    protected $signature = 'tg:set-menu-button
        {--url= : Override APP_URL/app for the WebApp URL}
        {--text=📱 App : Button label (Telegram caps it short)}
        {--reset : Restore Telegram default menu button}';

    protected $description = 'Set the bot menu button to open the Mini App (default for all new conversations).';

    public function handle(Nutgram $bot): int
    {
        if ($this->option('reset')) {
            $bot->setChatMenuButton();
            $this->info('Menu button reset to Telegram default.');

            return self::SUCCESS;
        }

        $url = (string) $this->option('url');
        if ($url === '') {
            $appUrl = (string) config('app.url', '');
            if ($appUrl === '') {
                $this->error('APP_URL is empty. Pass --url=https://… explicitly.');

                return self::FAILURE;
            }
            $url = rtrim($appUrl, '/').'/app';
        }

        if (! str_starts_with($url, 'https://')) {
            $this->error("Mini App URL must be HTTPS, got: {$url}");

            return self::FAILURE;
        }

        $button = new MenuButtonWebApp;
        $button->type = 'web_app';
        $button->text = (string) $this->option('text');
        $button->web_app = WebAppInfo::make(url: $url);

        $bot->setChatMenuButton(menu_button: $button);

        $this->info("Menu button set → {$url}");

        return self::SUCCESS;
    }
}
