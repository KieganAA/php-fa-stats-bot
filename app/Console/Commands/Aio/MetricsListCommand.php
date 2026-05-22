<?php

namespace App\Console\Commands\Aio;

use App\Models\Aio\Metric;
use App\Models\UserCompareGroup;
use App\Services\Stats\MetricDisplay;
use Illuminate\Console\Command;

/**
 * Print every AIO metric we have synced locally, with the display kind
 * MetricDisplay infers for it and a count of users referencing it in
 * their custom prefs. Use for poking around when the heuristic isn't
 * doing what we want — find the offending name, add an override.
 *
 * `--search foo` narrows by case-insensitive name substring.
 */
class MetricsListCommand extends Command
{
    protected $signature = 'aio:metrics:list
        {--search= : Case-insensitive substring filter on name}
        {--with-usage : Include "users picked" counter (extra query)}';

    protected $description = 'List AIO metrics with their MetricDisplay kind.';

    public function handle(): int
    {
        $query = Metric::query()->orderBy('name');
        if ($s = (string) $this->option('search')) {
            $query->where('name', 'ilike', '%'.$s.'%');
        }
        $metrics = $query->get(['uuid', 'name']);

        if ($metrics->isEmpty()) {
            $this->info('No metrics matched.');

            return self::SUCCESS;
        }

        $usage = [];
        if ($this->option('with-usage')) {
            // Count how many users have each metric in settings.metrics.
            // Cheap-ish because settings is jsonb on a small users table.
            foreach (UserCompareGroup::query()->getConnection()->select(
                "SELECT jsonb_array_elements_text(settings->'metrics') AS name, COUNT(*) AS cnt
                 FROM users
                 WHERE settings ? 'metrics'
                 GROUP BY 1",
            ) as $row) {
                $usage[$row->name] = (int) $row->cnt;
            }
        }

        $defaults = array_flip(MetricDisplay::defaultNames());

        $rows = [];
        foreach ($metrics as $m) {
            $rows[] = [
                'uuid' => substr($m->uuid, 0, 8).'…',
                'name' => $m->name,
                'label' => MetricDisplay::label($m->name),
                'kind' => MetricDisplay::kind($m->name),
                'default' => isset($defaults[$m->name]) ? '✓' : '',
                'users' => $this->option('with-usage') ? ($usage[$m->name] ?? 0) : '—',
            ];
        }

        $this->table(['uuid', 'name', 'label', 'kind', 'default', 'users'], $rows);
        $this->info('Total: '.count($rows));

        return self::SUCCESS;
    }
}
