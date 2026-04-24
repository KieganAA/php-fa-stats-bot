<?php

namespace App\Services\Aio\Sync;

class SyncReport
{
    public int $fetched = 0;

    public int $upserted = 0;

    public int $pruned = 0;

    public float $durationSeconds = 0.0;

    public function summary(): string
    {
        return sprintf(
            'fetched=%d upserted=%d pruned=%d in %.2fs',
            $this->fetched,
            $this->upserted,
            $this->pruned,
            $this->durationSeconds,
        );
    }
}
