<?php

namespace Tests\Feature\Campaign;

use App\Models\CampaignSubscription;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Auth\AppContext;
use App\Services\Campaign\CampaignSubscriptionService;
use App\Support\CampaignTelegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * The 🗑/💤 buttons on an orphan card. Covers the security-critical bit — only
 * the owner can act — plus the delete vs keep effect on the child row.
 */
final class OrphanDecisionCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPAIGN_UUID = 'cmp-orphan-1';

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

    public function test_owner_can_delete_orphan_via_button(): void
    {
        [$user, $orphan] = $this->makeOrphan();
        $bot = Nutgram::fake($this->callbackUpdate('corph:del:'.$orphan->id));
        app(AppContext::class)->setUser($user);

        CampaignTelegram::handleOrphanDecision($bot, 'del', $orphan->id);

        $this->assertNull(UserCompareGroup::query()->find($orphan->id), 'orphan should be deleted');
    }

    public function test_keep_leaves_orphan_in_place(): void
    {
        [$user, $orphan] = $this->makeOrphan();
        $bot = Nutgram::fake($this->callbackUpdate('corph:keep:'.$orphan->id));
        app(AppContext::class)->setUser($user);

        CampaignTelegram::handleOrphanDecision($bot, 'keep', $orphan->id);

        $kept = UserCompareGroup::query()->find($orphan->id);
        $this->assertNotNull($kept, 'kept orphan stays in DB');
        $this->assertNotNull($kept->orphaned_at, 'still flagged orphaned');
    }

    public function test_non_owner_cannot_delete_orphan(): void
    {
        [, $orphan] = $this->makeOrphan();
        $intruder = User::query()->create([
            'telegram_user_id' => 9999,
            'telegram_username' => 'intruder',
            'timezone' => 'UTC',
        ]);
        $bot = Nutgram::fake($this->callbackUpdate('corph:del:'.$orphan->id));
        app(AppContext::class)->setUser($intruder);

        CampaignTelegram::handleOrphanDecision($bot, 'del', $orphan->id);

        $this->assertNotNull(
            UserCompareGroup::query()->find($orphan->id),
            'another user must not be able to delete someone else\'s orphan',
        );
    }

    // ===== helpers =====

    /** @return array{0: User, 1: UserCompareGroup} */
    private function makeOrphan(): array
    {
        $user = User::query()->create([
            'telegram_user_id' => 1234,
            'telegram_username' => 'owner',
            'timezone' => 'Europe/Moscow',
        ]);
        $service = app(CampaignSubscriptionService::class);

        // Split with two landings → one compare child.
        $this->steps = ['step-1' => ['lp-a', 'lp-b']];
        $service->create($user, self::CAMPAIGN_UUID);
        $sub = CampaignSubscription::query()->where('campaign_uuid', self::CAMPAIGN_UUID)->firstOrFail();

        // Collapse the split → the child orphans.
        $this->steps = ['step-1' => ['lp-a']];
        $result = $service->resync($sub);

        return [$user, $result->orphaned[0]];
    }

    private function callbackUpdate(string $data): array
    {
        return [
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => 1234, 'is_bot' => false, 'first_name' => 'Owner'],
                'chat_instance' => 'ci-1',
                'data' => $data,
                'message' => [
                    'message_id' => 42,
                    'date' => 0,
                    'chat' => ['id' => 1234, 'type' => 'private'],
                ],
            ],
        ];
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
                ['name' => 'name', 'value' => 'Orphan Campaign'],
                ['name' => 'human_id', 'value' => 116400],
                ['name' => 'countries', 'value' => ['CA']],
                ['name' => 'settings', 'value' => json_encode($settings)],
            ],
            'data' => [],
            'primary' => null,
            'logs' => [],
        ];
    }

    private function landerEnvelope(): array
    {
        return [
            'fields' => [
                ['name' => 'name', 'value' => 'lander'],
                ['name' => 'mvt_settings', 'value' => '[]'],
            ],
            'data' => [],
            'primary' => null,
            'logs' => [],
        ];
    }
}
