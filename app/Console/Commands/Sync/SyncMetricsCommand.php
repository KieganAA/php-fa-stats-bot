<?php

namespace App\Console\Commands\Sync;

use App\Services\Aio\Sync\MetricSyncer;
use Illuminate\Console\Command;

class SyncMetricsCommand extends Command
{
    protected $signature = 'aio:sync:metrics';

    protected $description = 'Sync AIO Settings\\Metrics into aio_metrics.';

    public function handle(MetricSyncer $syncer): int
    {
        $this->info('Syncing metrics…');
        $report = $syncer->sync();
        $this->info('  '.$report->summary());

        return self::SUCCESS;
    }
}
