<?php

namespace App\Services\Aio\Dto;

class User
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly bool $isActive,
        public readonly ?int $aioCreatedAt,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $row): self
    {
        $createdAt = $row['created_at']['timestamp'] ?? null;

        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            isActive: (bool) ($row['is_active'] ?? true),
            aioCreatedAt: $createdAt !== null ? (int) $createdAt : null,
            raw: $row,
        );
    }
}
