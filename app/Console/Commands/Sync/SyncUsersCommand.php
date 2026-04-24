<?php

namespace App\Console\Commands\Sync;

use App\Services\Aio\Sync\UserSyncer;
use Illuminate\Console\Command;

class SyncUsersCommand extends Command
{
    protected $signature = 'aio:sync:users';

    protected $description = 'Sync AIO users into aio_users.';

    public function handle(UserSyncer $syncer): int
    {
        $this->info('Syncing users…');
        $report = $syncer->sync();
        $this->info('  '.$report->summary());

        return self::SUCCESS;
    }
}
