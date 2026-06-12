<?php

namespace App\Services\Aio\Dto;

/**
 * One funnel step within an AIO campaign. Identified by its own AIO uuid
 * (the key in the `settings` dict). Order is `position` — 1-indexed by where
 * the step appears in AIO's settings dict; we don't have explicit LP numbers,
 * so this is the best we can do for stable labelling ("Step 1 split").
 *
 * `landingUuids` is the set of ACTIVE Landing-type items in this step (we
 * filter out inactive ones and non-Landing items — Forms, Allowance Rules,
 * Text fields and friends). >=2 means it's a traffic split.
 */
final class CampaignStep
{
    /** @param  list<string>  $landingUuids */
    public function __construct(
        public readonly string $stepUuid,
        public readonly int $position,
        public readonly array $landingUuids,
    ) {}

    public function isSplit(): bool
    {
        return count($this->landingUuids) >= 2;
    }
}
