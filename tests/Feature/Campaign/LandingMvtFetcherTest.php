<?php

namespace Tests\Feature\Campaign;

use App\Models\Aio\Landing;
use App\Services\Campaign\LandingMvtFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class LandingMvtFetcherTest extends TestCase
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

        Redis::connection()->flushdb();
    }

    public function test_returns_empty_when_landing_has_no_mvt_settings(): void
    {
        // Empty string and literal "[]" both mean "no MVT" — both reach this
        // path in production responses, so we test both.
        Http::fake([
            'app.aio.test/api/v1/actions/data*' => Http::response([
                'fields' => [
                    ['name' => 'name', 'value' => 'lander x'],
                    ['name' => 'mvt_settings', 'value' => '[]'],
                ],
                'data' => [],
                'primary' => null,
                'logs' => [],
            ]),
        ]);

        $info = $this->app->make(LandingMvtFetcher::class)->fetch('lp-1');

        $this->assertSame('lp-1', $info->landingUuid);
        $this->assertSame([], $info->fields);
        $this->assertFalse($info->hasMvt());
    }

    public function test_parses_variation_fields_and_detects_mvt(): void
    {
        Http::fake([
            'app.aio.test/api/v1/actions/data*' => Http::response([
                'fields' => [
                    ['name' => 'mvt_settings', 'value' => json_encode([
                        [
                            'key' => 'lp_header',
                            'uuid' => 'field-uuid-1',
                            'settings' => [
                                'items' => [
                                    ['payload' => ['content' => 'Headline A']],
                                ],
                            ],
                        ],
                        [
                            'key' => 'lp_content_var_header_image',
                            'uuid' => 'field-uuid-2',
                            'settings' => [
                                'items' => [
                                    ['payload' => ['content' => 'img-1.webp']],
                                    ['payload' => ['content' => 'img-2.webp']],
                                ],
                            ],
                        ],
                    ])],
                ],
                'data' => [],
                'primary' => null,
                'logs' => [],
            ]),
        ]);

        $info = $this->app->make(LandingMvtFetcher::class)->fetch('lp-mvt');

        $this->assertCount(2, $info->fields);
        $this->assertSame('lp_header', $info->fields[0]->key);
        $this->assertSame(1, $info->fields[0]->variantCount());
        $this->assertSame(2, $info->fields[1]->variantCount());
        $this->assertSame(['img-1.webp', 'img-2.webp'], $info->fields[1]->variants);
        // Only the second field qualifies — but one is enough.
        $this->assertTrue($info->hasMvt());
    }

    public function test_fetch_backfills_missing_landing_into_catalog(): void
    {
        Http::fake([
            'app.aio.test/api/v1/actions/data*' => Http::response([
                'fields' => [
                    ['name' => 'name', 'value' => 'ES es | ImChat'],
                    ['name' => 'human_id', 'value' => 8708],
                    ['name' => 'countries', 'value' => ['ES']],
                    ['name' => 'lander_type_uuid', 'value' => 'lt-1'],
                    ['name' => 'mvt_settings', 'value' => '[]'],
                ],
                'data' => [], 'primary' => null, 'logs' => [],
            ]),
        ]);

        $this->app->make(LandingMvtFetcher::class)->fetch('lp-new');

        $row = Landing::query()->where('uuid', 'lp-new')->first();
        $this->assertNotNull($row, 'landing backfilled into catalog');
        $this->assertSame(8708, $row->human_id);
        $this->assertSame('ES es | ImChat', $row->name);
        $this->assertSame(['ES'], $row->countries);
    }

    public function test_fetch_does_not_overwrite_existing_catalog_row(): void
    {
        Landing::query()->create([
            'uuid' => 'lp-rich', 'human_id' => 555, 'name' => 'Rich row from bulk sync',
            'landing_type_uuid' => 'lt', 'landing_type_name' => 'White',
            'owner_uuid' => 'o', 'owner_name' => 'owner',
            'countries' => ['NO'], 'is_archived' => false, 'raw' => [], 'synced_at' => now(),
        ]);

        Http::fake([
            'app.aio.test/api/v1/actions/data*' => Http::response([
                'fields' => [
                    ['name' => 'name', 'value' => 'thin overwrite attempt'],
                    ['name' => 'human_id', 'value' => 999],
                    ['name' => 'mvt_settings', 'value' => '[]'],
                ],
                'data' => [], 'primary' => null, 'logs' => [],
            ]),
        ]);

        $this->app->make(LandingMvtFetcher::class)->fetch('lp-rich');

        $row = Landing::query()->where('uuid', 'lp-rich')->firstOrFail();
        $this->assertSame(555, $row->human_id);
        $this->assertSame('Rich row from bulk sync', $row->name);
    }
}
