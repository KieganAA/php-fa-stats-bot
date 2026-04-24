<?php

namespace App\Services\Aio\Dto;

class Landing
{
    /**
     * @param  array<int, string>  $mvtKeys  Keys from mvt_settings (lp_*) when landing is MVT parent.
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly ?string $typeUuid,
        public readonly ?string $userUuid,
        public readonly array $mvtKeys,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $row): self
    {
        $mvtSettings = $row['mvt_settings'] ?? null;
        $mvtKeys = is_array($mvtSettings) ? array_keys($mvtSettings) : [];

        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            typeUuid: isset($row['landing_type_uuid']) ? (string) $row['landing_type_uuid'] : null,
            userUuid: isset($row['user_uuid']) ? (string) $row['user_uuid'] : null,
            mvtKeys: $mvtKeys,
            raw: $row,
        );
    }
}
