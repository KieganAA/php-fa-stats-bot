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

        // Curated order — Telegram shows them in the order we send. Order also
        // drives the suggestion menu.
        //
        // Campaign-first pivot: the bot's job is now "subscribe to a campaign →
        // auto-track its splits & MVT". So the menu leads with the campaign
        // trio and a thin support set (extension is the primary entry point).
        // The old primitive commands (/stats /compare /geo /buyers /lps1 /lps2
        // /mvt /bind /groups /unbind /ai /ping /guide) still work if typed —
        // they're just hidden from the suggestion menu so the surface stays
        // focused. Re-add a row here to resurface any of them.
        $commands = [
            ['campaign', 'подписаться на кампанию (сплиты + MVT)'],
            ['campaigns', 'мои подписки на кампании'],
            ['resync', 'обновить структуру кампаний'],
            ['extension', '🧩 установить Chrome-расширение'],
            ['extension_token', 'обновить токен расширения'],
            ['open', '📱 открыть мини-апп'],
            ['help', 'справка'],
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
