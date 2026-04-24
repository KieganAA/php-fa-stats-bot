<?php

namespace App\Console\Commands\Sync;

use App\Services\Aio\Sync\FieldSyncer;
use Illuminate\Console\Command;

class SyncFieldsCommand extends Command
{
    protected $signature = 'aio:sync:fields';

    protected $description = 'Sync AIO Settings\\Fields into aio_fields.';

    public function handle(FieldSyncer $syncer): int
    {
        $this->info('Syncing fields…');
        $report = $syncer->sync();
        $this->info('  '.$report->summary());

        return self::SUCCESS;
    }
}
