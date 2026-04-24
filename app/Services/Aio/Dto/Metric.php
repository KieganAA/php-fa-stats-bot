<?php

namespace App\Services\Aio\Dto;

/**
 * Settings\Metrics row (from POST /api/v1/tables/data).
 *
 * Shape: top-level `uuid` + `_grid.name` (display label) + nested `metric`
 * carrying `name`, `format`, `type`, `description`. No slug — pivot placeholders
 * key by `metric_<uuid>`, so uuid + name is all we need for resolution.
 */
class Metric
{
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $name,
        public readonly ?string $format,
        public readonly ?string $type,
        public readonly ?string $description,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $row): self
    {
        $metric = is_array($row['metric'] ?? null) ? $row['metric'] : [];
        $grid = is_array($row['_grid'] ?? null) ? $row['_grid'] : [];

        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            name: self::firstString([$grid['name'] ?? null, $metric['name'] ?? null, $row['name'] ?? null]),
            format: isset($metric['format']) ? (string) $metric['format'] : null,
            type: isset($metric['type']) ? (string) $metric['type'] : null,
            description: self::firstString([$grid['description'] ?? null, $metric['description'] ?? null]),
            raw: $row,
        );
    }

    private static function firstString(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if ($c !== null && $c !== '') {
                return (string) $c;
            }
        }

        return null;
    }
}
