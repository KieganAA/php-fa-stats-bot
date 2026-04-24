<?php

namespace Tests\Feature\Aio;

use App\Models\Aio\Field as FieldModel;
use App\Models\Aio\Metric as MetricModel;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Aio\Pivot\MetricResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class LandingReportsTest extends TestCase
{
    use RefreshDatabase;

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

        $conn = Redis::connection();
        foreach (['aio:limit:rpm', 'aio:limit:concurrent', 'aio:limit:heavy'] as $key) {
            $conn->del($key);
        }

        Http::preventStrayRequests();
    }

    public function test_landing_stats_sends_filter_and_grouper_on_positional_key(): void
    {
        Http::fake([
            'app.aio.test/api/v1/pivot-report/data*' => Http::response([
                'lp-1' => [
                    'group_0' => 'lp-1',
                    'uuid-m1' => 100,
                ],
            ]),
        ]);

        $reports = $this->app->make(LandingReports::class);

        $pivot = $reports->landingStats(
            landingUuid: 'lp-1',
            position: 1,
            from: '2026-04-01 00:00:00',
            to: '2026-04-07 23:59:59',
            timezone: 'Europe/Berlin',
        );

        $this->assertCount(1, $pivot->rows);
        $this->assertSame(['group_0' => 'lp-1'], $pivot->rows[0]['dimensions']);
        $this->assertSame(['uuid-m1' => 100], $pivot->rows[0]['metrics']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['definitions'][0]['key'] ?? null) === 'landing_uuids[1]'
                && ($body['conditions'][0]['key'] ?? null) === 'landing_uuids[1]'
                && ($body['conditions'][0]['values'] ?? null) === ['lp-1']
                && ($body['dates'][2] ?? null) === 'Europe/Berlin';
        });
    }

    public function test_compare_landings_passes_all_uuids_in_filter(): void
    {
        Http::fake([
            'app.aio.test/api/v1/pivot-report/data*' => Http::response([
                'lp-a' => [
                    'group_0' => 'lp-a',
                    'uuid-m1' => 50,
                ],
                'lp-b' => [
                    'group_0' => 'lp-b',
                    'uuid-m1' => 70,
                ],
            ]),
        ]);

        $pivot = $this->app->make(LandingReports::class)->compareLandings(
            landingUuids: ['lp-a', 'lp-b'],
            position: 2,
            from: '2026-04-01 00:00:00',
            to: '2026-04-07 23:59:59',
        );

        $this->assertCount(2, $pivot->rows);
        $this->assertSame(['group_0' => 'lp-a'], $pivot->rows[0]['dimensions']);
        $this->assertSame(['group_0' => 'lp-b'], $pivot->rows[1]['dimensions']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['conditions'][0]['key'] ?? null) === 'landing_uuids[2]'
                && ($body['conditions'][0]['values'] ?? null) === ['lp-a', 'lp-b']
                && ($body['definitions'][0]['key'] ?? null) === 'landing_uuids[2]';
        });
    }

    public function test_mvt_breakdown_uses_field_clickhouse_keys(): void
    {
        Http::fake([
            'app.aio.test/api/v1/pivot-report/data*' => Http::response([
                'headerA' => [
                    'group_0' => 'headerA',
                    'group_1' => '',
                    'uuid-m1' => 30,
                    'gameX' => [
                        'group_0' => 'headerA',
                        'group_1' => 'gameX',
                        'uuid-m1' => 10,
                    ],
                    'gameY' => [
                        'group_0' => 'headerA',
                        'group_1' => 'gameY',
                        'uuid-m1' => 20,
                    ],
                ],
            ]),
        ]);

        $headerField = FieldModel::create([
            'uuid' => 'header-uuid',
            'data_source' => 'Agent Init',
            'group' => 'LP Content Variables',
            'field' => 'LP Content Var Header',
            'format' => 'Variant',
            'slug' => 'lp_header',
            'ch_column' => null,
            'description' => '',
            'access_type' => 'By Share',
            'raw' => ['field' => ['pre_processor' => 'String']],
            'synced_at' => now(),
        ]);
        $gameField = FieldModel::create([
            'uuid' => 'game-uuid',
            'data_source' => 'Agent Init',
            'group' => 'LP Content Variables',
            'field' => 'LP Content Var Game',
            'format' => 'Variant',
            'slug' => 'lp_content_var_game',
            'ch_column' => null,
            'description' => '',
            'access_type' => 'By Share',
            'raw' => ['field' => ['pre_processor' => 'String']],
            'synced_at' => now(),
        ]);

        $pivot = $this->app->make(LandingReports::class)->mvtBreakdown(
            landingUuid: 'lp-1',
            position: 1,
            mvtFields: [$headerField, $gameField],
            from: '2026-04-01 00:00:00',
            to: '2026-04-01 03:00:00',
        );

        $this->assertCount(3, $pivot->rows);
        $this->assertSame(
            ['group_0' => 'headerA', 'group_1' => ''],
            $pivot->rows[0]['dimensions'],
        );
        $this->assertSame(
            ['group_0' => 'headerA', 'group_1' => 'gameX'],
            $pivot->rows[1]['dimensions'],
        );
        $this->assertSame(['uuid-m1' => 10], $pivot->rows[1]['metrics']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['conditions'][0]['key'] ?? null) === 'landing_uuids[1]'
                && ($body['definitions'][0]['key'] ?? null) === 'string_fields[header-uuid]'
                && ($body['definitions'][1]['key'] ?? null) === 'string_fields[game-uuid]';
        });
    }

    public function test_metric_resolver_maps_raw_uuid_keys_to_names(): void
    {
        MetricModel::create([
            'uuid' => 'm-clicks',
            'name' => 'LP1 Clicks',
            'format' => 'Number',
            'type' => 'Formula',
            'description' => null,
            'raw' => [],
            'synced_at' => now(),
        ]);

        $resolver = $this->app->make(MetricResolver::class);

        $resolved = $resolver->resolveMetrics([
            'm-clicks' => 123,
            'unknown-uuid' => 99,
        ]);

        $this->assertSame(123, $resolved['LP1 Clicks']);
        $this->assertSame(99, $resolved['unknown-uuid']);
        $this->assertSame('LP1 Clicks', $resolver->nameFor('m-clicks'));
        $this->assertSame('m-clicks', $resolver->uuidFor('lp1 clicks'));
    }
}
