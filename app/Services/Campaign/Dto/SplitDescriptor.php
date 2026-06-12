<?php

namespace App\Services\Campaign\Dto;

/**
 * "Subscribe to traffic split between landings X and Y at step S of campaign
 * C." The downstream `CampaignSubscriptionService` turns each of these into a
 * compare-mode UserCompareGroup, with the landings as members.
 */
final class SplitDescriptor
{
    /** @param  list<string>  $landingUuids */
    public function __construct(
        public readonly string $stepUuid,
        public readonly int $stepPosition,
        public readonly array $landingUuids,
    ) {}
}
