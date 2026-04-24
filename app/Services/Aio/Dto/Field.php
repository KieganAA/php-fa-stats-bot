<?php

namespace App\Services\Aio\Dto;

class Field
{
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $dataSource,
        public readonly ?string $group,
        public readonly ?string $field,
        public readonly ?string $format,
        public readonly ?string $slug,
        public readonly ?string $chColumn,
        public readonly ?string $description,
        public readonly ?string $accessType,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            dataSource: isset($row['data_source']) ? (string) $row['data_source'] : null,
            group: isset($row['group']) ? (string) $row['group'] : null,
            field: isset($row['field']) ? (string) $row['field'] : null,
            format: isset($row['format']) ? (string) $row['format'] : null,
            slug: isset($row['slug']) ? (string) $row['slug'] : null,
            chColumn: isset($row['ch_column']) ? (string) $row['ch_column'] : null,
            description: isset($row['description']) ? (string) $row['description'] : null,
            accessType: isset($row['access_type']) ? (string) $row['access_type'] : null,
            raw: $row,
        );
    }
}
