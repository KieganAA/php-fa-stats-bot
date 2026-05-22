<?php

namespace Tests\Feature\Stats;

use App\Models\Aio\Landing;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Stats\ComparisonReporter;
use App\Services\Stats\PeriodParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ComparisonReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_compares_two_landings_side_by_side_with_delta(): void
    {
        $this->seedTargetMetrics();
        $this->seedLanding(33169, 'NO', 'Celeb Preland', 'zigi');
        $this->seedLanding(205215, 'IT', 'White 2.0', 'Cloak', uuid: 'uuid-205215');

        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('compareByPrimitive')
            ->once()
            ->with('landing_uuids[1]', ['uuid-33169', 'uuid-205215'], Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(new PivotResponse(
                rows: [
                    ['dimensions' => ['group_0' => 'uuid-33169'], 'metrics' => ['clicks-uuid' => 100, 'leads-uuid' => 8]],
                    ['dimensions' => ['group_0' => 'uuid-205215'], 'metrics' => ['clicks-uuid' => 400, 'leads-uuid' => 22]],
                ],
                raw: [],
            ));
        $this->app->instance(LandingReports::class, $reports);

        $window = app(PeriodParser::class)->parse('today');
        $html = app(ComparisonReporter::class)->report(['33169', '205215'], $window);

        $this->assertStringContainsString('#33169', $html);
        $this->assertStringContainsString('#205215', $html);
        $this->assertStringContainsString('Δ%', $html);
        $this->assertStringContainsString('compare', $html);
        // 400 vs 100 = +300%
        $this->assertStringContainsString('+300.0%', $html);
    }

    public function test_compares_two_countries(): void
    {
        $this->seedTargetMetrics();

        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('compareByPrimitive')
            ->once()
            ->with('location_country_code', ['DK', 'BR'], Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn(new PivotResponse(
                rows: [
                    ['dimensions' => ['group_0' => 'DK'], 'metrics' => ['clicks-uuid' => 50]],
                    ['dimensions' => ['group_0' => 'BR'], 'metrics' => ['clicks-uuid' => 75]],
                ],
                raw: [],
            ));
        $this->app->instance(LandingReports::class, $reports);

        $window = app(PeriodParser::class)->parse('week');
        $html = app(ComparisonReporter::class)->report(['DK', 'BR'], $window);

        $this->assertStringContainsString('DK', $html);
        $this->assertStringContainsString('BR', $html);
        // 75 vs 50 = +50%
        $this->assertStringContainsString('+50.0%', $html);
    }

    public function test_mixing_kinds_throws(): void
    {
        $this->seedTargetMetrics();
        $this->seedLanding(33169, 'NO', 'Celeb Preland', 'zigi');

        $window = app(PeriodParser::class)->parse('today');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('одного типа');
        app(ComparisonReporter::class)->report(['33169', 'DK'], $window);
    }

    public function test_single_token_throws(): void
    {
        $window = app(PeriodParser::class)->parse('today');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('минимум 2');
        app(ComparisonReporter::class)->report(['DK'], $window);
    }

    public function test_compares_three_landings_without_delta_column(): void
    {
        $this->seedTargetMetrics();
        $this->seedLanding(1, 'NO', 'Offer', 'a');
        $this->seedLanding(2, 'IT', 'Offer', 'b', uuid: 'uuid-2');
        $this->seedLanding(3, 'GB', 'Offer', 'c', uuid: 'uuid-3');

        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('compareByPrimitive')
            ->once()
            ->andReturn(new PivotResponse(
                rows: [
                    ['dimensions' => ['group_0' => 'uuid-1'], 'metrics' => ['clicks-uuid' => 1]],
                    ['dimensions' => ['group_0' => 'uuid-2'], 'metrics' => ['clicks-uuid' => 2]],
                    ['dimensions' => ['group_0' => 'uuid-3'], 'metrics' => ['clicks-uuid' => 3]],
                ],
                raw: [],
            ));
        $this->app->instance(LandingReports::class, $reports);

        $window = app(PeriodParser::class)->parse('today');
        $html = app(ComparisonReporter::class)->report(['1', '2', '3'], $window);

        $this->assertStringNotContainsString('Δ%', $html);  // 3 columns, no delta
    }

    private function seedLanding(
        int $humanId,
        string $country,
        string $type,
        string $owner,
        ?string $uuid = null,
    ): Landing {
        return Landing::query()->create([
            'uuid' => $uuid ?? ('uuid-'.$humanId),
            'human_id' => $humanId,
            'name' => "L{$humanId}",
            'landing_type_uuid' => 'lt',
            'landing_type_name' => $type,
            'owner_uuid' => 'o',
            'owner_name' => $owner,
            'countries' => [$country],
            'is_archived' => false,
            'raw' => [],
            'synced_at' => now(),
        ]);
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
}
