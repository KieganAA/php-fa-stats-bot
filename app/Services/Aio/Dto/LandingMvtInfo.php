<?php

namespace App\Services\Aio\Dto;

/**
 * Decoded shape of a landing's `mvt_settings` field from the AIO
 * `Lander\Create` action response. Each `MvtField` is a variation slot
 * (e.g. `lp_header`, `lp_content_var_*`) with its variant payloads.
 *
 * A landing is considered "MVT" when at least one field has 2+ variants —
 * a single-variant field is just a default value, not a multivariate test.
 */
final class LandingMvtInfo
{
    /** @param  list<MvtField>  $fields */
    public function __construct(
        public readonly string $landingUuid,
        public readonly array $fields,
    ) {}

    public function hasMvt(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->variantCount() >= 2) {
                return true;
            }
        }

        return false;
    }
}
