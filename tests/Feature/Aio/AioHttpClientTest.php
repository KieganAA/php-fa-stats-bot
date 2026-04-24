<?php

namespace Tests\Feature\Aio;

use App\Services\Aio\AioClient;
use App\Services\Aio\Exceptions\UpstreamException;
use App\Services\Aio\Http\AioHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AioHttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('aio.base_url', 'https://app.aio.test');
        config()->set('aio.token', 'test-token');
        config()->set('aio.tenant_id', 'tenant-1');
        config()->set('aio.cache.enabled', false);

        Http::preventStrayRequests();
    }

    public function test_sends_token_query_param_and_tenant_header(): void
    {
        Http::fake([
            'app.aio.test/api/v1/data/landings*' => Http::response([
                'rows' => [['uuid' => 'u1', 'name' => 'LP-1']],
                'next' => false,
            ]),
        ]);

        $landings = $this->app->make(AioClient::class)->listLandings(10);

        $this->assertCount(1, $landings);
        $this->assertSame('u1', $landings[0]->uuid);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'token=test-token')
                && $request->hasHeader('X-Tenant-Id', 'tenant-1');
        });
    }

    public function test_follows_pagination_via_next_flag(): void
    {
        Http::fake([
            'app.aio.test/api/v1/data/users*' => Http::sequence()
                ->push(['rows' => [['uuid' => 'a', 'name' => 'A']], 'next' => true])
                ->push(['rows' => [['uuid' => 'b', 'name' => 'B']], 'next' => false]),
        ]);

        $users = $this->app->make(AioClient::class)->listUsers(1);

        $this->assertCount(2, $users);
        $this->assertSame(['a', 'b'], array_map(fn ($u) => $u->uuid, $users));
    }

    public function test_throws_upstream_exception_on_5xx(): void
    {
        Http::fake([
            'app.aio.test/*' => Http::response('boom', 503),
        ]);

        $this->expectException(UpstreamException::class);

        $this->app->make(AioHttpClient::class)->get('api/v1/data/landings');
    }

    public function test_pivot_report_sends_wrapped_request_body(): void
    {
        Http::fake([
            'app.aio.test/api/v1/pivot-report/data*' => Http::response([
                '{"group_1":"DK"}' => ['placeholders' => ['m1' => 42]],
            ]),
        ]);

        $pivot = $this->app->make(AioClient::class)->pivotReport([
            'main' => 'LandingActivations',
            'groupers' => ['group_1' => 'location_country_code'],
        ], heavy: false);

        $this->assertCount(1, $pivot->rows);
        $this->assertSame(['group_1' => 'DK'], $pivot->rows[0]['dimensions']);

        Http::assertSent(function ($request) {
            $decoded = json_decode($request->body(), true);

            return isset($decoded['request']['main'])
                && $decoded['request']['main'] === 'LandingActivations';
        });
    }
}
