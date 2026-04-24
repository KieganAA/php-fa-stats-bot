<?php

namespace App\Services\Aio\Sync;

use App\Models\Aio\Metric as MetricModel;
use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\Metric;
use Illuminate\Support\Facades\DB;

class MetricSyncer
{
    public function __construct(private readonly AioClient $aio) {}

    public function sync(int $chunkSize = 200): SyncReport
    {
        $report = new SyncReport;
        $started = microtime(true);
        $batch = [];

        foreach ($this->aio->streamMetrics() as $metric) {
            $report->fetched++;
            $batch[] = $this->toRow($metric);

            if (count($batch) >= $chunkSize) {
                $report->upserted += $this->flush($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $report->upserted += $this->flush($batch);
        }

        $report->durationSeconds = microtime(true) - $started;

        return $report;
    }

    private function toRow(Metric $m): array
    {
        $now = now();

        return [
            'uuid' => $m->uuid,
            'name' => $m->name,
            'format' => $m->format,
            'type' => $m->type,
            'description' => $m->description,
            'raw' => json_encode($m->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function flush(array $rows): int
    {
        return DB::table((new MetricModel)->getTable())->upsert(
            $rows,
            uniqueBy: ['uuid'],
            update: [
                'name', 'format', 'type', 'description',
                'raw', 'synced_at', 'updated_at',
            ],
        );
    }
}
