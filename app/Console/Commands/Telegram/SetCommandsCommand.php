<?php

namespace App\Console\Commands\Telegram;

use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommand;

/**
 * Pushes the bot's command list to Telegram so they show up in the typing-
 * suggestion menu when a user starts typing `/`. Without this, the bot
 * responds to commands but Telegram has no idea what's available.
 *
 * Re-run any time the command set changes (added /extension etc).
 */
class SetCommandsCommand extends Command
{
    protected $signature = 'tg:set-commands
        {--reset : Clear all commands instead of registering}
        {--list : Just print what would be sent, no API call}';

    protected $description = 'Push the bot command list to Telegram (setMyCommands).';

    public function handle(Nutgram $bot): int
    {
        if ($this->option('reset')) {
            $bot->setMyCommands([]);
            $this->info('Cleared all bot commands.');

            return self::SUCCESS;
        }

        // Curated order — Telegram shows them in the order we send. Put the
        // most common ones first. Order also drives the suggestion menu.
        $commands = [
            ['stats', 'статы по примитиву'],
            ['compare', 'сравнить 2+ примитивов'],
            ['geo', 'топ стран'],
            ['buyers', 'топ баеров'],
            ['lps1', 'топ лендингов на LP1'],
            ['lps2', 'топ лендингов на LP2'],
            ['mvt', 'MVT-разбивка ленда'],
            ['bind', 'забиндить подписку'],
            ['groups', 'мои подписки'],
            ['unbind', 'снять подписку'],
            ['open', '📱 открыть мини-апп'],
            ['extension', '🧩 установить Chrome-расширение'],
            ['extension_token', 'обновить токен расширения'],
            ['extension_revoke', 'отозвать токен расширения'],
            ['guide', '📖 гайд по мини-аппу'],
            ['help', 'справка'],
            ['ai', 'свободный вопрос (AI)'],
            ['ping', 'проверка связи'],
            ['start', 'начать сначала'],
        ];

        if ($this->option('list')) {
            foreach ($commands as [$cmd, $desc]) {
                $this->line(sprintf('  /%-22s %s', $cmd, $desc));
            }

            return self::SUCCESS;
        }

        $payload = array_map(
            fn (array $row) => new BotCommand(command: $row[0], description: $row[1]),
            $commands,
        );

        $bot->setMyCommands($payload);

        $this->info('Registered '.count($payload).' commands with Telegram.');
        $this->line('Type `/` in the bot chat — they should pop up as suggestions.');

        return self::SUCCESS;
    }
}
