<?php

namespace App\Services\Aio\Sync;

use App\Models\Aio\LandingType as LandingTypeModel;
use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\LandingType;
use Illuminate\Support\Facades\DB;

class LandingTypeSyncer
{
    public function __construct(private readonly AioClient $aio) {}

    public function sync(int $chunkSize = 200): SyncReport
    {
        $report = new SyncReport;
        $started = microtime(true);
        $batch = [];

        foreach ($this->aio->streamLandingTypes() as $type) {
            $report->fetched++;
            $batch[] = $this->toRow($type);

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

    private function toRow(LandingType $t): array
    {
        $now = now();

        return [
            'uuid' => $t->uuid,
            'name' => $t->name,
            'raw' => json_encode($t->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'aio_created_at' => $t->aioCreatedAt !== null ? date('c', $t->aioCreatedAt) : null,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function flush(array $rows): int
    {
        return DB::table((new LandingTypeModel)->getTable())->upsert(
            $rows,
            uniqueBy: ['uuid'],
            update: ['name', 'raw', 'aio_created_at', 'synced_at', 'updated_at'],
        );
    }
}
