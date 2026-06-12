<?php

namespace Tests\Feature\Campaign;

use App\Services\Campaign\CampaignStructureFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class CampaignStructureFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('aio.base_url', 'https://app.aio.test');
        config()->set('aio.token', 'test-token');
        config()->set('aio.tenant_id', 'tenant-1');
        config()->set('aio.cache.enabled', false);
        config()->set('aio.limiter.max_wait_ms', 500);
        config()->set('aio.limiter.retry_interval_ms', 20);
        config()->set('aio.rate_limits.per_minute', 1000);

        Redis::connection()->flushdb();
    }

    public function test_parses_steps_and_extracts_landing_uuids_from_payload_content(): void
    {
        // Two real steps + one traffic_filter blob that shouldn't show up as
        // a step. Step 1 has 2 landings (= split), Step 2 has 1.
        Http::fake([
            'app.aio.test/api/v1/actions/data*' => Http::response($this->envelope([
                ['name' => 'name', 'value' => 'Test Campaign'],
                ['name' => 'human_id', 'value' => 116400],
                ['name' => 'countries', 'value' => ['CA', 'US']],
                ['name' => 'settings', 'value' => json_encode([
                    'step-uuid-1' => [
                        'payload' => [
                            'items' => [
                                $this->landingItem('lp-uuid-a'),
                                $this->landingItem('lp-uuid-b'),
                            ],
                        ],
                    ],
                    'traffic-filter-uuid' => [
                        // Not a step — different shape.
                        'settings' => '{}',
                        'allowanceRules' => ['items' => []],
                    ],
                    'step-uuid-2' => [
                        'payload' => [
                            'items' => [
                                $this->landingItem('lp-uuid-c'),
                            ],
                        ],
                    ],
                ])],
            ])),
        ]);

        $fetcher = $this->app->make(CampaignStructureFetcher::class);
        $struct = $fetcher->fetch('cmp-1');

        $this->assertSame('cmp-1', $struct->campaignUuid);
        $this->assertSame(116400, $struct->humanId);
        $this->assertSame('Test Campaign', $struct->name);
        $this->assertSame(['CA', 'US'], $struct->countries);
        $this->assertCount(2, $struct->steps);
        $this->assertSame('step-uuid-1', $struct->steps[0]->stepUuid);
        $this->assertSame(1, $struct->steps[0]->position);
        $this->assertSame(['lp-uuid-a', 'lp-uuid-b'], $struct->steps[0]->landingUuids);
        $this->assertTrue($struct->steps[0]->isSplit());
        $this->assertSame('step-uuid-2', $struct->steps[1]->stepUuid);
        $this->assertSame(2, $struct->steps[1]->position);
        $this->assertFalse($struct->steps[1]->isSplit());
    }

    public function test_skips_inactive_items_and_non_landing_types(): void
    {
        // Step has 3 items: one inactive Landing, one Form, one active Landing.
        // Only the active Landing should survive — that's just 1 landing,
        // so not a split.
        Http::fake([
            'app.aio.test/api/v1/actions/data*' => Http::response($this->envelope([
                ['name' => 'name', 'value' => 'C'],
                ['name' => 'human_id', 'value' => 1],
                ['name' => 'countries', 'value' => []],
                ['name' => 'settings', 'value' => json_encode([
                    'step-1' => [
                        'payload' => [
                            'items' => [
                                $this->landingItem('lp-inactive', isActive: false),
                                $this->formItem('form-x'),
                                $this->landingItem('lp-active'),
                            ],
                        ],
                    ],
                ])],
            ])),
        ]);

        $fetcher = $this->app->make(CampaignStructureFetcher::class);
        $struct = $fetcher->fetch('cmp-1');

        $this->assertCount(1, $struct->steps);
        $this->assertSame(['lp-active'], $struct->steps[0]->landingUuids);
        $this->assertFalse($struct->steps[0]->isSplit());
    }

    public function test_steps_without_landings_are_dropped(): void
    {
        // Step that holds only a Form — not interesting for our subscriber.
        Http::fake([
            'app.aio.test/api/v1/actions/data*' => Http::response($this->envelope([
                ['name' => 'name', 'value' => 'C'],
                ['name' => 'human_id', 'value' => 1],
                ['name' => 'countries', 'value' => []],
                ['name' => 'settings', 'value' => json_encode([
                    'step-form-only' => [
                        'payload' => [
                            'items' => [$this->formItem('form-x')],
                        ],
                    ],
                ])],
            ])),
        ]);

        $fetcher = $this->app->make(CampaignStructureFetcher::class);
        $struct = $fetcher->fetch('cmp-1');

        $this->assertSame([], $struct->steps);
        $this->assertSame([], $struct->splits());
    }

    private function envelope(array $fields): array
    {
        return [
            'fields' => $fields,
            'data' => [],
            'primary' => null,
            'logs' => [],
        ];
    }

    private function landingItem(string $landingUuid, bool $isActive = true): array
    {
        return [
            'payload' => [
                'type' => 'Landing',
                'uuid' => 'item-'.$landingUuid,
                'group' => 'Rainbow',
                'weight' => 100,
                'content' => $landingUuid,
                'isActive' => $isActive,
                'selected' => 0,
                'isFillFirst' => false,
            ],
            'conditions' => [],
            'scope_type' => 'Empty',
            'scope_uuid' => '',
            'lp_split_mode' => 'Landing',
        ];
    }

    private function formItem(string $formUuid): array
    {
        return [
            'payload' => [
                'type' => 'Form',
                'uuid' => 'item-'.$formUuid,
                'content' => $formUuid,
                'isActive' => true,
            ],
        ];
    }
}
