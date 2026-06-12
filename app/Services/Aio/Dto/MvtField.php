<?php

namespace App\Services\Aio\Dto;

/**
 * One variation slot inside a landing's `mvt_settings`. `key` is the AIO
 * slot identifier (`lp_header`, `lp_content_var_*`, …), `variants` are the
 * candidate payload contents AIO will pick between for that slot.
 */
final class MvtField
{
    /** @param  list<string>  $variants  raw `payload.content` values, one per item */
    public function __construct(
        public readonly string $key,
        public readonly string $fieldUuid,
        public readonly array $variants,
    ) {}

    public function variantCount(): int
    {
        return count($this->variants);
    }
}
