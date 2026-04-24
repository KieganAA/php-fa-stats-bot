<?php

namespace App\Console\Commands\Sync;

use App\Services\Aio\Sync\LandingTypeSyncer;
use Illuminate\Console\Command;

class SyncLandingTypesCommand extends Command
{
    protected $signature = 'aio:sync:landing-types';

    protected $description = 'Sync AIO landing types into aio_landing_types.';

    public function handle(LandingTypeSyncer $syncer): int
    {
        $this->info('Syncing landing types…');
        $report = $syncer->sync();
        $this->info('  '.$report->summary());

        return self::SUCCESS;
    }
}
