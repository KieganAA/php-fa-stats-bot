<?php

namespace Tests\Feature\Aio;

use App\Models\Aio\Metric as MetricModel;
use App\Services\Aio\Pivot\MetricResolver;
use App\Services\Aio\Pivot\TargetMetricSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TargetMetricSetTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_uuids_onto_aio_names(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'Q Visits'));
        MetricModel::create($this->metricRow('m-leads', 'Leads'));

        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['Q Visits', 'Leads'],
        );

        $projected = $set->project([
            'm-clicks' => 100,
            'm-leads' => 7,
            'm-other' => 999,
        ]);

        $this->assertSame(['Q Visits' => 100, 'Leads' => 7], $projected);
    }

    public function test_project_emits_null_for_missing_uuid(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'Q Visits'));

        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['Q Visits'],
        );

        $this->assertSame(['Q Visits' => null], $set->project([]));
    }

    public function test_uuids_for_throws_on_unknown_metric_name(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'Q Visits'));

        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['Q Visits', 'Leads'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Leads');
        $set->uuidsFor();
    }

    public function test_name_resolution_is_case_insensitive(): void
    {
        MetricModel::create($this->metricRow('m-cr', 'Real Approve'));

        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['real approve'],
        );

        $this->assertSame(['real approve' => 'm-cr'], $set->uuidsFor());
    }

    public function test_project_with_override_uses_passed_names(): void
    {
        MetricModel::create($this->metricRow('m-x', 'Revenue $'));

        // Constructor list defines the default — but project() takes a per-call
        // override that wins. That's the entry point per-user prefs use.
        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['Q Visits'],
        );

        $this->assertSame(
            ['Revenue $' => 42],
            $set->project(['m-x' => 42], ['Revenue $']),
        );
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
}
