<?php

namespace Tests\Feature\Campaign;

use App\Services\Aio\Dto\CampaignStep;
use App\Services\Aio\Dto\CampaignStructure;
use App\Services\Aio\Dto\LandingMvtInfo;
use App\Services\Aio\Dto\MvtField;
use App\Services\Campaign\CampaignAnalyzer;
use PHPUnit\Framework\TestCase;

final class CampaignAnalyzerTest extends TestCase
{
    private CampaignAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CampaignAnalyzer;
    }

    public function test_step_with_two_landings_is_a_split(): void
    {
        $structure = new CampaignStructure(
            campaignUuid: 'cmp-1',
            humanId: 100,
            name: 'cmp',
            countries: ['CA'],
            steps: [
                new CampaignStep('step-1', 1, ['lp-a', 'lp-b']),
            ],
        );

        $analysis = $this->analyzer->analyze($structure, []);

        $this->assertCount(1, $analysis->splits);
        $this->assertSame(['lp-a', 'lp-b'], $analysis->splits[0]->landingUuids);
        $this->assertSame('step-1', $analysis->splits[0]->stepUuid);
        $this->assertSame(1, $analysis->splits[0]->stepPosition);
        $this->assertSame([], $analysis->mvts);
    }

    public function test_step_with_one_landing_is_not_a_split(): void
    {
        $structure = new CampaignStructure(
            campaignUuid: 'cmp-1',
            humanId: 100,
            name: 'cmp',
            countries: [],
            steps: [
                new CampaignStep('step-1', 1, ['lp-solo']),
            ],
        );

        $analysis = $this->analyzer->analyze($structure, []);

        $this->assertSame([], $analysis->splits);
    }

    public function test_mvt_landing_yields_mvt_descriptor(): void
    {
        $structure = new CampaignStructure(
            campaignUuid: 'cmp-1',
            humanId: 100,
            name: 'cmp',
            countries: [],
            steps: [
                new CampaignStep('step-1', 1, ['lp-mvt']),
            ],
        );
        $mvt = [
            'lp-mvt' => new LandingMvtInfo('lp-mvt', [
                new MvtField('lp_header', 'f-1', ['variant-a', 'variant-b']),
            ]),
        ];

        $analysis = $this->analyzer->analyze($structure, $mvt);

        $this->assertCount(1, $analysis->mvts);
        $this->assertSame('lp-mvt', $analysis->mvts[0]->landingUuid);
        $this->assertSame('step-1', $analysis->mvts[0]->stepUuid);
    }

    public function test_field_with_only_one_variant_does_not_count_as_mvt(): void
    {
        $structure = new CampaignStructure(
            campaignUuid: 'cmp-1',
            humanId: 100,
            name: 'cmp',
            countries: [],
            steps: [
                new CampaignStep('step-1', 1, ['lp-1']),
            ],
        );
        // Single-variant field = default value, not a multivariate test.
        $mvt = [
            'lp-1' => new LandingMvtInfo('lp-1', [
                new MvtField('lp_header', 'f-1', ['only-variant']),
            ]),
        ];

        $analysis = $this->analyzer->analyze($structure, $mvt);

        $this->assertSame([], $analysis->mvts);
    }

    public function test_split_and_mvt_coexist_independently(): void
    {
        // Step 1 splits between two landings; one of those landings is also
        // MVT. Expect one split descriptor + one mvt descriptor.
        $structure = new CampaignStructure(
            campaignUuid: 'cmp-1',
            humanId: 100,
            name: 'cmp',
            countries: [],
            steps: [
                new CampaignStep('step-1', 1, ['lp-a', 'lp-b']),
            ],
        );
        $mvt = [
            'lp-a' => new LandingMvtInfo('lp-a', [
                new MvtField('lp_header', 'f-1', ['v1', 'v2']),
            ]),
            'lp-b' => new LandingMvtInfo('lp-b', []),
        ];

        $analysis = $this->analyzer->analyze($structure, $mvt);

        $this->assertCount(1, $analysis->splits);
        $this->assertCount(1, $analysis->mvts);
        $this->assertSame('lp-a', $analysis->mvts[0]->landingUuid);
    }

    public function test_landing_in_two_steps_yields_single_mvt_with_first_step_labeling(): void
    {
        // Edge case: same landing referenced from two different steps. We
        // dedupe MVT descriptors and keep the first step's labelling.
        $structure = new CampaignStructure(
            campaignUuid: 'cmp-1',
            humanId: 100,
            name: 'cmp',
            countries: [],
            steps: [
                new CampaignStep('step-1', 1, ['lp-mvt']),
                new CampaignStep('step-2', 2, ['lp-mvt', 'lp-other']),
            ],
        );
        $mvt = [
            'lp-mvt' => new LandingMvtInfo('lp-mvt', [
                new MvtField('lp_header', 'f-1', ['v1', 'v2']),
            ]),
        ];

        $analysis = $this->analyzer->analyze($structure, $mvt);

        $this->assertCount(1, $analysis->mvts);
        $this->assertSame(1, $analysis->mvts[0]->stepPosition);
    }
}
