<?php

namespace Tests\Feature\Stats;

use App\Models\Aio\Field;
use App\Models\Aio\Landing;
use App\Services\Aio\Dto\PivotResponse;
use App\Services\Aio\Pivot\LandingReports;
use App\Services\Stats\MvtReporter;
use App\Services\Stats\PeriodParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MvtReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_active_slugs_and_decodes_content_object_values(): void
    {
        $this->seedTargetMetrics();
        $this->seedField('lp_header', 'hdr-uuid');
        $this->seedField('lp_content_var_subheading', 'sub-uuid');
        $landing = $this->seedLanding(33169);

        $this->mockMvt([
            // Row 1: real variant on lp_header, empty on subheading.
            [
                'dimensions' => [
                    'group_0' => '{"content_object":{"type":"string","value":"Headline A"}}',
                    'group_1' => '',
                ],
                'metrics' => ['clicks-uuid' => 120, 'leads-uuid' => 12],
            ],
            // Row 2: another variant on lp_header.
            [
                'dimensions' => [
                    'group_0' => '{"content_object":{"type":"string","value":"Headline B"}}',
                    'group_1' => '',
                ],
                'metrics' => ['clicks-uuid' => 95, 'leads-uuid' => 18],
            ],
            // Row 3: all empty — should be dropped.
            [
                'dimensions' => ['group_0' => '', 'group_1' => ''],
                'metrics' => ['clicks-uuid' => 1, 'leads-uuid' => 0],
            ],
        ]);

        $window = app(PeriodParser::class)->parse('today');
        $report = app(MvtReporter::class)->report($landing, $window);

        $this->assertSame($landing->id, $report['landing']->id);
        $this->assertSame(2, count($report['rows']));
        $this->assertSame(['lp_header'], $report['active_slugs']);
        $this->assertSame('Headline A', $report['rows'][0]['variants']['lp_header']);
        $this->assertSame('Headline B', $report['rows'][1]['variants']['lp_header']);
    }

    public function test_throws_when_no_fields_synced(): void
    {
        $landing = $this->seedLanding(33169);
        $window = app(PeriodParser::class)->parse('today');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('aio:sync:fields');
        app(MvtReporter::class)->report($landing, $window);
    }

    public function test_empty_response_returns_zero_rows(): void
    {
        $this->seedTargetMetrics();
        $this->seedField('lp_header', 'hdr-uuid');
        $landing = $this->seedLanding(33169);
        $this->mockMvt([]);

        $report = app(MvtReporter::class)->report($landing, app(PeriodParser::class)->parse('today'));

        $this->assertSame(0, count($report['rows']));
        $this->assertSame([], $report['active_slugs']);
    }

    private function mockMvt(array $rows): void
    {
        $reports = Mockery::mock(LandingReports::class);
        $reports->shouldReceive('mvtBreakdown')
            ->once()
            ->andReturn(new PivotResponse(rows: $rows, raw: []));
        $this->app->instance(LandingReports::class, $reports);
    }

    private function seedLanding(int $humanId): Landing
    {
        return Landing::query()->create([
            'uuid' => 'lp-uuid-'.$humanId,
            'human_id' => $humanId,
            'name' => "L{$humanId}",
            'landing_type_uuid' => 'lt',
            'landing_type_name' => 'Celeb Preland',
            'owner_uuid' => 'o',
            'owner_name' => 'owner',
            'countries' => ['NG'],
            'is_archived' => false,
            'raw' => [],
            'synced_at' => now(),
        ]);
    }

    private function seedField(string $slug, string $uuid): void
    {
        Field::query()->create([
            'uuid' => $uuid,
            'data_source' => 'Agent Init',
            'group' => 'Content',
            'field' => $slug,
            'format' => 'Variant',
            'slug' => $slug,
            'ch_column' => null,
            'description' => null,
            'access_type' => 'Public',
            'raw' => ['field' => ['pre_processor' => 'String']],
            'synced_at' => now(),
        ]);
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
