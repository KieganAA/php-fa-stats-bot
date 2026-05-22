<?php

namespace Tests\Feature\Api;

use App\Models\Aio\Landing;
use App\Models\User;
use App\Models\UserCompareGroup;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Pivot\LandingReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * End-to-end exercise of the Mini App API surface from /api/v1/me to
 * /api/v1/groups, all gated by VerifyTelegramInitData. The bot+pipeline
 * services are mocked at the AIO boundary; everything else runs.
 */
class MiniAppApiTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '7654321:test-bot-token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.token', self::BOT_TOKEN);
        $this->seedTargetMetrics();
    }

    public function test_compare_endpoint_returns_html_for_two_primitives(): void
    {
        $this->stubLandingReports();
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $resp = $this->getJson('/api/v1/compare?primitives=DK,BR&period=today', $headers);

        $resp->assertStatus(200);
        $resp->assertJsonStructure(['html', 'tokens', 'window' => ['from', 'to', 'label']]);
        $this->assertStringContainsString('compare', $resp->json('html'));
    }

    public function test_compare_rejects_single_primitive(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $this->getJson('/api/v1/compare?primitives=DK&period=today', $headers)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Нужно минимум 2 примитива.');
    }

    public function test_rankings_endpoint_renders_geo_html(): void
    {
        $this->stubLandingReports();
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $resp = $this->getJson('/api/v1/rankings?kind=geo&period=today', $headers);

        $resp->assertStatus(200);
        $this->assertSame('geo', $resp->json('kind'));
        $this->assertStringContainsString('топ стран', $resp->json('html'));
    }

    public function test_rankings_validates_kind(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $this->getJson('/api/v1/rankings?kind=bogus&period=today', $headers)
            ->assertStatus(422);
    }

    public function test_groups_full_lifecycle(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(33169, 'NG'));
        Landing::query()->create($this->landingRow(205215, 'IT'));

        // 0. Empty list.
        $this->getJson('/api/v1/groups', $headers)
            ->assertStatus(200)
            ->assertJsonPath('groups', []);

        // 1. Create — 2 landings → compare mode.
        $created = $this->postJson('/api/v1/groups', [
            'primitives' => ['33169', '205215'],
            'name' => 'my-test',
        ], $headers);
        $created->assertStatus(201);
        $created->assertJsonPath('group.name', 'my-test');
        $created->assertJsonPath('group.mode', 'compare');
        $created->assertJsonCount(2, 'group.members');
        $groupId = $created->json('group.id');

        // 2. Pause.
        $this->patchJson("/api/v1/groups/{$groupId}", ['paused' => true], $headers)
            ->assertStatus(200)
            ->assertJsonPath('group.paused', true);

        // 3. List shows it.
        $this->getJson('/api/v1/groups', $headers)
            ->assertStatus(200)
            ->assertJsonCount(1, 'groups')
            ->assertJsonPath('groups.0.paused', true);

        // 4. Delete.
        $this->deleteJson("/api/v1/groups/{$groupId}", [], $headers)
            ->assertStatus(200);
        $this->assertSame(0, UserCompareGroup::query()->count());
    }

    public function test_one_user_cannot_touch_anothers_group(): void
    {
        Landing::query()->create($this->landingRow(1, 'IT'));

        $aliceHeaders = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        $bobHeaders = ['Authorization' => 'tma '.$this->makeInitData(2, 'bob')];

        $groupId = $this->postJson('/api/v1/groups', [
            'primitives' => ['1'],
        ], $aliceHeaders)->json('group.id');

        $this->deleteJson("/api/v1/groups/{$groupId}", [], $bobHeaders)
            ->assertStatus(403);
    }

    public function test_solo_landing_group_gets_mvt_mode(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(33169, 'NG'));

        $resp = $this->postJson('/api/v1/groups', [
            'primitives' => ['33169'],
        ], $headers);

        $resp->assertStatus(201);
        $resp->assertJsonPath('group.mode', 'mvt');
        $resp->assertJsonCount(1, 'group.members');
    }

    public function test_groups_create_rejects_unknown_landing(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $this->postJson('/api/v1/groups', ['primitives' => ['999999']], $headers)
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Лендинг «999999» не найден.']);
    }

    private function stubLandingReports(): void
    {
        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('compareByPrimitive')->andReturn(new PivotResponse(
            rows: [
                ['dimensions' => ['group_0' => 'DK'], 'metrics' => ['clicks-uuid' => 50]],
                ['dimensions' => ['group_0' => 'BR'], 'metrics' => ['clicks-uuid' => 75]],
            ],
            raw: [],
        ));
        $reports->shouldReceive('rankByPrimitive')->andReturn(new PivotResponse(
            rows: [
                ['dimensions' => ['group_0' => 'DK'], 'metrics' => ['clicks-uuid' => 100, 'leads-uuid' => 10]],
                ['dimensions' => ['group_0' => 'IT'], 'metrics' => ['clicks-uuid' => 50, 'leads-uuid' => 8]],
            ],
            raw: [],
        ));
        $this->app->instance(LandingReports::class, $reports);
    }

    private function landingRow(int $humanId, string $country): array
    {
        return [
            'uuid' => 'uuid-'.$humanId,
            'human_id' => $humanId,
            'name' => "L{$humanId}",
            'landing_type_uuid' => 'lt',
            'landing_type_name' => 'White 2.0',
            'owner_uuid' => 'o',
            'owner_name' => 'owner',
            'countries' => [$country],
            'is_archived' => false,
            'raw' => [],
            'synced_at' => now(),
        ];
    }

    private function seedTargetMetrics(): void
    {
        foreach ([
            'clicks-uuid' => 'LP1 Clicks',
            'lp-ctr-uuid' => 'Q LP1 CTR',
            'leads-uuid' => 'Leads',
            'ftds-real-uuid' => 'FTDs',
            'real-cr-uuid' => 'LP1  CR%',
            'interest-rate-uuid' => 'LP1 Interest Rate',
            'scrolling-uuid' => 'Q LP1 Scroll Avg',
        ] as $uuid => $name) {
            \App\Models\Aio\Metric::query()->updateOrCreate(
                ['uuid' => $uuid],
                ['name' => $name, 'raw' => [], 'synced_at' => now()],
            );
        }
    }

    private function makeInitData(int $userId, string $username): string
    {
        $fields = [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => $userId, 'username' => $username]),
        ];
        ksort($fields);
        $check = [];
        foreach ($fields as $k => $v) {
            $check[] = "{$k}={$v}";
        }
        $secret = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $hash = hash_hmac('sha256', implode("\n", $check), $secret);

        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = urlencode($k).'='.urlencode($v);
        }
        $parts[] = 'hash='.$hash;

        return implode('&', $parts);
    }
}
