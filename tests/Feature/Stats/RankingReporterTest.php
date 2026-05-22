<?php

namespace Tests\Feature\Stats;

use App\Models\Aio\Landing;
use App\Models\Aio\User as AioUser;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Stats\PeriodParser;
use App\Services\Stats\RankingReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RankingReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_geo_ranks_countries_by_leads_descending(): void
    {
        $this->seedTargetMetrics();
        $this->mockRank('location_country_code', [
            ['DK', ['clicks-uuid' => 100, 'leads-uuid' => 5]],
            ['BR', ['clicks-uuid' => 500, 'leads-uuid' => 20]],
            ['IT', ['clicks-uuid' => 300, 'leads-uuid' => 10]],
        ]);

        $window = app(PeriodParser::class)->parse('today');
        $html = app(RankingReporter::class)->report('geo', $window);

        // Sorted by leads: BR 20, IT 10, DK 5
        $brPos = strpos($html, 'BR');
        $itPos = strpos($html, 'IT');
        $dkPos = strpos($html, 'DK');

        $this->assertLessThan($itPos, $brPos);
        $this->assertLessThan($dkPos, $itPos);
        $this->assertStringContainsString('топ стран', $html);
    }

    public function test_buyers_resolves_owner_uuids_against_aio_users(): void
    {
        $this->seedTargetMetrics();
        AioUser::query()->create(['uuid' => 'u-vasya', 'name' => 'vasya', 'is_active' => true, 'raw' => [], 'synced_at' => now()]);
        AioUser::query()->create(['uuid' => 'u-petya', 'name' => 'petya', 'is_active' => true, 'raw' => [], 'synced_at' => now()]);
        $this->mockRank('campaign_owner_uuid', [
            ['u-vasya', ['clicks-uuid' => 100, 'leads-uuid' => 50]],
            ['u-petya', ['clicks-uuid' => 200, 'leads-uuid' => 25]],
            ['u-unknown', ['clicks-uuid' => 50, 'leads-uuid' => 5]],
        ]);

        $window = app(PeriodParser::class)->parse('today');
        $html = app(RankingReporter::class)->report('buyers', $window);

        $this->assertStringContainsString('@vasya', $html);
        $this->assertStringContainsString('@petya', $html);
        // Unknown uuid → "@<8-char prefix>"
        $this->assertStringContainsString('@u-unknow', $html);
    }

    public function test_lp1_resolves_landing_uuids_to_short_lines(): void
    {
        $this->seedTargetMetrics();
        Landing::query()->create([
            'uuid' => 'uuid-1', 'human_id' => 33169, 'name' => 'X',
            'landing_type_uuid' => 'lt', 'landing_type_name' => 'Celeb Preland',
            'owner_uuid' => 'o', 'owner_name' => 'zigi',
            'countries' => ['NO'], 'is_archived' => false, 'raw' => [], 'synced_at' => now(),
        ]);
        $this->mockRank('landing_uuids[1]', [
            ['uuid-1', ['clicks-uuid' => 120, 'leads-uuid' => 8]],
        ]);

        $window = app(PeriodParser::class)->parse('today');
        $html = app(RankingReporter::class)->report('lp1', $window);

        $this->assertStringContainsString('#33169', $html);
        $this->assertStringContainsString('Celeb Preland', $html);
        $this->assertStringContainsString('NO', $html);
        $this->assertStringContainsString('топ LP1', $html);
        $this->assertStringNotContainsString('@zigi', $html);  // creator doesn't belong on LP rows
    }

    public function test_top_n_caps_results(): void
    {
        $this->seedTargetMetrics();
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ["CC{$i}", ['clicks-uuid' => $i, 'leads-uuid' => $i]];
        }
        $this->mockRank('location_country_code', $rows);

        $window = app(PeriodParser::class)->parse('today');
        $html = app(RankingReporter::class)->report('geo', $window, topN: 5);

        $this->assertSame(5, substr_count($html, "\n<code>") - 1, 'header + 5 data rows');
    }

    public function test_unknown_kind_throws(): void
    {
        $window = app(PeriodParser::class)->parse('today');

        $this->expectException(RuntimeException::class);
        app(RankingReporter::class)->report('nonsense', $window);
    }

    public function test_empty_response_returns_no_data_marker(): void
    {
        $this->mockRank('location_country_code', []);

        $window = app(PeriodParser::class)->parse('today');
        $html = app(RankingReporter::class)->report('geo', $window);

        $this->assertStringContainsString('Нет данных', $html);
    }

    /** @param  list<array{0: string, 1: array<string, int|float>}>  $rows */
    private function mockRank(string $expectedKey, array $rows): void
    {
        $pivot = new PivotResponse(
            rows: array_map(
                fn ($r) => ['dimensions' => ['group_0' => $r[0]], 'metrics' => $r[1]],
                $rows,
            ),
            raw: [],
        );
        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('rankByPrimitive')
            ->with($expectedKey, Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($pivot);
        $this->app->instance(LandingReports::class, $reports);
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
