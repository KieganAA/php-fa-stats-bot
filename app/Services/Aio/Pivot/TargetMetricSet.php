<?php

namespace App\Services\Aio\Pivot;

use RuntimeException;

/**
 * Projects a uuid-keyed pivot response onto a list of AIO metric NAMES.
 *
 * The constructor list is the default (shipped via config('aio.default_metrics')),
 * but every public method also accepts an override list — that's how per-user
 * metric preferences plug in: the controller pulls the user's chosen names and
 * passes them through.
 *
 * project($uuidValues, $names) returns a `[name => value|null]` map preserving
 * the order of the supplied names. Missing values are null.
 */
class TargetMetricSet
{
    public function __construct(
        private readonly MetricResolver $resolver,
        /** @var list<string> AIO metric names (case-insensitive matched against aio_metrics) */
        private readonly array $defaultNames,
    ) {}

    /** @return list<string> */
    public function defaults(): array
    {
        return $this->defaultNames;
    }

    /**
     * Project pivot metrics onto a list of AIO names.
     *
     * @param  array<string, mixed>  $uuidValues  uuid-keyed pivot row metrics
     * @param  list<string>|null     $names       AIO names to extract (default = ctor list)
     * @return array<string, int|float|null>      AIO-name-keyed values
     */
    public function project(array $uuidValues, ?array $names = null): array
    {
        $names ??= $this->defaultNames;

        $out = [];
        foreach ($names as $name) {
            $uuid = $this->resolver->uuidFor($name);
            if ($uuid === null) {
                $out[$name] = null;

                continue;
            }
            $out[$name] = $uuidValues[$uuid] ?? null;
        }

        return $out;
    }

    /**
     * Resolve names → uuids for callers that need the raw AIO uuids.
     * Throws if any name is unknown — fail loud rather than silently drop.
     *
     * @param  list<string>|null  $names
     * @return array<string, string>  name => uuid
     */
    public function uuidsFor(?array $names = null): array
    {
        $names ??= $this->defaultNames;

        $out = [];
        $missing = [];
        foreach ($names as $name) {
            $uuid = $this->resolver->uuidFor($name);
            if ($uuid === null) {
                $missing[] = $name;

                continue;
            }
            $out[$name] = $uuid;
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Target metrics not found in aio_metrics: '.implode(', ', $missing)
            );
        }

        return $out;
    }
}
