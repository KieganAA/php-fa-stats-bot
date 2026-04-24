<?php

namespace App\Services\Aio\Sync;

use App\Models\Aio\Field as FieldModel;
use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\Field;
use Illuminate\Support\Facades\DB;

class FieldSyncer
{
    public function __construct(private readonly AioClient $aio) {}

    public function sync(int $chunkSize = 200): SyncReport
    {
        $report = new SyncReport;
        $started = microtime(true);
        $batch = [];

        foreach ($this->aio->streamFields() as $field) {
            $report->fetched++;
            $batch[] = $this->toRow($field);

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

    private function toRow(Field $f): array
    {
        $now = now();

        return [
            'uuid' => $f->uuid,
            'slug' => $f->slug,
            'field' => $f->name,
            'data_source' => $f->dataSource,
            'group' => $f->group,
            'format' => $f->format,
            'ch_column' => $f->chColumn,
            'description' => $f->description,
            'access_type' => $f->accessType,
            'raw' => json_encode($f->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function flush(array $rows): int
    {
        return DB::table((new FieldModel)->getTable())->upsert(
            $rows,
            uniqueBy: ['uuid'],
            update: [
                'slug', 'field', 'data_source', 'group', 'format',
                'ch_column', 'description', 'access_type', 'raw',
                'synced_at', 'updated_at',
            ],
        );
    }
}
