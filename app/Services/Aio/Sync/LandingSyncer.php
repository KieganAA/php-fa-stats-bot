<?php

namespace App\Services\Aio\Sync;

use App\Models\Aio\Landing as LandingModel;
use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\Landing;
use Illuminate\Support\Facades\DB;

class LandingSyncer
{
    public function __construct(private readonly AioClient $aio) {}

    public function sync(int $chunkSize = 200): SyncReport
    {
        $report = new SyncReport;
        $started = microtime(true);
        $batch = [];

        foreach ($this->aio->streamLandings() as $landing) {
            $report->fetched++;
            $batch[] = $this->toRow($landing);

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

    private function toRow(Landing $l): array
    {
        $now = now();

        return [
            'uuid' => $l->uuid,
            'human_id' => $l->humanId,
            'name' => $l->name,
            'landing_type_uuid' => $l->landingTypeUuid,
            'landing_type_name' => $l->landingTypeName,
            'owner_uuid' => $l->ownerUuid,
            'owner_name' => $l->ownerName,
            'countries' => json_encode($l->countries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_archived' => $l->isArchived,
            'raw' => json_encode($l->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'aio_created_at' => $l->aioCreatedAt !== null ? date('c', $l->aioCreatedAt) : null,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function flush(array $rows): int
    {
        return DB::table((new LandingModel)->getTable())->upsert(
            $rows,
            uniqueBy: ['uuid'],
            update: [
                'human_id', 'name', 'landing_type_uuid', 'landing_type_name',
                'owner_uuid', 'owner_name', 'countries', 'is_archived',
                'raw', 'aio_created_at', 'synced_at', 'updated_at',
            ],
        );
    }
}
