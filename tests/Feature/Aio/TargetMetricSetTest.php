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

    public function test_resolves_slugs_to_uuids_and_projects_metrics(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'LP1 Clicks'));
        MetricModel::create($this->metricRow('m-leads', 'Leads'));

        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['clicks' => 'LP1 Clicks', 'leads' => 'Leads'],
        );

        $resolved = $set->all();
        $this->assertSame('m-clicks', $resolved['clicks']['uuid']);
        $this->assertSame('m-leads', $resolved['leads']['uuid']);

        $projected = $set->project([
            'm-clicks' => 100,
            'm-leads' => 7,
            'm-other' => 999,
        ]);
        $this->assertSame(['clicks' => 100, 'leads' => 7], $projected);
    }

    public function test_project_emits_null_for_missing_uuid(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'LP1 Clicks'));

        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['clicks' => 'LP1 Clicks'],
        );

        $this->assertSame(['clicks' => null], $set->project([]));
    }

    public function test_throws_when_a_target_metric_is_missing(): void
    {
        MetricModel::create($this->metricRow('m-clicks', 'LP1 Clicks'));

        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['clicks' => 'LP1 Clicks', 'leads' => 'Leads'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('leads');
        $set->all();
    }

    public function test_resolution_is_case_insensitive(): void
    {
        MetricModel::create($this->metricRow('m-cr', 'LP1  CR%'));

        $set = new TargetMetricSet(
            $this->app->make(MetricResolver::class),
            ['real_cr' => 'lp1  cr%'],
        );

        $this->assertSame('m-cr', $set->all()['real_cr']['uuid']);
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
