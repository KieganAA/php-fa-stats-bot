<?php

namespace App\Services\Aio\Dto;

use App\Services\Aio\Support\PivotWalker;

class PivotResponse
{
    /**
     * @param  array<int, array{dimensions: array<string, mixed>, metrics: array<string, mixed>}>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $response): self
    {
        return new self(
            rows: PivotWalker::flatten($response),
            raw: $response,
        );
    }
}
