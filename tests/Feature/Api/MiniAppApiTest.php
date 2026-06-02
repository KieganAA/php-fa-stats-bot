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

    public function test_rankings_endpoint_returns_structured_rows(): void
    {
        // Structured shape is what the Mini App's sortable table renders from —
        // each cell carries both `raw` (for sorting) and `formatted` (the
        // bot-style display string).
        $this->stubLandingReports();
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $resp = $this->getJson('/api/v1/rankings?kind=geo&period=today', $headers);

        $resp->assertStatus(200);
        $resp->assertJsonStructure([
            'kind',
            'window' => ['from', 'to', 'label', 'timezone'],
            'title',
            'header',
            'html',
            'columns' => [['name', 'label', 'kind']],
            'rows' => [['label', 'metrics']],
        ]);
        $this->assertSame('country', $resp->json('header'));
        $this->assertNotEmpty($resp->json('rows'));

        $firstRow = $resp->json('rows.0');
        $this->assertNotEmpty($firstRow['metrics']);
        // Each metric cell carries the dual {raw, formatted} shape.
        $firstCell = reset($firstRow['metrics']);
        $this->assertArrayHasKey('raw', $firstCell);
        $this->assertArrayHasKey('formatted', $firstCell);
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

    public function test_me_returns_per_context_presets(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $resp = $this->getJson('/api/v1/me', $headers);

        $resp->assertStatus(200);
        $resp->assertJsonStructure([
            'metric_presets' => [
                'stats' => ['names', 'columns', 'customized', 'defaults'],
                'geo' => ['names', 'columns', 'customized', 'defaults'],
                'mvt' => ['names', 'columns', 'customized', 'defaults'],
            ],
            'metric_labels',
            'contexts',
        ]);
        // Untouched user → no customizations.
        $resp->assertJsonPath('metric_presets.stats.customized', false);
        // Geo default trio.
        $this->assertSame(['Q Visits', 'Leads', 'Real Approve'], $resp->json('metric_presets.geo.names'));
    }

    public function test_set_context_metrics_persists_only_for_that_context(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $resp = $this->putJson('/api/v1/me/metrics/geo', [
            'metrics' => ['Q LP1 CTR', 'Leads'],
        ], $headers);

        $resp->assertStatus(200);
        $resp->assertJsonPath('metric_presets.geo.customized', true);
        $this->assertSame(['Q LP1 CTR', 'Leads'], $resp->json('metric_presets.geo.names'));

        // Other contexts untouched.
        $resp->assertJsonPath('metric_presets.stats.customized', false);
        $resp->assertJsonPath('metric_presets.buyers.customized', false);
    }

    public function test_set_context_metrics_null_resets_to_defaults(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        // Custom first.
        $this->putJson('/api/v1/me/metrics/geo', ['metrics' => ['Leads']], $headers)
            ->assertStatus(200)
            ->assertJsonPath('metric_presets.geo.customized', true);

        // Then null → reset.
        $resp = $this->putJson('/api/v1/me/metrics/geo', ['metrics' => null], $headers);

        $resp->assertStatus(200);
        $resp->assertJsonPath('metric_presets.geo.customized', false);
        $this->assertSame(['Q Visits', 'Leads', 'Real Approve'], $resp->json('metric_presets.geo.names'));
    }

    public function test_set_context_metrics_validates_context(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $this->putJson('/api/v1/me/metrics/bogus', ['metrics' => ['Leads']], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error', 'Unknown context: bogus');
    }

    public function test_set_metric_labels_stores_overrides_and_strips_redundants(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $resp = $this->putJson('/api/v1/me/metric-labels', [
            'labels' => [
                'Q Visits' => 'Quals',
                // matches built-in MetricDisplay::label() — should be stripped.
                'Real Approve' => 'CR%',
                '' => 'X',
            ],
        ], $headers);

        $resp->assertStatus(200);
        $this->assertSame(['Q Visits' => 'Quals'], $resp->json('metric_labels'));
        // And it threads into the column spec immediately.
        $cols = $resp->json('metric_presets.stats.columns');
        $first = collect($cols)->firstWhere('name', 'Q Visits');
        $this->assertSame('Quals', $first['label']);
    }

    public function test_set_metric_labels_empty_clears(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];

        $this->putJson('/api/v1/me/metric-labels', [
            'labels' => ['Q Visits' => 'Quals'],
        ], $headers)->assertStatus(200);

        $resp = $this->putJson('/api/v1/me/metric-labels', ['labels' => null], $headers);

        $resp->assertStatus(200);
        $this->assertSame([], (array) $resp->json('metric_labels'));
    }

    public function test_create_group_with_custom_interval(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(33169, 'NG'));
        Landing::query()->create($this->landingRow(205215, 'IT'));

        $resp = $this->postJson('/api/v1/groups', [
            'primitives' => ['33169', '205215'],
            'notify_interval_minutes' => 720,
        ], $headers);

        $resp->assertStatus(201);
        $resp->assertJsonPath('group.notify_interval_minutes', 720);
    }

    public function test_create_group_defaults_interval_to_180(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(33169, 'NG'));

        $resp = $this->postJson('/api/v1/groups', [
            'primitives' => ['33169'],
        ], $headers);

        $resp->assertStatus(201);
        $resp->assertJsonPath('group.notify_interval_minutes', 180);
    }

    public function test_create_group_rejects_out_of_range_interval(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(33169, 'NG'));

        // Too low.
        $this->postJson('/api/v1/groups', [
            'primitives' => ['33169'],
            'notify_interval_minutes' => 5,
        ], $headers)->assertStatus(422);

        // Too high — > 7 days.
        $this->postJson('/api/v1/groups', [
            'primitives' => ['33169'],
            'notify_interval_minutes' => 99999,
        ], $headers)->assertStatus(422);
    }

    public function test_update_group_changes_interval(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(33169, 'NG'));

        $groupId = $this->postJson('/api/v1/groups', [
            'primitives' => ['33169'],
        ], $headers)->json('group.id');

        $resp = $this->patchJson("/api/v1/groups/{$groupId}", [
            'notify_interval_minutes' => 60,
        ], $headers);

        $resp->assertStatus(200);
        $resp->assertJsonPath('group.notify_interval_minutes', 60);
    }

    public function test_landings_search_by_human_id_prefix(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(33169, 'NG'));
        Landing::query()->create($this->landingRow(33170, 'BR'));
        Landing::query()->create($this->landingRow(99999, 'IT'));

        $resp = $this->getJson('/api/v1/landings?q=331', $headers);

        $resp->assertStatus(200);
        $hids = collect($resp->json('landings'))->pluck('human_id')->sort()->values()->all();
        $this->assertSame([33169, 33170], $hids);
    }

    public function test_landings_search_by_country_code(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(33169, 'NO'));
        Landing::query()->create($this->landingRow(33170, 'BR'));

        $resp = $this->getJson('/api/v1/landings?q=NO', $headers);

        $resp->assertStatus(200);
        $hids = collect($resp->json('landings'))->pluck('human_id')->all();
        $this->assertContains(33169, $hids);
        $this->assertNotContains(33170, $hids);
    }

    public function test_landings_search_excludes_archived(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create([
            ...$this->landingRow(33169, 'NG'),
            'is_archived' => true,
        ]);
        Landing::query()->create($this->landingRow(33170, 'NG'));

        $resp = $this->getJson('/api/v1/landings?q=NG', $headers);

        $hids = collect($resp->json('landings'))->pluck('human_id')->all();
        $this->assertNotContains(33169, $hids);
        $this->assertContains(33170, $hids);
    }

    public function test_landings_search_empty_q_returns_recent(): void
    {
        $headers = ['Authorization' => 'tma '.$this->makeInitData(1, 'alice')];
        Landing::query()->create($this->landingRow(1, 'IT'));
        Landing::query()->create($this->landingRow(2, 'BR'));

        $resp = $this->getJson('/api/v1/landings', $headers);

        $resp->assertStatus(200);
        $this->assertCount(2, $resp->json('landings'));
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
            'clicks-uuid' => 'Q Visits',
            'lp-ctr-uuid' => 'Q LP1 CTR',
            'leads-uuid' => 'Leads',
            'ftds-real-uuid' => 'Total FTDs',
            'real-cr-uuid' => 'Real Approve',
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
