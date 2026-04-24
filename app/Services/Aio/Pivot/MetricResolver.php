<?php

namespace App\Services\Aio\Pivot;

use App\Models\Aio\Metric as MetricModel;

/**
 * Maps AIO metric UUIDs (keys in pivot leaf rows) to human-readable names
 * from the local `aio_metrics` table.
 *
 * Two-way lookup:
 *   - nameFor('16ab920b-...') → "LP1 Clicks"
 *   - uuidFor('LP1 Clicks')   → "16ab920b-..."  (case-insensitive exact match)
 *
 * Results are kept in process memory — metrics change rarely.
 */
class MetricResolver
{
    /** @var array<string, string>|null  uuid → name */
    private ?array $byUuid = null;

    /** @var array<string, string>|null  lowercased-name → uuid */
    private ?array $byName = null;

    public function nameFor(string $uuid): ?string
    {
        $this->hydrate();

        return $this->byUuid[$uuid] ?? null;
    }

    public function uuidFor(string $name): ?string
    {
        $this->hydrate();

        return $this->byName[mb_strtolower($name)] ?? null;
    }

    /**
     * @param  array<string, mixed>  $metrics  keyed by raw UUID
     * @return array<string, mixed>  keyed by human name (unknown uuids keep their raw key)
     */
    public function resolveMetrics(array $metrics): array
    {
        $this->hydrate();

        $out = [];
        foreach ($metrics as $uuid => $value) {
            $name = $this->byUuid[(string) $uuid] ?? null;
            $out[$name ?? (string) $uuid] = $value;
        }

        return $out;
    }

    public function refresh(): void
    {
        $this->byUuid = null;
        $this->byName = null;
    }

    private function hydrate(): void
    {
        if ($this->byUuid !== null) {
            return;
        }

        $this->byUuid = [];
        $this->byName = [];

        MetricModel::query()
            ->select(['uuid', 'name'])
            ->whereNotNull('name')
            ->orderBy('id')
            ->each(function (MetricModel $m): void {
                $this->byUuid[$m->uuid] = $m->name;
                $this->byName[mb_strtolower($m->name)] ??= $m->uuid;
            });
    }
}
