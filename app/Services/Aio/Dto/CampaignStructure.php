<?php

namespace App\Services\Aio\Dto;

/**
 * Decoded shape of AIO's `Campaign\Create` action response, narrowed to what
 * we need: who the campaign is, plus the ordered list of funnel steps and the
 * landings each step routes to.
 *
 * We deliberately drop everything else AIO returns (traffic filters, allowance
 * rules, forms, cost strategy, …) — that's all configuration we don't have a
 * use for in the subscriber logic.
 */
final class CampaignStructure
{
    /** @param  list<CampaignStep>  $steps */
    public function __construct(
        public readonly string $campaignUuid,
        public readonly ?int $humanId,
        public readonly string $name,
        /** @var list<string> */
        public readonly array $countries,
        public readonly array $steps,
    ) {}

    /** @return list<CampaignStep> */
    public function splits(): array
    {
        return array_values(array_filter($this->steps, fn (CampaignStep $s) => $s->isSplit()));
    }

    /** @return list<string> distinct landing uuids across all steps */
    public function allLandingUuids(): array
    {
        $out = [];
        foreach ($this->steps as $step) {
            foreach ($step->landingUuids as $uuid) {
                $out[$uuid] = true;
            }
        }

        return array_keys($out);
    }
}
