<?php

namespace App\Services\Aio\Dto;

class Field
{
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $slug,
        public readonly ?string $name,
        public readonly ?string $dataSource,
        public readonly ?string $group,
        public readonly ?string $format,
        public readonly ?string $chColumn,
        public readonly ?string $description,
        public readonly ?string $accessType,
        public readonly bool $isArchived,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $row): self
    {
        $nested = is_array($row['field'] ?? null) ? $row['field'] : [];

        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            slug: isset($row['slug']) ? (string) $row['slug'] : (isset($nested['slug']) ? (string) $nested['slug'] : null),
            name: isset($nested['name']) ? (string) $nested['name'] : null,
            dataSource: isset($row['data_source']) ? (string) $row['data_source'] : null,
            group: isset($row['group']) ? (string) $row['group'] : null,
            format: isset($row['format']) ? (string) $row['format'] : null,
            chColumn: isset($row['ch_column']) ? (string) $row['ch_column'] : null,
            description: isset($row['description']) ? (string) $row['description'] : null,
            accessType: isset($row['access_type']) ? (string) $row['access_type'] : null,
            isArchived: (bool) ($row['is_archived'] ?? false),
            raw: $row,
        );
    }
}
