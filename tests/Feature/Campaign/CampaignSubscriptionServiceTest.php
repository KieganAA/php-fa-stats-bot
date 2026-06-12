<?php

namespace Tests\Feature\Campaign;

use App\Models\CampaignSubscription;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Campaign\CampaignSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class CampaignSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPAIGN_UUID = 'cmp-uuid-1';

    /** @var array<string, list<string>> step_uuid => landing_uuids — mutated between phases */
    private array $steps = [];

    /** @var array<string, true> landing uuids that should report MVT */
    private array $mvtSet = [];

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

        // Register the AIO fake ONCE. The closure reads mutable instance state
        // so a test can change the campaign structure between resyncs without
        // re-faking (Http::fake() appends rather than replaces, so the first
        // stub would otherwise keep matching).
        Http::fake([
            'app.aio.test/api/v1/actions/data*' => function ($request) {
                $body = json_decode($request->body(), true) ?: [];
                $action = $body['action'] ?? '';

                if ($action === 'Campaign\\Create') {
                    return Http::response($this->campaignEnvelope($this->steps));
                }

                $uuid = $body['uuids'][0] ?? '';

                return Http::response($this->landerEnvelope(isset($this->mvtSet[$uuid])));
            },
        ]);
    }

    public function test_create_refuses_campaign_with_no_splits_or_mvt(): void
    {
        // One landing on the step, no MVT → nothing to track.
        $this->fakeAio(steps: ['step-1' => ['lp-a']], mvtLandings: []);
        $user = $this->makeUser();

        $this->expectException(\App\Services\Campaign\Exceptions\EmptyCampaignException::class);
        try {
            $this->service()->create($user, self::CAMPAIGN_UUID);
        } finally {
            // No dead parent row left behind.
            $this->assertSame(0, CampaignSubscription::query()->count());
        }
    }

    public function test_create_builds_split_and_mvt_children(): void
    {
        $this->fakeAio(
            steps: [
                'step-1' => ['lp-a', 'lp-b'],   // split
                'step-2' => ['lp-mvt'],          // single, but MVT landing
            ],
            mvtLandings: ['lp-mvt'],
        );

        $user = $this->makeUser();
        $result = $this->service()->create($user, self::CAMPAIGN_UUID);

        $this->assertCount(2, $result->created);
        $this->assertSame([], $result->orphaned);

        $sub = CampaignSubscription::query()->where('campaign_uuid', self::CAMPAIGN_UUID)->firstOrFail();
        $this->assertSame($user->id, $sub->user_id);
        $this->assertCount(2, $sub->children);

        $split = $sub->children->firstWhere('mode', UserCompareGroup::MODE_COMPARE);
        $this->assertNotNull($split);
        $this->assertSame('split:step-1', $split->child_key);
        $this->assertCount(2, $split->members);

        $mvt = $sub->children->firstWhere('mode', UserCompareGroup::MODE_MVT);
        $this->assertNotNull($mvt);
        $this->assertSame('mvt:lp-mvt', $mvt->child_key);
        $this->assertCount(1, $mvt->members);
    }

    public function test_resync_is_idempotent_when_structure_unchanged(): void
    {
        $this->fakeAio(steps: ['step-1' => ['lp-a', 'lp-b']], mvtLandings: []);

        $user = $this->makeUser();
        $service = $this->service();
        $service->create($user, self::CAMPAIGN_UUID);

        $sub = CampaignSubscription::query()->where('campaign_uuid', self::CAMPAIGN_UUID)->firstOrFail();
        $result = $service->resync($sub);

        $this->assertFalse($result->changed());
    }

    public function test_resync_marks_vanished_split_as_orphaned_not_deleted(): void
    {
        $service = $this->service();
        $user = $this->makeUser();

        // First: a split exists.
        $this->fakeAio(steps: ['step-1' => ['lp-a', 'lp-b']], mvtLandings: []);
        $service->create($user, self::CAMPAIGN_UUID);
        $sub = CampaignSubscription::query()->where('campaign_uuid', self::CAMPAIGN_UUID)->firstOrFail();

        // Then: the split collapses to a single landing → no longer a split.
        $this->fakeAio(steps: ['step-1' => ['lp-a']], mvtLandings: []);
        $result = $service->resync($sub);

        $this->assertCount(1, $result->orphaned);
        $orphan = $result->orphaned[0];
        $this->assertSame('split:step-1', $orphan->child_key);

        // Still in DB, just flagged.
        $this->assertDatabaseHas('user_compare_groups', [
            'id' => $orphan->id,
            'child_key' => 'split:step-1',
        ]);
        $this->assertNotNull($orphan->fresh()->orphaned_at);
        $this->assertFalse($orphan->fresh()->isDueForPush(now()));
    }

    public function test_orphan_is_reported_only_once(): void
    {
        $service = $this->service();
        $user = $this->makeUser();

        $this->fakeAio(steps: ['step-1' => ['lp-a', 'lp-b']], mvtLandings: []);
        $service->create($user, self::CAMPAIGN_UUID);
        $sub = CampaignSubscription::query()->where('campaign_uuid', self::CAMPAIGN_UUID)->firstOrFail();

        $this->fakeAio(steps: ['step-1' => ['lp-a']], mvtLandings: []);
        $first = $service->resync($sub);
        $this->assertCount(1, $first->orphaned);

        // Second resync with same (collapsed) structure shouldn't re-report.
        $second = $service->resync($sub);
        $this->assertCount(0, $second->orphaned);
        $this->assertFalse($second->changed());
    }

    public function test_reappearing_split_is_reactivated(): void
    {
        $service = $this->service();
        $user = $this->makeUser();

        $this->fakeAio(steps: ['step-1' => ['lp-a', 'lp-b']], mvtLandings: []);
        $service->create($user, self::CAMPAIGN_UUID);
        $sub = CampaignSubscription::query()->where('campaign_uuid', self::CAMPAIGN_UUID)->firstOrFail();

        // Collapse → orphan.
        $this->fakeAio(steps: ['step-1' => ['lp-a']], mvtLandings: []);
        $service->resync($sub);

        // Re-expand → reactivate.
        $this->fakeAio(steps: ['step-1' => ['lp-a', 'lp-b']], mvtLandings: []);
        $result = $service->resync($sub);

        $this->assertCount(1, $result->reactivated);
        $this->assertNull($result->reactivated[0]->fresh()->orphaned_at);
    }

    // ===== helpers =====

    private function service(): CampaignSubscriptionService
    {
        return $this->app->make(CampaignSubscriptionService::class);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'telegram_user_id' => 1234,
            'telegram_username' => 'tester',
            'timezone' => 'Europe/Moscow',
        ]);
    }

    /**
     * Point the (already-registered) AIO fake at a new structure.
     *
     * @param  array<string, list<string>>  $steps         step_uuid => landing_uuids
     * @param  list<string>  $mvtLandings  landing uuids that should report MVT
     */
    private function fakeAio(array $steps, array $mvtLandings): void
    {
        $this->steps = $steps;
        $this->mvtSet = array_flip($mvtLandings);
    }

    /** @param  array<string, list<string>>  $steps */
    private function campaignEnvelope(array $steps): array
    {
        $settings = [];
        foreach ($steps as $stepUuid => $landingUuids) {
            $items = [];
            foreach ($landingUuids as $lu) {
                $items[] = [
                    'payload' => [
                        'type' => 'Landing',
                        'uuid' => 'item-'.$lu,
                        'content' => $lu,
                        'isActive' => true,
                    ],
                ];
            }
            $settings[$stepUuid] = ['payload' => ['items' => $items]];
        }

        return [
            'fields' => [
                ['name' => 'name', 'value' => 'Test Campaign'],
                ['name' => 'human_id', 'value' => 116400],
                ['name' => 'countries', 'value' => ['CA']],
                ['name' => 'settings', 'value' => json_encode($settings)],
            ],
            'data' => [],
            'primary' => null,
            'logs' => [],
        ];
    }

    private function landerEnvelope(bool $isMvt): array
    {
        $mvtSettings = $isMvt
            ? json_encode([
                [
                    'key' => 'lp_header',
                    'uuid' => 'field-1',
                    'settings' => ['items' => [
                        ['payload' => ['content' => 'A']],
                        ['payload' => ['content' => 'B']],
                    ]],
                ],
            ])
            : '[]';

        return [
            'fields' => [
                ['name' => 'name', 'value' => 'lander'],
                ['name' => 'mvt_settings', 'value' => $mvtSettings],
            ],
            'data' => [],
            'primary' => null,
            'logs' => [],
        ];
    }
}
