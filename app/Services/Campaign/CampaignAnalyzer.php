<?php

namespace App\Services\Campaign;

use App\Services\Aio\Dto\CampaignStructure;
use App\Services\Aio\Dto\LandingMvtInfo;
use App\Services\Campaign\Dto\CampaignAnalysis;
use App\Services\Campaign\Dto\MvtDescriptor;
use App\Services\Campaign\Dto\SplitDescriptor;

/**
 * Pure: takes a CampaignStructure and per-landing MvtInfo and decides which
 * compare/mvt subscriptions a user should have for that campaign.
 *
 *  - Split  = step where 2+ active Landing items live → one compare subscription.
 *  - MVT    = landing whose mvt_settings has any field with 2+ variants → one
 *             mvt subscription, attached to the step that introduced it.
 *
 * If the same landing happens to appear in multiple steps (rare; some campaign
 * graphs route back), we keep the FIRST step's labelling for the MVT
 * descriptor — pick is deterministic but arbitrary.
 *
 * MVT info for landings not provided in $mvtByUuid is treated as "no MVT" —
 * the caller is expected to fetch info for every landing referenced by the
 * structure; missing entries just mean "we couldn't confirm, assume no".
 */
final class CampaignAnalyzer
{
    /** @param  array<string, LandingMvtInfo>  $mvtByLandingUuid */
    public function analyze(CampaignStructure $structure, array $mvtByLandingUuid): CampaignAnalysis
    {
        $splits = [];
        $mvts = [];
        $mvtSeen = [];

        foreach ($structure->steps as $step) {
            if ($step->isSplit()) {
                $splits[] = new SplitDescriptor(
                    stepUuid: $step->stepUuid,
                    stepPosition: $step->position,
                    landingUuids: $step->landingUuids,
                );
            }

            foreach ($step->landingUuids as $landingUuid) {
                if (isset($mvtSeen[$landingUuid])) {
                    continue;
                }
                $info = $mvtByLandingUuid[$landingUuid] ?? null;
                if ($info instanceof LandingMvtInfo && $info->hasMvt()) {
                    $mvts[] = new MvtDescriptor(
                        landingUuid: $landingUuid,
                        stepUuid: $step->stepUuid,
                        stepPosition: $step->position,
                    );
                    $mvtSeen[$landingUuid] = true;
                }
            }
        }

        return new CampaignAnalysis(
            structure: $structure,
            splits: $splits,
            mvts: $mvts,
        );
    }
}
