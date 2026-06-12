<?php

namespace Tests\Feature\Campaign;

use App\Models\CampaignSubscription;
use App\Models\User;
use App\Services\Auth\ExtensionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * The extension's headline action: POST /api/ext/campaign with a campaign uuid
 * → the bot looks inside, derives splits + MVT landings, wires up the child
 * subscriptions, and returns a summary the popup renders. Re-posting is a
 * resync (idempotent), never a duplicate.
 */
final class ExtensionCampaignSubscribeTest extends TestCase
{
    use RefreshDatabase;

    // A real uuid shape — the endpoint runs the token through
    // CampaignTokenResolver, which only accepts uuids (human_id is a seam).
    private const CAMPAIGN_UUID = '967815b9-6c7a-4bf9-8bbe-005f3d188ab2';

    /** @var array<string, list<string>> step_uuid => landing_uuids */
    private array $steps = [];

    /** @var array<string, true> landing uuids that should report MVT */
    private array $mvtSet = [];

    /** @var list<array<string, mixed>> rows the campaign search returns */
    private array $searchRows = [];

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
                $uuid = $body['uuids'][0] ?? '';

                return Http::response($this->landerEnvelope(isset($this->mvtSet[$uuid])));
            },
            'app.aio.test/api/v1/tables/data*' => fn () => Http::response([
                'Tracker\\Campaigns' => ['response' => ['rows' => $this->searchRows, 'next' => false]],
            ]),
        ]);
    }

    public function test_subscribe_derives_splits_and_mvt_and_returns_summary(): void
    {
        $this->fakeAio(
            steps: [
                'step-1' => ['lp-a', 'lp-b'],   // split
                'step-2' => ['lp-mvt'],          // single, but MVT landing
            ],
            mvtLandings: ['lp-mvt'],
        );
        [$user, $headers] = $this->authedUser();

        $resp = $this->postJson('/api/ext/campaign', ['campaign' => self::uuid()], $headers);

        $resp->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('campaign.splits', 1)
            ->assertJsonPath('campaign.mvts', 1)
            ->assertJsonPath('campaign.human_id', 116400)
            ->assertJsonPath('changed.created', 2);

        $this->assertCount(2, $resp->json('campaign.children'));

        $sub = CampaignSubscription::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(self::uuid(), $sub->campaign_uuid);
        $this->assertCount(2, $sub->children);
    }

    public function test_reposting_same_campaign_is_idempotent_resync(): void
    {
        $this->fakeAio(steps: ['step-1' => ['lp-a', 'lp-b']], mvtLandings: []);
        [$user, $headers] = $this->authedUser();

        $this->postJson('/api/ext/campaign', ['campaign' => self::uuid()], $headers)
            ->assertStatus(200)
            ->assertJsonPath('changed.created', 1);

        // Second click: same structure → no new children.
        $this->postJson('/api/ext/campaign', ['campaign' => self::uuid()], $headers)
            ->assertStatus(200)
            ->assertJsonPath('changed.created', 0)
            ->assertJsonPath('campaign.splits', 1);

        $this->assertSame(1, CampaignSubscription::query()->where('user_id', $user->id)->count());
    }

    public function test_subscribe_by_human_id_resolves_uuid(): void
    {
        $this->fakeAio(steps: ['step-1' => ['lp-a', 'lp-b']], mvtLandings: []);
        // Search for 036469 returns the campaign whose uuid we then subscribe to.
        $this->searchRows = [[
            'uuid' => self::CAMPAIGN_UUID,
            '_identity' => ['uuid' => self::CAMPAIGN_UUID, 'human_id' => '036469', 'name' => 'Catch Campaign'],
        ]];
        [$user, $headers] = $this->authedUser();

        $this->postJson('/api/ext/campaign', ['campaign' => '036469'], $headers)
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('campaign.campaign_uuid', self::CAMPAIGN_UUID)
            ->assertJsonPath('campaign.splits', 1);

        $this->assertSame(
            self::CAMPAIGN_UUID,
            CampaignSubscription::query()->where('user_id', $user->id)->value('campaign_uuid'),
        );
    }

    public function test_subscribe_to_empty_campaign_returns_422(): void
    {
        // Single landing, no MVT → nothing to track.
        $this->fakeAio(steps: ['step-1' => ['lp-a']], mvtLandings: []);
        [$user, $headers] = $this->authedUser();

        $this->postJson('/api/ext/campaign', ['campaign' => self::uuid()], $headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(0, CampaignSubscription::query()->where('user_id', $user->id)->count());
    }

    public function test_unknown_human_id_returns_422(): void
    {
        $this->searchRows = []; // search finds nothing
        [, $headers] = $this->authedUser();

        $this->postJson('/api/ext/campaign', ['campaign' => '999999'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_requires_bearer_auth(): void
    {
        $this->postJson('/api/ext/campaign', ['campaign' => self::uuid()])
            ->assertStatus(401);
    }

    // ===== helpers =====

    private static function uuid(): string
    {
        return self::CAMPAIGN_UUID;
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function authedUser(): array
    {
        $user = User::factory()->telegram('1', 'alice')->create();
        $token = app(ExtensionTokenService::class)->rotate($user);

        return [$user, ['Authorization' => "Bearer {$token}"]];
    }

    /**
     * @param  array<string, list<string>>  $steps
     * @param  list<string>  $mvtLandings
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
                $items[] = ['payload' => [
                    'type' => 'Landing',
                    'uuid' => 'item-'.$lu,
                    'content' => $lu,
                    'isActive' => true,
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
            'data' => [],
            'primary' => null,
            'logs' => [],
        ];
    }

    private function landerEnvelope(bool $isMvt): array
    {
        $mvtSettings = $isMvt
            ? json_encode([[
                'key' => 'lp_header',
                'uuid' => 'field-1',
                'settings' => ['items' => [
                    ['payload' => ['content' => 'A']],
                    ['payload' => ['content' => 'B']],
                ]],
            ]])
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
