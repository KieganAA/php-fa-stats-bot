<?php

namespace App\Services\Aio\Dto;

class LandingType
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            raw: $row,
        );
    }
}
