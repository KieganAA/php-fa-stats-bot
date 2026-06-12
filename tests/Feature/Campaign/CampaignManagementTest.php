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

    public function test_update_daily_schedule_propagates_to_children(): void
    {
        [$user, $headers] = $this->authedUser();
        $sub = $this->makeSubscription($user, ['step-1' => ['lp-a', 'lp-b']]);

        $this->patchJson("/api/ext/campaigns/{$sub->id}", [
            'schedule_type' => 'daily',
            'daily_at' => '9:30',
        ], $headers)
            ->assertStatus(200)
            ->assertJsonPath('campaign.schedule_type', 'daily')
            ->assertJsonPath('campaign.daily_at', '09:30'); // normalised

        $child = UserCompareGroup::query()->where('campaign_subscription_id', $sub->id)->firstOrFail();
        $this->assertSame('daily', $child->schedule_type);
        $this->assertSame('09:30', $child->daily_at);
    }

    public function test_daily_schedule_due_logic(): void
    {
        [$user] = $this->authedUser();
        $user->timezone = 'Europe/Moscow'; // UTC+3
        $user->save();
        $sub = $this->makeSubscription($user, ['step-1' => ['lp-a', 'lp-b']]);
        $child = UserCompareGroup::query()->where('campaign_subscription_id', $sub->id)->firstOrFail();
        $child->schedule_type = 'daily';
        $child->daily_at = '19:00'; // 19:00 MSK == 16:00 UTC
        $child->last_notified_at = null;
        $child->save();
        $child = $child->fresh()->load('user');

        // Before today's slot (15:00 UTC = 18:00 MSK) → not due.
        $this->assertFalse($child->isDueForPush(\Carbon\CarbonImmutable::parse('2026-06-12 15:00:00', 'UTC')));
        // After the slot (16:30 UTC = 19:30 MSK), never pushed → due.
        $this->assertTrue($child->isDueForPush(\Carbon\CarbonImmutable::parse('2026-06-12 16:30:00', 'UTC')));

        // Pushed at 16:31 UTC today → no longer due today…
        $child->last_notified_at = \Carbon\CarbonImmutable::parse('2026-06-12 16:31:00', 'UTC');
        $child->save();
        $child = $child->fresh()->load('user');
        $this->assertFalse($child->isDueForPush(\Carbon\CarbonImmutable::parse('2026-06-12 20:00:00', 'UTC')));
        // …but due again after tomorrow's slot.
        $this->assertTrue($child->isDueForPush(\Carbon\CarbonImmutable::parse('2026-06-13 16:30:00', 'UTC')));
    }

    public function test_manual_push_dispatches_one_campaign_digest_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$user, $headers] = $this->authedUser();
        $sub = $this->makeSubscription($user, ['step-1' => ['lp-a', 'lp-b'], 'step-2' => ['lp-c', 'lp-d']]);

        // Orphan one child — it must not count as a section.
        $orphan = $sub->children()->first();
        $orphan->orphaned_at = now();
        $orphan->save();

        $this->postJson("/api/ext/campaigns/{$sub->id}/push", [], $headers)
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dispatched', 1)
            ->assertJsonPath('sections', 1);

        // One digest job for the whole campaign, not one per child.
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\NotifyCampaignJob::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\NotifyCampaignJob::class,
            fn ($job) => $job->campaignSubscriptionId === $sub->id && $job->userId === $user->id,
        );
    }

    public function test_manual_push_rejected_when_campaign_paused(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$user, $headers] = $this->authedUser();
        $sub = $this->makeSubscription($user, ['step-1' => ['lp-a', 'lp-b']]);
        $sub->paused_at = now();
        $sub->save();

        $this->postJson("/api/ext/campaigns/{$sub->id}/push", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
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
