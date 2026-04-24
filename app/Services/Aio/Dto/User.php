<?php

namespace App\Services\Aio\Dto;

class User
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly ?string $email,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $row): self
    {
        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            name: (string) ($row['name'] ?? $row['nickname'] ?? $row['username'] ?? ''),
            email: isset($row['email']) ? (string) $row['email'] : null,
            raw: $row,
        );
    }
}
