<?php

namespace App\Console\Commands\Tracking;

use App\Jobs\NotifyCompareGroupJob;
use App\Models\LandingSnapshot;
use App\Models\TrackedLanding;
use App\Models\UserCompareGroup;
use App\Services\Tracking\LandingSnapshotter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Periodic 3h tick:
 *   1. Capture an aggregate snapshot for every active tracked landing
 *      (useful for trend history; not strictly required for notifications).
 *   2. Dispatch a NotifyCompareGroupJob per active compare group — the job
 *      itself rebuilds the compare report via live AIO calls.
 *
 * Snapshot capture stays even if no one subscribed via compare groups, so
 * we accumulate history for the eventual "drilldown" / Mini App charting
 * surface.
 */
class SnapshotCommand extends Command
{
    protected $signature = 'tracking:snapshot
        {--id=* : restrict to specific tracked_landings.id values}
        {--kind=3h : 3h | since_start | both}
        {--no-capture : skip snapshot capture (dispatch notify jobs only)}
        {--no-notify : capture only, skip dispatch}';

    protected $description = 'Capture aggregate snapshots and fan out compare-group notifications.';

    public function handle(LandingSnapshotter $snapshotter): int
    {
        $captureFailures = 0;

        if (! $this->option('no-capture')) {
            $captureFailures = $this->captureSnapshots($snapshotter);
        }

        if (! $this->option('no-notify')) {
            $this->fanOutCompareGroups();
        }

        return $captureFailures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function captureSnapshots(LandingSnapshotter $snapshotter): int
    {
        $tracked = $this->loadTargets();
        if ($tracked->isEmpty()) {
            $this->info('No active tracked landings.');

            return 0;
        }

        $kindOpt = (string) $this->option('kind');
        $failures = 0;

        foreach ($tracked as $landing) {
            $label = "{$landing->id}/{$landing->landing_uuid}@{$landing->position}";
            $this->line("→ snapshot {$label}");
            try {
                $snapshots = $this->captureForKind($snapshotter, $landing, $kindOpt);
                foreach ($snapshots as $s) {
                    $this->info("  {$s->kind} snapshot #{$s->id}");
                }
            } catch (Throwable $e) {
                $failures++;
                $this->error("  failed: {$e->getMessage()}");
                report($e);
            }
        }

        return $failures;
    }

    private function fanOutCompareGroups(): void
    {
        // All active groups with at least one member. The job itself
        // branches on group.mode (compare / mvt) — see NotifyCompareGroupJob.
        $groups = UserCompareGroup::query()
            ->whereNull('paused_at')
            ->has('members', '>=', 1)
            ->get();

        foreach ($groups as $g) {
            NotifyCompareGroupJob::dispatch((int) $g->user_id, (int) $g->id);
        }
        $this->info('Dispatched tracking-group jobs: '.$groups->count());
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
