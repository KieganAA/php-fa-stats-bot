<?php

namespace App\Services\Aio\Pivot;

use RuntimeException;

/**
 * Resolves the configured target-metric slugs (config('aio.target_metrics'))
 * to AIO metric UUIDs via MetricResolver. Used by the slicer to project the
 * full pivot response (~80 metrics) down to the handful we actually report on.
 *
 *   slug => ['name' => '<aio name>', 'uuid' => '<aio uuid>']
 */
class TargetMetricSet
{
    /** @var array<string, array{name: string, uuid: string}>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly MetricResolver $resolver,
        /** @var array<string, string> slug => aio metric name */
        private readonly array $slugToName,
    ) {}

    /**
     * @return array<string, array{name: string, uuid: string}>  slug => {name, uuid}
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $out = [];
        $missing = [];
        foreach ($this->slugToName as $slug => $name) {
            $uuid = $this->resolver->uuidFor($name);
            if ($uuid === null) {
                $missing[$slug] = $name;
                continue;
            }
            $out[$slug] = ['name' => $name, 'uuid' => $uuid];
        }

        if ($missing !== []) {
            $list = implode(', ', array_map(fn ($s, $n) => "{$s}=>'{$n}'", array_keys($missing), $missing));
            throw new RuntimeException("Target metrics not found in aio_metrics: {$list}");
        }

        return $this->resolved = $out;
    }

    /**
     * Project a raw pivot metrics map (uuid => value) onto the target slugs.
     * Missing target metrics resolve to null.
     *
     * @param  array<string, mixed>  $metrics  uuid-keyed
     * @return array<string, int|float|null>  slug-keyed
     */
    public function project(array $metrics): array
    {
        $out = [];
        foreach ($this->all() as $slug => $info) {
            $out[$slug] = $metrics[$info['uuid']] ?? null;
        }

        return $out;
    }
}
