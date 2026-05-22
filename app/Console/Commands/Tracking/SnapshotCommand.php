<?php

namespace App\Console\Commands\Tracking;

use App\Jobs\NotifyLandingSnapshotJob;
use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Services\Tracking\LandingSnapshotter;
use Illuminate\Console\Command;
use Throwable;

class SnapshotCommand extends Command
{
    protected $signature = 'tracking:snapshot
        {--id=* : restrict to specific tracked_landings.id values}
        {--kind=both : 3h | since_start | both}
        {--no-notify : capture only, do not dispatch notify jobs}';

    protected $description = 'Capture aggregate snapshots for active tracked landings and fan out notifications to subscribers.';

    public function handle(LandingSnapshotter $snapshotter): int
    {
        $tracked = $this->loadTargets();
        if ($tracked->isEmpty()) {
            $this->info('No active tracked landings.');

            return self::SUCCESS;
        }

        $kindOpt = (string) $this->option('kind');
        $notify = ! $this->option('no-notify');
        $failures = 0;

        foreach ($tracked as $landing) {
            $label = "{$landing->id}/{$landing->landing_uuid}@{$landing->position}";
            $this->line("→ snapshot {$label}");
            try {
                $snapshots = $this->captureForKind($snapshotter, $landing, $kindOpt);
                foreach ($snapshots as $snapshot) {
                    $this->info("  {$snapshot->kind} snapshot #{$snapshot->id}");
                    if ($notify) {
                        $this->fanOut($snapshot, $landing);
                    }
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error("  failed: {$e->getMessage()}");
                report($e);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<LandingSnapshot> */
    private function captureForKind(LandingSnapshotter $snapshotter, TrackedLanding $landing, string $kind): array
    {
        return match ($kind) {
            '3h' => [$snapshotter->capture($landing, LandingSnapshot::KIND_3H)],
            'since_start' => [$snapshotter->capture($landing, LandingSnapshot::KIND_SINCE_START)],
            'both' => $snapshotter->captureBoth($landing),
            default => throw new \InvalidArgumentException("Unknown kind: {$kind}"),
        };
    }

    private function fanOut(LandingSnapshot $snapshot, TrackedLanding $landing): void
    {
        $column = $snapshot->kind === LandingSnapshot::KIND_SINCE_START
            ? 'notify_since_start'
            : 'notify_3h';

        $userIds = $landing->bindings()->where($column, true)->pluck('user_id');
        foreach ($userIds as $userId) {
            NotifyLandingSnapshotJob::dispatch((int) $userId, (int) $snapshot->id);
        }
        $this->line('  dispatched '.$userIds->count().' notify jobs');
    }

    private function loadTargets()
    {
        $query = TrackedLanding::query()->whereNull('paused_at');
        $ids = (array) $this->option('id');
        if ($ids !== []) {
            $query->whereIn('id', array_map('intval', $ids));
        }

        return $query->orderBy('id')->get();
    }
}
