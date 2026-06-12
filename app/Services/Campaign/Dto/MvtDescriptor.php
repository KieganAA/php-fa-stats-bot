<?php

namespace App\Services\Campaign\Dto;

/**
 * "Subscribe to MVT-variant breakdown for landing L inside campaign C."
 * Becomes an mvt-mode UserCompareGroup with a single member (the landing) —
 * the pivot report side already knows how to slice by variant uuid.
 *
 * `stepUuid` / `stepPosition` are carried for labelling ("MVT on step 2")
 * even though the MVT report itself doesn't care about the step.
 */
final class MvtDescriptor
{
    public function __construct(
        public readonly string $landingUuid,
        public readonly string $stepUuid,
        public readonly int $stepPosition,
    ) {}
}
