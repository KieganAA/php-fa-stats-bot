<?php

namespace Tests\Feature\Campaign;

use App\Models\CampaignSubscription;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Auth\ExtensionTokenService;
use App\Services\Campaign\CampaignSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Campaign-subscription management (list / cadence / pause / resync / delete),
 * exercised through the extension routes that delegate to CampaignsController.
 */
final class CampaignManagementTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPAIGN_UUID = '967815b9-6c7a-4bf9-8bbe-005f3d188ab2';

    /** @var array<string, list<string>> */
    private array $steps = [];

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

        Http::fake([
            'app.aio.test/api/v1/actions/data*' => function ($request) {
                $body = json_decode($request->body(), true) ?: [];
                if (($body['action'] ?? '') === 'Campaign\\Create') {
                    return Http::response($this->campaignEnvelope($this->steps));
                }

                return Http::response($this->landerEnvelope());
            },
        ]);
    }

    public function test_index_lists_campaigns_with_children_summary(): void
    {
        [$user, $headers] = $this->authedUser();
        $this->makeSubscription($user, ['step-1' => ['lp-a', 'lp-b'], 'step-2' => ['lp-c']]);

        $resp = $this->getJson('/api/ext/campaigns', $headers);

        $resp->assertStatus(200)
            ->assertJsonCount(1, 'campaigns')
            ->assertJsonPath('campaigns.0.human_id', 116400)
            ->assertJsonPath('campaigns.0.splits', 1)
            ->assertJsonPath('campaigns.0.mvts', 0)
            ->assertJsonPath('campaigns.0.paused', false);
    }

    public function test_update_interval_propagates_to_children_and_pause(): void
    {
        [$user, $headers] = $this->authedUser();
        $sub = $this->makeSubscription($user, ['step-1' => ['lp-a', 'lp-b']]);

        $this->patchJson("/api/ext/campaigns/{$sub->id}", [
            'notify_interval_minutes' => 360,
            'paused' => true,
        ], $headers)
            ->assertStatus(200)
            ->assertJsonPath('campaign.notify_interval_minutes', 360)
            ->assertJsonPath('campaign.paused', true);

        // Cadence pushed down to children (that's what the push tick reads).
        $this->assertSame(
            [360],
            UserCompareGroup::query()->where('campaign_subscription_id', $sub->id)
                ->pluck('notify_interval_minutes')->unique()->values()->all(),
        );
        $this->assertNotNull($sub->fresh()->paused_at);
    }

    public function test_resync_endpoint_reports_changes(): void
    {
        [$user, $headers] = $this->authedUser();
        $sub = $this->makeSubscription($user, ['step-1' => ['lp-a', 'lp-b']]);

        // Collapse the split → the child orphans on resync.
        $this->steps = ['step-1' => ['lp-a']];
        $this->postJson("/api/ext/campaigns/{$sub->id}/resync", [], $headers)
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('changed.orphaned', 1)
            ->assertJsonPath('campaign.orphans', 1);
    }

    public function test_destroy_removes_subscription_and_children(): void
    {
        [$user, $headers] = $this->authedUser();
        $sub = $this->makeSubscription($user, ['step-1' => ['lp-a', 'lp-b']]);

        $this->deleteJson("/api/ext/campaigns/{$sub->id}", [], $headers)
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertNull(CampaignSubscription::query()->find($sub->id));
        $this->assertSame(0, UserCompareGroup::query()->where('campaign_subscription_id', $sub->id)->count());
    }

    public function test_cannot_manage_another_users_campaign(): void
    {
        [$owner] = $this->authedUser();
        $sub = $this->makeSubscription($owner, ['step-1' => ['lp-a', 'lp-b']]);

        // A second user with their own token.
        $intruder = User::factory()->telegram('2', 'intruder')->create();
        $intruderHeaders = ['Authorization' => 'Bearer '.app(ExtensionTokenService::class)->rotate($intruder)];

        $this->deleteJson("/api/ext/campaigns/{$sub->id}", [], $intruderHeaders)->assertStatus(403);
        $this->patchJson("/api/ext/campaigns/{$sub->id}", ['paused' => true], $intruderHeaders)->assertStatus(403);

        $this->assertNotNull(CampaignSubscription::query()->find($sub->id));
    }

    // ===== helpers =====

    /** @return array{0: User, 1: array<string, string>} */
    private function authedUser(): array
    {
        $user = User::factory()->telegram('1', 'owner')->create();
        $token = app(ExtensionTokenService::class)->rotate($user);

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    /** @param  array<string, list<string>>  $steps */
    private function makeSubscription(User $user, array $steps): CampaignSubscription
    {
        $this->steps = $steps;
        app(CampaignSubscriptionService::class)->create($user, self::CAMPAIGN_UUID);

        return CampaignSubscription::query()
            ->where('user_id', $user->id)
            ->where('campaign_uuid', self::CAMPAIGN_UUID)
            ->firstOrFail();
    }

    /** @param  array<string, list<string>>  $steps */
    private function campaignEnvelope(array $steps): array
    {
        $settings = [];
        foreach ($steps as $stepUuid => $landingUuids) {
            $items = [];
            foreach ($landingUuids as $lu) {
                $items[] = ['payload' => [
                    'type' => 'Landing', 'uuid' => 'item-'.$lu, 'content' => $lu, 'isActive' => true,
                ]];
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
            'data' => [], 'primary' => null, 'logs' => [],
        ];
    }

    private function landerEnvelope(): array
    {
        return [
            'fields' => [
                ['name' => 'name', 'value' => 'lander'],
                ['name' => 'mvt_settings', 'value' => '[]'],
            ],
            'data' => [], 'primary' => null, 'logs' => [],
        ];
    }
}
