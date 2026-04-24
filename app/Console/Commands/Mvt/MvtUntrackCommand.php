<?php

namespace App\Console\Commands\Mvt;

use App\Models\Aio\Landing;
use App\Models\TrackedLanding;
use Illuminate\Console\Command;

class MvtUntrackCommand extends Command
{
    protected $signature = 'mvt:untrack
        {landing : aio_landings.uuid or numeric human_id}
        {--position=1 : landing position in the funnel}
        {--purge : delete the tracking record (and its slices) instead of pausing}';

    protected $description = 'Pause or delete a tracked landing.';

    public function handle(): int
    {
        $landing = $this->resolveLanding($this->argument('landing'));
        if ($landing === null) {
            $this->error('Landing not found in aio_landings.');

            return self::FAILURE;
        }

        $tracked = TrackedLanding::query()
            ->where('landing_uuid', $landing->uuid)
            ->where('position', (int) $this->option('position'))
            ->first();

        if ($tracked === null) {
            $this->warn('No tracking record found for this landing+position.');

            return self::SUCCESS;
        }

        if ($this->option('purge')) {
            $tracked->delete();
            $this->info("Deleted tracking #{$tracked->id} (slices cascaded).");

            return self::SUCCESS;
        }

        if ($tracked->paused_at !== null) {
            $this->warn("Already paused at {$tracked->paused_at}.");

            return self::SUCCESS;
        }

        $tracked->update(['paused_at' => now()]);
        $this->info("Paused tracking #{$tracked->id}.");

        return self::SUCCESS;
    }

    private function resolveLanding(string $arg): ?Landing
    {
        if (ctype_digit($arg)) {
            return Landing::query()->where('human_id', (int) $arg)->first();
        }

        return Landing::query()->where('uuid', $arg)->first();
    }
}
