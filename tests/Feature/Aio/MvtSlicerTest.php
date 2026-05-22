<?php

namespace Tests\Feature\Aio;

use App\Models\Aio\Field as FieldModel;
use App\Models\Aio\Metric as MetricModel;
use App\Models\MvtSlice;
use App\Models\TrackedLanding;
use App\Services\Aio\Pivot\MvtSlicer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class MvtSlicerTest extends TestCase
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
        config()->set('aio.target_metrics', [
            'clicks' => 'Q Visits',
            'leads' => 'Leads',
        ]);

        foreach (['aio:limit:rpm', 'aio:limit:concurrent', 'aio:limit:heavy'] as $key) {
            Redis::connection()->del($key);
        }

        Http::preventStrayRequests();
    }

    public function test_captures_three_hour_slice_with_decoded_dimensions_and_projected_metrics(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'Q Visits'));
        MetricModel::create($this->metricRow('m-leads', 'Leads'));

        $headerField = $this->makeField('header-uuid', 'lp_landing_header');
        $gameField = $this->makeField('game-uuid', 'lp_content_var_game');

        $tracked = TrackedLanding::create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::parse('2026-04-25 09:00:00'),
        ]);
        $tracked->mvtFields()->attach([$headerField->id, $gameField->id]);

        $variantA = json_encode(['content_object' => ['type' => 'string', 'value' => 'Header A']]);
        $variantBlue = json_encode(['content_object' => ['type' => 'string', 'value' => 'Blue']]);

        Http::fake([
            'app.aio.test/api/v1/pivot-report/data*' => Http::response([
                $variantA => [
                    'group_0' => $variantA,
                    'group_1' => '',
                    'm-clicks' => 100,
                    'm-leads' => 5,
                    'm-extra' => 999,
                    $variantBlue => [
                        'group_0' => $variantA,
                        'group_1' => $variantBlue,
                        'm-clicks' => 60,
                        'm-leads' => 3,
                    ],
                ],
            ]),
        ]);

        $slicer = $this->app->make(MvtSlicer::class);
        $now = CarbonImmutable::parse('2026-04-25 15:00:00');

        $slice = $slicer->capture($tracked, MvtSlice::KIND_3H, $now);

        $this->assertSame(MvtSlice::KIND_3H, $slice->kind);
        $this->assertTrue($slice->window_start->equalTo(CarbonImmutable::parse('2026-04-25 12:00:00')));
        $this->assertTrue($slice->window_end->equalTo($now));

        $this->assertCount(2, $slice->rows);
        $this->assertSame(['lp_landing_header' => 'Header A', 'lp_content_var_game' => ''], $slice->rows[0]['dimensions']);
        $this->assertSame(['clicks' => 100, 'leads' => 5], $slice->rows[0]['metrics']);
        $this->assertSame(['lp_landing_header' => 'Header A', 'lp_content_var_game' => 'Blue'], $slice->rows[1]['dimensions']);
        $this->assertSame(['clicks' => 60, 'leads' => 3], $slice->rows[1]['metrics']);
    }

    public function test_since_start_uses_tracking_started_at(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'Q Visits'));
        MetricModel::create($this->metricRow('m-leads', 'Leads'));

        $field = $this->makeField('h-uuid', 'lp_header');
        $tracked = TrackedLanding::create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::parse('2026-04-20 09:00:00'),
        ]);
        $tracked->mvtFields()->attach([$field->id]);

        Http::fake([
            'app.aio.test/api/v1/pivot-report/data*' => Http::response([
                'A' => ['group_0' => 'A', 'm-clicks' => 1000, 'm-leads' => 50],
            ]),
        ]);

        $now = CarbonImmutable::parse('2026-04-25 15:00:00');
        $slice = $this->app->make(MvtSlicer::class)->capture($tracked, MvtSlice::KIND_SINCE_START, $now);

        $this->assertTrue($slice->window_start->equalTo(CarbonImmutable::parse('2026-04-20 09:00:00')));
        $this->assertTrue($slice->window_end->equalTo($now));

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['dates'][0] === '2026-04-20 09:00:00'
                && $body['dates'][1] === '2026-04-25 15:00:00';
        });
    }

    public function test_capture_both_creates_two_slices(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'Q Visits'));
        MetricModel::create($this->metricRow('m-leads', 'Leads'));

        $field = $this->makeField('h-uuid', 'lp_header');
        $tracked = TrackedLanding::create([
            'landing_uuid' => 'lp-1',
            'position' => 1,
            'tracking_started_at' => CarbonImmutable::parse('2026-04-25 09:00:00'),
        ]);
        $tracked->mvtFields()->attach([$field->id]);

        Http::fake([
            'app.aio.test/api/v1/pivot-report/data*' => Http::response([
                'A' => ['group_0' => 'A', 'm-clicks' => 10, 'm-leads' => 1],
            ]),
        ]);

        [$threeHour, $sinceStart] = $this->app->make(MvtSlicer::class)->captureBoth(
            $tracked,
            CarbonImmutable::parse('2026-04-25 15:00:00'),
        );

        $this->assertSame(MvtSlice::KIND_3H, $threeHour->kind);
        $this->assertSame(MvtSlice::KIND_SINCE_START, $sinceStart->kind);
        $this->assertSame(2, MvtSlice::count());
    }

    private function metricRow(string $uuid, string $name): array
    {
        return [
            'uuid' => $uuid,
            'name' => $name,
            'format' => 'Number',
            'type' => 'Formula',
            'description' => null,
            'raw' => [],
            'synced_at' => now(),
        ];
    }

    private function makeField(string $uuid, string $slug): FieldModel
    {
        return FieldModel::create([
            'uuid' => $uuid,
            'data_source' => 'Agent Init',
            'group' => 'LP Content Variables',
            'field' => $slug,
            'format' => 'Variant',
            'slug' => $slug,
            'ch_column' => null,
            'description' => '',
            'access_type' => 'By Share',
            'raw' => ['field' => ['pre_processor' => 'String']],
            'synced_at' => now(),
        ]);
    }
}
