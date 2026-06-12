<?php

namespace App\Services\Campaign\Dto;

use App\Services\Aio\Dto\CampaignStructure;

/**
 * Output of CampaignAnalyzer. Carries:
 *   - the original CampaignStructure (so callers can read name / human_id /
 *     countries without re-fetching),
 *   - the splits we'd like to subscribe to,
 *   - the per-landing MVT subscriptions we'd like.
 *
 * Splits and MVTs are independent — a campaign with one split that points to
 * a single landing AND that landing being MVT will produce one of each.
 */
final class CampaignAnalysis
{
    /**
     * @param  list<SplitDescriptor>  $splits
     * @param  list<MvtDescriptor>  $mvts
     */
    public function __construct(
        public readonly CampaignStructure $structure,
        public readonly array $splits,
        public readonly array $mvts,
    ) {}

    public function isEmpty(): bool
    {
        return $this->splits === [] && $this->mvts === [];
    }
}
