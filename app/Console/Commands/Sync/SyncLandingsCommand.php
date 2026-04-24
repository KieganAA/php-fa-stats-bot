<?php

namespace App\Console\Commands\Sync;

use App\Services\Aio\Sync\LandingSyncer;
use Illuminate\Console\Command;

class SyncLandingsCommand extends Command
{
    protected $signature = 'aio:sync:landings';

    protected $description = 'Sync AIO landings into the local aio_landings table.';

    public function handle(LandingSyncer $syncer): int
    {
        $this->info('Syncing landings…');
        $report = $syncer->sync();
        $this->info('  '.$report->summary());

        return self::SUCCESS;
    }
}
