<?php

namespace App\Services\Aio\Sync;

use App\Models\Aio\User as UserModel;
use App\Services\Aio\AioClient;
use App\Services\Aio\Dto\User;
use Illuminate\Support\Facades\DB;

class UserSyncer
{
    public function __construct(private readonly AioClient $aio) {}

    public function sync(int $chunkSize = 200): SyncReport
    {
        $report = new SyncReport;
        $started = microtime(true);
        $batch = [];

        foreach ($this->aio->streamUsers() as $user) {
            $report->fetched++;
            $batch[] = $this->toRow($user);

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

    private function toRow(User $u): array
    {
        $now = now();

        return [
            'uuid' => $u->uuid,
            'name' => $u->name,
            'is_active' => $u->isActive,
            'raw' => json_encode($u->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'aio_created_at' => $u->aioCreatedAt !== null ? date('c', $u->aioCreatedAt) : null,
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function flush(array $rows): int
    {
        return DB::table((new UserModel)->getTable())->upsert(
            $rows,
            uniqueBy: ['uuid'],
            update: ['name', 'is_active', 'raw', 'aio_created_at', 'synced_at', 'updated_at'],
        );
    }
}
