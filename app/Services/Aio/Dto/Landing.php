<?php

namespace App\Services\Aio\Dto;

class Landing
{
    public function __construct(
        public readonly string $uuid,
        public readonly ?int $humanId,
        public readonly string $name,
        public readonly ?string $landingTypeUuid,
        public readonly ?string $landingTypeName,
        public readonly ?string $ownerUuid,
        public readonly ?string $ownerName,
        /** @var array<int, string> */
        public readonly array $countries,
        public readonly bool $isArchived,
        public readonly ?int $aioCreatedAt,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $row): self
    {
        $type = $row['landing_type'] ?? [];
        $owner = $row['owner'] ?? [];
        $createdAt = $row['created_at']['timestamp'] ?? null;

        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            humanId: isset($row['human_id']) ? (int) $row['human_id'] : null,
            name: (string) ($row['name'] ?? ''),
            landingTypeUuid: isset($type['uuid']) ? (string) $type['uuid'] : null,
            landingTypeName: isset($type['name']) ? (string) $type['name'] : null,
            ownerUuid: isset($owner['uuid']) ? (string) $owner['uuid'] : null,
            ownerName: isset($owner['name']) ? (string) $owner['name'] : null,
            countries: array_values(array_map('strval', $row['countries'] ?? [])),
            isArchived: (bool) ($row['is_archived'] ?? false),
            aioCreatedAt: $createdAt !== null ? (int) $createdAt : null,
            raw: $row,
        );
    }
}
