<?php

namespace App\Console\Commands\Mvt;

use App\Models\TrackedLanding;
use Illuminate\Console\Command;

class MvtListCommand extends Command
{
    protected $signature = 'mvt:list {--all : include paused tracked landings}';

    protected $description = 'List tracked landings with their MVT fields.';

    public function handle(): int
    {
        $query = TrackedLanding::query()->with(['landing', 'mvtFields'])->orderBy('id');
        if (! $this->option('all')) {
            $query->whereNull('paused_at');
        }

        $rows = $query->get()->map(fn (TrackedLanding $t) => [
            'id' => $t->id,
            'landing' => $t->landing?->name ?? $t->landing_uuid,
            'pos' => $t->position,
            'started' => $t->tracking_started_at?->format('Y-m-d H:i') ?? '—',
            'paused' => $t->paused_at?->format('Y-m-d H:i') ?? '—',
            'fields' => $t->mvtFields->pluck('slug')->implode(', '),
        ])->all();

        if ($rows === []) {
            $this->info($this->option('all') ? 'No tracked landings.' : 'No active tracked landings.');

            return self::SUCCESS;
        }

        $this->table(['#', 'landing', 'pos', 'started', 'paused', 'mvt fields'], $rows);

        return self::SUCCESS;
    }
}
