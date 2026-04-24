<?php

namespace Tests\Feature\Aio;

use App\Models\Aio\Field as FieldModel;
use App\Models\Aio\Landing as LandingModel;
use App\Models\Aio\LandingType as LandingTypeModel;
use App\Models\Aio\User as UserModel;
use App\Services\Aio\Sync\FieldSyncer;
use App\Services\Aio\Sync\LandingSyncer;
use App\Services\Aio\Sync\LandingTypeSyncer;
use App\Services\Aio\Sync\UserSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SyncersTest extends TestCase
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

    public function test_landing_syncer_upserts_rows_from_paginated_list(): void
    {
        Http::fake([
            'app.aio.test/api/v1/data/landings*' => Http::sequence()
                ->push([
                    'rows' => [
                        $this->landingRow('lp-1', 'LP One', typeUuid: 't-1', typeName: 'Promo'),
                        $this->landingRow('lp-2', 'LP Two', typeUuid: 't-1', typeName: 'Promo'),
                    ],
                    'next' => true,
                ])
                ->push([
                    'rows' => [
                        $this->landingRow('lp-3', 'LP Three', typeUuid: null, typeName: null),
                    ],
                    'next' => false,
                ]),
        ]);

        $report = $this->app->make(LandingSyncer::class)->sync(chunkSize: 2);

        $this->assertSame(3, $report->fetched);
        $this->assertSame(3, $report->upserted);
        $this->assertSame(3, LandingModel::count());

        $lp1 = LandingModel::where('uuid', 'lp-1')->first();
        $this->assertSame('LP One', $lp1->name);
        $this->assertSame('t-1', $lp1->landing_type_uuid);
        $this->assertSame('Promo', $lp1->landing_type_name);
        $this->assertFalse((bool) $lp1->is_archived);
    }

    public function test_landing_syncer_updates_existing_rows_on_rerun(): void
    {
        Http::fake([
            'app.aio.test/api/v1/data/landings*' => Http::sequence()
                ->push(['rows' => [$this->landingRow('lp-1', 'Original')], 'next' => false])
                ->push(['rows' => [$this->landingRow('lp-1', 'Renamed')], 'next' => false]),
        ]);

        $this->app->make(LandingSyncer::class)->sync();
        $this->app->make(LandingSyncer::class)->sync();

        $this->assertSame(1, LandingModel::count());
        $this->assertSame('Renamed', LandingModel::where('uuid', 'lp-1')->value('name'));
    }

    public function test_user_syncer_persists_active_flag_and_timestamp(): void
    {
        Http::fake([
            'app.aio.test/api/v1/data/users*' => Http::response([
                'rows' => [
                    ['uuid' => 'u-1', 'name' => 'Alex', 'is_active' => true, 'created_at' => ['timestamp' => 1700000000]],
                    ['uuid' => 'u-2', 'name' => 'Julia', 'is_active' => false, 'created_at' => ['timestamp' => 1710000000]],
                ],
                'next' => false,
            ]),
        ]);

        $report = $this->app->make(UserSyncer::class)->sync();

        $this->assertSame(2, $report->fetched);
        $this->assertSame(2, UserModel::count());
        $this->assertTrue((bool) UserModel::where('uuid', 'u-1')->value('is_active'));
        $this->assertFalse((bool) UserModel::where('uuid', 'u-2')->value('is_active'));
    }

    public function test_landing_type_syncer_persists_rows(): void
    {
        Http::fake([
            'app.aio.test/api/v1/data/landing-types*' => Http::response([
                'rows' => [
                    ['uuid' => 'lt-1', 'name' => 'Promo'],
                    ['uuid' => 'lt-2', 'name' => 'Quiz'],
                ],
                'next' => false,
            ]),
        ]);

        $report = $this->app->make(LandingTypeSyncer::class)->sync();

        $this->assertSame(2, $report->fetched);
        $this->assertSame(2, LandingTypeModel::count());
        $this->assertSame('Quiz', LandingTypeModel::where('uuid', 'lt-2')->value('name'));
    }

    public function test_field_syncer_walks_tables_data_wrapped_response(): void
    {
        Http::fake([
            'app.aio.test/api/v1/tables/data*' => Http::sequence()
                ->push($this->fieldsTableResponse(
                    rows: [
                        $this->fieldRow('f-1', 'lp_header', 'Header'),
                        $this->fieldRow('f-2', 'lp_content_var_game', 'Game var'),
                    ],
                    next: true,
                ))
                ->push($this->fieldsTableResponse(
                    rows: [$this->fieldRow('f-3', 'lp_cta', 'CTA')],
                    next: false,
                )),
        ]);

        $report = $this->app->make(FieldSyncer::class)->sync();

        $this->assertSame(3, $report->fetched);
        $this->assertSame(3, FieldModel::count());
        $this->assertSame('lp_header', FieldModel::where('uuid', 'f-1')->value('slug'));
        $this->assertSame('CTA', FieldModel::where('uuid', 'f-3')->value('field'));
    }

    public function test_field_syncer_sends_required_table_request_defaults(): void
    {
        Http::fake([
            'app.aio.test/api/v1/tables/data*' => Http::response(
                $this->fieldsTableResponse(rows: [], next: false),
            ),
        ]);

        $this->app->make(FieldSyncer::class)->sync();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $fields = $body['request']['Settings\\Fields'] ?? [];

            return ($fields['table'] ?? null) === 'Settings\\Fields'
                && ($fields['sort_direction'] ?? null) === 'asc'
                && ($fields['hide_trash'] ?? null) === true
                && array_key_exists('filters', $fields)
                && array_key_exists('dates', $fields);
        });
    }

    public function test_http_client_retries_on_throttle_body_and_recovers(): void
    {
        Http::fake([
            'app.aio.test/api/v1/data/landing-types*' => Http::sequence()
                ->push('{"message":"Too Many Attempts","exception":"ThrottleRequestsException"}', 500)
                ->push([
                    'rows' => [['uuid' => 'lt-1', 'name' => 'Promo']],
                    'next' => false,
                ]),
        ]);

        config()->set('aio.rate_limits.per_minute', 1000);

        $report = $this->app->make(LandingTypeSyncer::class)->sync();

        $this->assertSame(1, $report->fetched);
        $this->assertSame(1, LandingTypeModel::count());
    }

    private function landingRow(
        string $uuid,
        string $name,
        ?string $typeUuid = 't-1',
        ?string $typeName = 'Promo',
    ): array {
        return [
            'uuid' => $uuid,
            'human_id' => 42,
            'name' => $name,
            'landing_type' => $typeUuid !== null ? ['uuid' => $typeUuid, 'name' => $typeName] : null,
            'owner' => ['uuid' => 'o-1', 'name' => 'Owner'],
            'countries' => ['DK', 'SE'],
            'is_archived' => false,
            'created_at' => ['timestamp' => 1700000000],
        ];
    }

    private function fieldRow(string $uuid, string $slug, string $name): array
    {
        return [
            'uuid' => $uuid,
            'slug' => $slug,
            'field' => ['slug' => $slug, 'name' => $name],
            'data_source' => 'landings',
            'group' => 'content',
            'format' => 'string',
            'ch_column' => 'column_'.$slug,
            'description' => null,
            'access_type' => 'public',
        ];
    }

    private function fieldsTableResponse(array $rows, bool $next): array
    {
        return [
            'Settings\\Fields' => [
                'logs' => [],
                'request' => [],
                'response' => [
                    'rows' => $rows,
                    'next' => $next,
                    'previous' => false,
                    'columns' => [],
                ],
            ],
        ];
    }
}
