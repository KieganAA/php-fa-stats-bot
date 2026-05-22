<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\ToolCatalog;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Pivot\LandingReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ToolCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_definitions_lists_expected_tools(): void
    {
        $catalog = $this->app->make(ToolCatalog::class);

        $names = array_map(fn ($d) => $d['name'], $catalog->definitions());

        $this->assertSame(['stats'], $names);
        foreach ($catalog->definitions() as $def) {
            $this->assertArrayHasKey('description', $def);
            $this->assertSame('object', $def['input_schema']['type']);
        }
    }

    public function test_dispatch_unknown_tool_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown tool/');

        $this->app->make(ToolCatalog::class)->dispatch('not_a_tool', []);
    }

    public function test_stats_resolves_primitive_calls_aio_and_renders(): void
    {
        $this->seedTargetMetrics();
        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('statsByPrimitive')
            ->once()
            ->with(Mockery::on(fn ($key) => $key === 'location_country_code'), 'DK', Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(new PivotResponse(
                rows: [['dimensions' => ['location_country_code' => 'DK'], 'metrics' => ['clicks-uuid' => 100]]],
                raw: [],
            ));
        $this->app->instance(LandingReports::class, $reports);

        $html = $this->app->make(ToolCatalog::class)->dispatch('stats', ['primitive' => 'DK']);

        $this->assertStringContainsString('DK', $html);
        $this->assertStringContainsString('100', $html);
    }

    public function test_stats_rejects_unknown_primitive(): void
    {
        $this->expectException(RuntimeException::class);

        $this->app->make(ToolCatalog::class)->dispatch('stats', ['primitive' => 'not-a-country-code']);
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
}
