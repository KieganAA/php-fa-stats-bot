<?php

namespace App\Console\Commands\Mvt;

use App\Models\Aio\Field;
use App\Models\Aio\Landing;
use App\Models\TrackedLanding;
use Illuminate\Console\Command;

class MvtTrackCommand extends Command
{
    protected $signature = 'mvt:track
        {landing : aio_landings.uuid or numeric human_id}
        {--position=1 : landing position in the funnel}
        {--field=* : MVT field slug (repeat or comma-separate)}
        {--notes= : optional notes}';

    protected $description = 'Start tracking an AIO landing for 3-hourly MVT slices.';

    public function handle(): int
    {
        $landing = $this->resolveLanding($this->argument('landing'));
        if ($landing === null) {
            $this->error('Landing not found in aio_landings — sync first or check the uuid/human_id.');

            return self::FAILURE;
        }

        $fieldSlugs = $this->collectFieldSlugs();
        if ($fieldSlugs === []) {
            $this->error('At least one --field=<slug> is required.');

            return self::FAILURE;
        }

        $fields = Field::query()->whereIn('slug', $fieldSlugs)->get();
        $missing = array_diff($fieldSlugs, $fields->pluck('slug')->all());
        if ($missing !== []) {
            $this->error('Unknown field slugs: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $tracked = TrackedLanding::query()->updateOrCreate(
            [
                'landing_uuid' => $landing->uuid,
                'position' => (int) $this->option('position'),
            ],
            [
                'tracking_started_at' => now(),
                'paused_at' => null,
                'notes' => $this->option('notes'),
            ],
        );
        $tracked->mvtFields()->sync($fields->pluck('id')->all());

        $this->info("Tracking #{$tracked->id}: {$landing->name} [pos {$tracked->position}]");
        $this->line('  fields: '.$fields->pluck('slug')->implode(', '));

        return self::SUCCESS;
    }

    private function resolveLanding(string $arg): ?Landing
    {
        if (ctype_digit($arg)) {
            return Landing::query()->where('human_id', (int) $arg)->first();
        }

        return Landing::query()->where('uuid', $arg)->first();
    }

    /** @return list<string> */
    private function collectFieldSlugs(): array
    {
        $raw = (array) $this->option('field');
        $out = [];
        foreach ($raw as $piece) {
            foreach (explode(',', (string) $piece) as $slug) {
                $slug = trim($slug);
                if ($slug !== '') {
                    $out[] = $slug;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
